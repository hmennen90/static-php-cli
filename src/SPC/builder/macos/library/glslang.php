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
                '-DBUILD_SHARED_LIBS=OFF',
                '-DENABLE_CTEST=OFF',
                '-DENABLE_GLSLANG_BINARIES=OFF',
                '-DENABLE_SPVREMAPPER=OFF',
            )
            ->build();
    }
}
