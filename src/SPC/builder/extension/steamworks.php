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

        // PHP expects php_steamworks.h in the extension root, but it lives in src/
        // Copy it to the root so internal_functions.c can find it on all platforms
        $extDir = SOURCE_PATH . '/php-src/ext/steamworks';
        $header = $extDir . '/src/php_steamworks.h';
        if (file_exists($header) && !file_exists($extDir . '/php_steamworks.h')) {
            copy($header, $extDir . '/php_steamworks.h');
        }

        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        $sdkSource = SOURCE_PATH . '/steamworks-sdk';

        // config.m4 expects SDK layout: <path>/public/steam/steam_api.h and
        // <path>/redistributable_bin/{osx,linux64}/libsteam_api.{dylib,so}
        // Create this layout in BUILD_ROOT_PATH/steamworks-sdk/
        $sdkDest = BUILD_ROOT_PATH . '/steamworks-sdk';

        // Copy redistributable libs
        logger()->debug("Steamworks SDK source dir: {$sdkSource} (exists: " . (is_dir($sdkSource) ? 'yes' : 'no') . ')');
        if (PHP_OS_FAMILY === 'Darwin') {
            $osxDir = $sdkDest . '/redistributable_bin/osx';
            @mkdir($osxDir, 0755, true);
            $dylib = $sdkSource . '/redistributable_bin/osx/libsteam_api.dylib';
            logger()->debug("Looking for dylib at: {$dylib} (exists: " . (file_exists($dylib) ? 'yes' : 'no') . ')');
            if (file_exists($dylib)) {
                copy($dylib, $osxDir . '/libsteam_api.dylib');
                // Copy to buildroot/lib for artifact upload and buildroot/bin for sanity test
                @mkdir(BUILD_ROOT_PATH . '/lib', 0755, true);
                copy($dylib, BUILD_ROOT_PATH . '/lib/libsteam_api.dylib');
                @mkdir(BUILD_ROOT_PATH . '/bin', 0755, true);
                copy($dylib, BUILD_ROOT_PATH . '/bin/libsteam_api.dylib');
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $linuxDir = $sdkDest . '/redistributable_bin/linux64';
            @mkdir($linuxDir, 0755, true);
            $so = $sdkSource . '/redistributable_bin/linux64/libsteam_api.so';
            logger()->debug("Looking for .so at: {$so} (exists: " . (file_exists($so) ? 'yes' : 'no') . ')');
            if (file_exists($so)) {
                copy($so, $linuxDir . '/libsteam_api.so');
                @mkdir(BUILD_ROOT_PATH . '/lib', 0755, true);
                copy($so, BUILD_ROOT_PATH . '/lib/libsteam_api.so');
                @mkdir(BUILD_ROOT_PATH . '/bin', 0755, true);
                copy($so, BUILD_ROOT_PATH . '/bin/libsteam_api.so');
            }
        }

        // For static builds, PHP_ADD_LIBRARY_WITH_PATH in config.m4 only affects
        // SHARED_LIBADD which is ignored. We need to add the link flags manually.
        $existing = getenv('SPC_EXTRA_LIBS') ?: '';
        if (!str_contains($existing, '-lsteam_api')) {
            $existing .= ' -lsteam_api';
        }
        putenv('SPC_EXTRA_LIBS=' . trim($existing));

        // Copy mock SDK headers into public/steam/ layout (matches config.m4 check)
        // Note: FileSystem::copyDir uses cp -r which nests if target exists,
        // so only create the parent dir and let copyDir create the final "steam" dir
        $publicDir = $sdkDest . '/public';
        @mkdir($publicDir, 0755, true);
        $mockHeaders = SOURCE_PATH . '/ext-steamworks/ci/mock_sdk/public/steam';
        if (is_dir($mockHeaders)) {
            FileSystem::copyDir($mockHeaders, $publicDir . '/steam');
        }

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
        // Also copy .lib to buildroot/lib so the linker can find it
        if (file_exists($lib)) {
            @mkdir(BUILD_ROOT_PATH . '/lib', 0755, true);
            copy($lib, BUILD_ROOT_PATH . '/lib/steam_api64.lib');
        }

        // Copy mock SDK headers into public/steam/ layout
        $publicDir = $sdkDest . '/public';
        @mkdir($publicDir, 0755, true);
        $mockHeaders = SOURCE_PATH . '/ext-steamworks/ci/mock_sdk/public/steam';
        if (is_dir($mockHeaders)) {
            FileSystem::copyDir($mockHeaders, $publicDir . '/steam');
        }

        return true;
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        return '--with-steamworks=' . BUILD_ROOT_PATH . '/steamworks-sdk';
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        return '--with-steamworks=' . BUILD_ROOT_PATH . '/steamworks-sdk';
    }
}
