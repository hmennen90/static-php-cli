<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class glslang extends MacOSLibraryBase
{
    public const NAME = 'glslang';

    protected function build(): void
    {
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DENABLE_OPT=OFF',
                '-DENABLE_GLSLANG_BINARIES=OFF',
                '-DBUILD_TESTING=OFF',
                '-DBUILD_SHARED_LIBS=OFF',
            )
            ->build('.');
    }
}
