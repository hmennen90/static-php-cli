<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends MacOSLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DBUILD_SHARED_LIBS=OFF',
                '-DBUILD_TESTS=OFF',
                '-DUPDATE_DEPS=OFF',
                '-DBUILD_STATIC_LOADER=ON',
            )
            ->build();
    }
}
