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

        // PHP's genif.sh scans ext/<name>/*.h* for "phpext_" to generate
        // internal_functions_cli.c includes. The vulkan extension puts its header
        // in src/php_vulkan.h which genif.sh can't find. Create a wrapper at
        // the expected location that includes the real header AND contains the
        // phpext_ symbol so genif.sh picks it up.
        $wrapperHeader = SOURCE_PATH . '/php-src/ext/vulkan/php_vulkan.h';
        if (!file_exists($wrapperHeader)) {
            file_put_contents($wrapperHeader, <<<'H'
                #ifndef PHP_VULKAN_WRAPPER_H
                #define PHP_VULKAN_WRAPPER_H
                #include "src/php_vulkan.h"
                /* phpext_vulkan_ptr is defined in src/php_vulkan.h — this comment
                   ensures genif.sh detects this header via its phpext_ grep. */
                #endif
                H . "\n");
        }

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
