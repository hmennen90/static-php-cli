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

    public function getUnixConfigureArg(bool $shared = false): string
    {
        return '--with-steamworks=' . BUILD_ROOT_PATH;
    }
}
