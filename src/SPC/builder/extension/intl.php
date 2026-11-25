<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\builder\windows\WindowsBuilder;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('intl')]
class intl extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if ($this->builder instanceof WindowsBuilder) {
            // Match the "shared" argument whatever the source list is: PHP 8.6 added intl_icu_compat.c to it,
            // which made the old exact-string replacement a silent no-op (intl came out as a separate php_intl.dll).
            FileSystem::replaceFileRegex(
                SOURCE_PATH . '/php-src/ext/intl/config.w32',
                '/(EXTENSION\("intl",\s*"[^"]*",\s*)true,/',
                '${1}PHP_INTL_SHARED,'
            );
            return true;
        }
        return false;
    }

    public function patchBeforeSharedPhpize(): bool
    {
        return $this->patchBeforeBuildconf();
    }
}
