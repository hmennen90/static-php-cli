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

        // Vulkan: on macOS via MoltenVK, on Linux via vulkan-loader
        if ($this->builder->getLib('vulkan-loader') !== null || $this->builder->getLib('moltenvk') !== null) {
            $args .= ' --with-vulkan=' . BUILD_ROOT_PATH;
        } else {
            $args .= ' --without-vulkan';
        }

        // Metal: macOS only (uses system frameworks)
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
