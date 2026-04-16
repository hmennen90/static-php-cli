<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends LinuxLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DBUILD_SHARED_LIBS=OFF',
                '-DBUILD_TESTS=OFF',
                '-DUPDATE_DEPS=OFF',
                '-DBUILD_WSI_XCB_SUPPORT=OFF',
                '-DBUILD_WSI_XLIB_SUPPORT=OFF',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DBUILD_WSI_DIRECTFB_SUPPORT=OFF',
                '-DBUILD_STATIC_LOADER=ON',
            )
            ->build();
    }
}
