<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('glfw')]
class glfw extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/glfw')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-glfw', SOURCE_PATH . '/php-src/ext/glfw');
        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        FileSystem::replaceFileStr(SOURCE_PATH . '/php-src/configure', '-lglfw ', '-lglfw3 ');

        // ogt_vox_c_wrapper.cpp requires the C++ standard library
        $extraLibs = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extraLibs[] = '-lc++';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extraLibs[] = '-lstdc++';
            // GLFW X11 backend dependencies (only .so available on Alpine, no static .a)
            // Disable -all-static so these can be dynamically linked
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
        return '--enable-glfw --with-glfw-dir=' . BUILD_ROOT_PATH;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        return '--enable-glfw=static';
    }
}
