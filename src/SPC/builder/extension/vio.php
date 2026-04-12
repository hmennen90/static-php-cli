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
        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        // Fix library names in configure if needed
        FileSystem::replaceFileStr(SOURCE_PATH . '/php-src/configure', '-lglfw ', '-lglfw3 ');

        // Fix strdup declaration in vio_shader_compiler.c — C23 requires explicit declarations
        // and strdup is only available with _GNU_SOURCE or _POSIX_C_SOURCE >= 200809L
        $shaderCompiler = SOURCE_PATH . '/php-src/ext/vio/src/vio_shader_compiler.c';
        if (file_exists($shaderCompiler)) {
            $content = file_get_contents($shaderCompiler);
            if (!str_contains($content, '_GNU_SOURCE')) {
                file_put_contents($shaderCompiler, "#ifndef _GNU_SOURCE\n#define _GNU_SOURCE\n#endif\n" . $content);
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
