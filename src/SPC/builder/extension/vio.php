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

        // Vulkan: disabled in static builds — VMA C++ wrapper requires
        // Makefile.frag which is not processed in the static build pipeline
        $args .= ' --without-vulkan';

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
