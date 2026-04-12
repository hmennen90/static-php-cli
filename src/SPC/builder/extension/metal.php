<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('metal')]
class metal extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/metal')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-metal', SOURCE_PATH . '/php-src/ext/metal');
        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        // Metal uses Objective-C with ARC — ensure the frameworks are linked
        $extraLibs = ['-lc++'];

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
        return '--enable-metal';
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        // Metal is macOS-only, no Windows support
        return '';
    }
}
