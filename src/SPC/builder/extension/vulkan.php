<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('vulkan')]
class vulkan extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/vulkan')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-vulkan', SOURCE_PATH . '/php-src/ext/vulkan');
        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        // Fix library names in configure (-lglfw -> -lglfw3)
        FileSystem::replaceFileStr(SOURCE_PATH . '/php-src/configure', '-lglfw ', '-lglfw3 ');

        $extraLibs = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extraLibs[] = '-lc++';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extraLibs[] = '-lstdc++';
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
        return '--with-vulkan=' . BUILD_ROOT_PATH;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        return '--with-vulkan=' . BUILD_ROOT_PATH;
    }
}
