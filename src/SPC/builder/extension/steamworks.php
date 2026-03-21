<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('steamworks')]
class steamworks extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/steamworks')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-steamworks', SOURCE_PATH . '/php-src/ext/steamworks');
        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        $libDir = BUILD_ROOT_PATH . '/lib';
        $includeDir = BUILD_ROOT_PATH . '/include';

        // Copy SDK redistributable library for linking.
        // The Steamworks SDK is placed in downloads/steamworks-sdk/ manually (NDA).
        $sdkSource = SOURCE_PATH . '/steamworks-sdk';
        if (PHP_OS_FAMILY === 'Darwin') {
            $dylib = $sdkSource . '/redistributable_bin/osx/libsteam_api.dylib';
            if (file_exists($dylib)) {
                copy($dylib, $libDir . '/libsteam_api.dylib');
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $so = $sdkSource . '/redistributable_bin/linux64/libsteam_api.so';
            if (file_exists($so)) {
                copy($so, $libDir . '/libsteam_api.so');
            }
        }

        // Copy mock SDK headers (C-compatible) so configure can find them
        $steamIncDir = $includeDir . '/steam';
        if (!is_dir($steamIncDir)) {
            @mkdir($steamIncDir, 0755, true);
        }
        $mockHeaders = SOURCE_PATH . '/ext-steamworks/ci/mock_sdk/public/steam';
        if (is_dir($mockHeaders)) {
            FileSystem::copyDir($mockHeaders, $steamIncDir);
        }

        // Add libsteam_api as dynamic link dependency
        $existing = getenv('SPC_EXTRA_LIBS') ?: '';
        if (!str_contains($existing, '-lsteam_api')) {
            $existing .= ' -lsteam_api';
        }
        putenv('SPC_EXTRA_LIBS=' . trim($existing));

        return true;
    }

    public function patchBeforeWindowsConfigure(): bool
    {
        $sdkSource = SOURCE_PATH . '/steamworks-sdk';

        // config.w32 expects SDK layout: <path>/public/steam/*.h and
        // <path>/redistributable_bin/win64/steam_api64.lib
        // Create this layout in BUILD_ROOT_PATH/steamworks-sdk/
        $sdkDest = BUILD_ROOT_PATH . '/steamworks-sdk';

        // Copy redistributable libs
        $winLibDir = $sdkDest . '/redistributable_bin/win64';
        @mkdir($winLibDir, 0755, true);
        $lib = $sdkSource . '/redistributable_bin/win64/steam_api64.lib';
        if (file_exists($lib)) {
            copy($lib, $winLibDir . '/steam_api64.lib');
        }
        $dll = $sdkSource . '/redistributable_bin/win64/steam_api64.dll';
        if (file_exists($dll)) {
            // Also copy to buildroot/lib for artifact upload
            @mkdir(BUILD_ROOT_PATH . '/lib', 0755, true);
            copy($dll, BUILD_ROOT_PATH . '/lib/steam_api64.dll');
        }

        // Copy mock SDK headers into public/steam/ layout
        $steamIncDir = $sdkDest . '/public/steam';
        @mkdir($steamIncDir, 0755, true);
        $mockHeaders = SOURCE_PATH . '/ext-steamworks/ci/mock_sdk/public/steam';
        if (is_dir($mockHeaders)) {
            FileSystem::copyDir($mockHeaders, $steamIncDir);
        }

        return true;
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        return '--with-steamworks=' . BUILD_ROOT_PATH;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        return '--with-steamworks=' . BUILD_ROOT_PATH . '/steamworks-sdk';
    }
}
