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

        // Remove vio's bundled vendor libs that duplicate php-glfw's (glad, stb, miniaudio)
        // Both config.m4 and config.w32 list these; removing prevents LNK2005 on Windows
        // and potential issues on Unix.
        $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
        if (file_exists($configM4)) {
            // Replace from the continuation backslash of the last non-vendor line
            // through the end of the vendor block, ending with just a comma.
            FileSystem::replaceFileStr(
                $configM4,
                " \\\n    vendor/glad/src/glad.c" .
                " \\\n    vendor/stb/stb_image_impl.c" .
                " \\\n    vendor/stb/stb_truetype_impl.c" .
                " \\\n    vendor/stb/stb_image_write_impl.c" .
                " \\\n    vendor/miniaudio/miniaudio_impl.c,",
                ','
            );
        }
        $configW32 = SOURCE_PATH . '/php-src/ext/vio/config.w32';
        if (file_exists($configW32)) {
            // Replace the vendor source block in config.w32 with just the final semicolon.
            // The block goes from glad.c through miniaudio_impl.c (last source file).
            // Replace from the end of the last non-vendor source line through
            // the end of the vendor block. The previous line ends with '" +'
            // and we replace it all with just '";' to end the source string.
            FileSystem::replaceFileStr(
                $configW32,
                "\" +\n" .
                "        \"vendor\\\\glad\\\\src\\\\glad.c \" +\n" .
                "        \"vendor\\\\stb\\\\stb_image_impl.c \" +\n" .
                "        \"vendor\\\\stb\\\\stb_truetype_impl.c \" +\n" .
                "        \"vendor\\\\stb\\\\stb_image_write_impl.c \" +\n" .
                '        "vendor\\\miniaudio\\\miniaudio_impl.c";',
                '";'  // close the previous source entry with semicolon
            );
        }

        // Makefile.frag objects (VMA C++ wrapper, Metal .m) are only added to
        // shared_objects_vio which isn't used in static builds. We add sources
        // directly to PHP_NEW_EXTENSION and remove the Makefile.frag rules.
        $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
        $makefileFrag = SOURCE_PATH . '/php-src/ext/vio/Makefile.frag';

        // VMA C++ wrapper: add .cpp to config.m4 source list for static builds.
        // PHP's build system handles .cpp via CXX rules when PHP_REQUIRE_CXX() is set.
        if (file_exists($configM4)) {
            FileSystem::replaceFileStr(
                $configM4,
                'src/backends/vulkan/vio_vulkan.c \\',
                "src/backends/vulkan/vio_vulkan.c \\\n    src/backends/vulkan/vio_vma_wrapper.cpp \\"
            );
        }

        // Remove the VMA block from Makefile.frag to prevent duplicate rules
        if (file_exists($makefileFrag)) {
            $content = file_get_contents($makefileFrag);
            $content = preg_replace(
                '/^# VMA C\+\+.*?^endif\s*$/ms',
                '',
                $content
            );
            file_put_contents($makefileFrag, $content);
        }

        // Metal: macOS only — compile .m as .c with ObjC flags injected later.
        if (PHP_OS_FAMILY === 'Darwin') {
            $metalSrc = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.m';
            $metalC = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.c';
            if (file_exists($metalSrc) && !file_exists($metalC)) {
                copy($metalSrc, $metalC);
            }

            // Add vio_metal.c to the PHP_NEW_EXTENSION source list in config.m4
            $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
            if (file_exists($configM4)) {
                FileSystem::replaceFileStr(
                    $configM4,
                    'src/vio_backend_null.c \\',
                    "src/vio_backend_null.c \\\n    src/backends/metal/vio_metal.c \\"
                );
            }

            // Remove the Metal block from Makefile.frag to prevent a duplicate
            // rule for vio_metal.lo (we handle it via config.m4 above).
            $makefileFrag = SOURCE_PATH . '/php-src/ext/vio/Makefile.frag';
            if (file_exists($makefileFrag)) {
                $content = file_get_contents($makefileFrag);
                $content = preg_replace(
                    '/^# Metal backend.*?^endif\s*$/ms',
                    '',
                    $content
                );
                file_put_contents($makefileFrag, $content);
            }
        }

        return true;
    }

    public function patchBeforeMake(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        // Patch the generated Makefile so the Metal .c file (copied from .m)
        // is compiled as Objective-C with ARC enabled.
        $makefile = SOURCE_PATH . '/php-src/Makefile';
        if (!file_exists($makefile)) {
            return false;
        }

        $content = file_get_contents($makefile);
        // The Makefile expands $(abs_srcdir) to the full path, so match
        // any path ending with the Metal source file.
        $content = preg_replace(
            '#-c\s+(\S+/ext/vio/src/backends/metal/vio_metal\.c)#',
            '-x objective-c -fobjc-arc -c $1',
            $content
        );
        file_put_contents($makefile, $content);

        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        $extraLibs = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extraLibs[] = '-lc++';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extraLibs[] = '-lstdc++';
            // GPU drivers require dynamic linking
            putenv('SPC_NO_STATIC_LINK=1');
            // X11 dependencies (same as glfw)
            $extraLibs = array_merge($extraLibs, [
                '-lX11', '-lXrandr', '-lXinerama', '-lXcursor', '-lXi',
                '-lXext', '-lXfixes', '-lXrender', '-lxcb', '-lXau', '-lXdmcp',
            ]);
            $extraLibs[] = '-ldl';
            $extraLibs[] = '-lpthread';
            $extraLibs[] = '-lm';
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
        $args = '--enable-vio';
        $args .= ' --with-glfw=' . BUILD_ROOT_PATH;
        $args .= ' --with-glslang=' . BUILD_ROOT_PATH;
        $args .= ' --with-spirv-cross=' . BUILD_ROOT_PATH;

        // Optional: ffmpeg
        if ($this->builder->getLib('ffmpeg') !== null) {
            $args .= ' --with-ffmpeg=' . BUILD_ROOT_PATH;
        } else {
            $args .= ' --without-ffmpeg';
        }

        // Vulkan: enable if vulkan-headers are available (VMA wrapper is now
        // added to PHP_NEW_EXTENSION in patchBeforeBuildconf)
        if ($this->builder->getLib('vulkan-headers') !== null) {
            $args .= ' --with-vulkan=' . BUILD_ROOT_PATH;
        } else {
            $args .= ' --without-vulkan';
        }

        // Metal: macOS only (Objective-C source is patched into the build above)
        if (PHP_OS_FAMILY === 'Darwin') {
            $args .= ' --with-metal';
        } else {
            $args .= ' --without-metal';
        }

        return $args;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        $args = '--enable-vio --with-glfw --with-glslang --with-spirv-cross';
        $args .= ' --with-d3d11 --with-d3d12';

        if ($this->builder->getLib('vulkan-loader') !== null) {
            $args .= ' --with-vulkan';
        }
        if ($this->builder->getLib('ffmpeg') !== null) {
            $args .= ' --with-ffmpeg';
        }

        return $args;
    }
}
