<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_headers extends MacOSLibraryBase
{
    public const NAME = 'vulkan-headers';

    protected function build(): void
    {
        // Vulkan-Headers is a header-only package; CMake install copies headers into buildroot.
        // This provides the full Vulkan header set including vk_video/ which MoltenVK doesn't ship.
        UnixCMakeExecutor::create($this)
            ->build();
    }
}
