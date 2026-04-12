<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('vio')]
class vio extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/vio')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-vio', SOURCE_PATH . '/php-src/ext/vio');

        // Stub out vendored implementations that conflict with php-glfw
        $emptyStubs = [
            'vendor/glad/src/glad.c',
            'vendor/stb/stb_image_impl.c',
            'vendor/stb/stb_truetype_impl.c',
            'vendor/stb/stb_image_write_impl.c',
            'vendor/miniaudio/miniaudio_impl.c',
        ];
        foreach ($emptyStubs as $file) {
            $path = SOURCE_PATH . '/php-src/ext/vio/' . $file;
            if (file_exists($path)) {
                file_put_contents($path, "/* stubbed — provided by php-glfw */\n");
            }
        }

        // Fix Makefile.frag — replace -DHAVE_CONFIG_H with -DHAVE_VULKAN=1
        // since config.h doesn't exist at the expected path in static builds.
        $frag = SOURCE_PATH . '/php-src/ext/vio/Makefile.frag';
        if (file_exists($frag)) {
            $content = file_get_contents($frag);
            $content = str_replace('-DHAVE_CONFIG_H', '-DHAVE_VULKAN=1 -DHAVE_METAL=1', $content);
            file_put_contents($frag, $content);
        }

        // Replace OpenGL backend with no-op stubs (core VIO code references these symbols)
        $openglStub = SOURCE_PATH . '/php-src/ext/vio/src/backends/opengl/vio_opengl.c';
        if (file_exists($openglStub)) {
            file_put_contents($openglStub, <<<'C'
                /* OpenGL backend stub for static Vulkan/Metal builds */
                #include "vio_opengl.h"
                vio_opengl_state vio_gl = {0};
                void vio_backend_opengl_register(void) { /* no-op */ }
                void vio_opengl_setup_context(void) { /* no-op */ }
                unsigned int vio_opengl_compile_shader_source(const char *v, const char *f) { (void)v; (void)f; return 0; }

                C);
        }

        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        // Fix library names in configure if needed
        FileSystem::replaceFileStr(SOURCE_PATH . '/php-src/configure', '-lglfw ', '-lglfw3 ');

        // Fix strdup declaration in vio_shader_compiler.c — C23 requires explicit declarations.
        // strdup is POSIX, not standard C. Prepend feature macros and explicit declaration.
        $shaderCompiler = SOURCE_PATH . '/php-src/ext/vio/src/vio_shader_compiler.c';
        if (file_exists($shaderCompiler)) {
            $content = file_get_contents($shaderCompiler);
            if (!str_contains($content, 'STRDUP_DECLARED')) {
                $preamble = <<<'C'
                    /* static-php-cli: ensure strdup is available in strict C standard modes */
                    #ifndef _GNU_SOURCE
                    #define _GNU_SOURCE
                    #endif
                    #include <string.h>
                    #ifndef STRDUP_DECLARED
                    #define STRDUP_DECLARED
                    extern char *strdup(const char *);
                    #endif

                    C;
                file_put_contents($shaderCompiler, $preamble . $content);
            }
        }

        $extraLibs = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extraLibs[] = '-lc++';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extraLibs[] = '-lstdc++';
            // X11 backend dependencies (only .so available on Alpine, no static .a)
            putenv('SPC_NO_STATIC_LINK=1');
            $extraLibs = array_merge($extraLibs, [
                '-lX11', '-lXrandr', '-lXinerama', '-lXcursor', '-lXi',
                '-lXext', '-lXfixes', '-lXrender', '-lxcb', '-lXau', '-lXdmcp',
            ]);
        }

        $existing = getenv('SPC_EXTRA_LIBS') ?: '';
        foreach ($extraLibs as $lib) {
            if (!str_contains($existing, $lib)) {
                $existing .= ' ' . $lib;
            }
        }
        putenv('SPC_EXTRA_LIBS=' . trim($existing));

        return true;
    }

    public function patchBeforeMake(): bool
    {
        $buildDir = SOURCE_PATH . '/php-src';
        $vulkanInc = BUILD_ROOT_PATH . '/include';

        // Compile VMA C++ wrapper — the Makefile.frag rule exists but shared_objects_vio
        // isn't consumed by the micro SAPI target, so we compile it manually and add it.
        $vmaWrapper = $buildDir . '/ext/vio/src/backends/vulkan/vio_vma_wrapper.cpp';
        $vmaLo = 'ext/vio/src/backends/vulkan/vio_vma_wrapper.lo';
        if (file_exists($vmaWrapper)) {
            $vmaInc = $buildDir . '/ext/vio/vendor/vma';
            $extInc = $buildDir . '/ext/vio/include';
            @mkdir(dirname("{$buildDir}/{$vmaLo}"), 0755, true);

            $cxx = PHP_OS_FAMILY === 'Darwin' ? 'c++' : 'g++';
            $phpIncludes = "-I{$buildDir} -I{$buildDir}/main -I{$buildDir}/Zend -I{$buildDir}/TSRM";
            shell()->cd($buildDir)->exec(
                "/bin/sh {$buildDir}/libtool --silent --preserve-dup-deps --tag=CXX --mode=compile {$cxx} -std=c++14"
                . " -I{$vmaInc} -I{$extInc} -I{$vulkanInc} {$phpIncludes}"
                . ' -DHAVE_VULKAN=1 -fPIC -O2'
                . " -c {$vmaWrapper} -o {$vmaLo}"
            );

            // Add to PHP_MICRO_OBJS so make links it into micro.sfx
            FileSystem::replaceFileStr(
                "{$buildDir}/Makefile",
                'PHP_MICRO_OBJS = ',
                "PHP_MICRO_OBJS = {$vmaLo} "
            );
        }

        // Compile Metal backend on macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $metalSrc = $buildDir . '/ext/vio/src/backends/metal/vio_metal.m';
            $metalLo = 'ext/vio/src/backends/metal/vio_metal.lo';
            if (file_exists($metalSrc)) {
                $extInc = $buildDir . '/ext/vio/include';
                @mkdir(dirname("{$buildDir}/{$metalLo}"), 0755, true);

                $phpIncludes = "-I{$buildDir} -I{$buildDir}/main -I{$buildDir}/Zend -I{$buildDir}/TSRM";
                shell()->cd($buildDir)->exec(
                    "/bin/sh {$buildDir}/libtool --silent --preserve-dup-deps --tag=CC --mode=compile cc"
                    . ' -x objective-c -fobjc-arc'
                    . " -I{$extInc} -I{$vulkanInc} {$phpIncludes}"
                    . ' -DHAVE_VULKAN=1 -DHAVE_METAL=1 -fPIC -O2'
                    . " -c {$metalSrc} -o {$metalLo}"
                );

                FileSystem::replaceFileStr(
                    "{$buildDir}/Makefile",
                    'PHP_MICRO_OBJS = ',
                    "PHP_MICRO_OBJS = {$metalLo} "
                );
            }
        }

        return true;
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $args = '--enable-vio'
            . ' --with-glfw-dir=' . BUILD_ROOT_PATH
            . ' --with-glslang=' . BUILD_ROOT_PATH
            . ' --with-spirv-cross=' . BUILD_ROOT_PATH
            . ' --with-vulkan=' . BUILD_ROOT_PATH;

        if (PHP_OS_FAMILY === 'Darwin') {
            $args .= ' --with-metal';
        }

        return $args;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        return '--enable-vio=static';
    }
}
