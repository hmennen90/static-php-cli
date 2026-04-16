<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class spirv_cross extends LinuxLibraryBase
{
    public const NAME = 'spirv-cross';

    protected function build(): void
    {
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DSPIRV_CROSS_SHARED=OFF',
                '-DSPIRV_CROSS_CLI=OFF',
                '-DSPIRV_CROSS_ENABLE_TESTS=OFF',
            )
            ->build();
    }
}
