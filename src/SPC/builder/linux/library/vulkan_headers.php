<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_headers extends LinuxLibraryBase
{
    public const NAME = 'vulkan-headers';

    protected function build(): void
    {
        // Vulkan-Headers is a header-only package; CMake install copies headers into buildroot.
        UnixCMakeExecutor::create($this)
            ->build();
    }
}
