<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends LinuxLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader defaults to SHARED on Linux. Patch to build STATIC instead.
        // We only change the library type in the else() branch (non-Apple path)
        // and remove the install(EXPORT) that references targets not in the export set.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);

            // The non-Apple code path has: add_library(vulkan SHARED ...)
            // Change SHARED to STATIC for static linking.
            $content = str_replace(
                'add_library(vulkan SHARED',
                'add_library(vulkan STATIC',
                $content
            );

            // Remove install(EXPORT) which fails with static builds
            $content = preg_replace(
                '/install\(EXPORT\s+VulkanLoaderConfig[^)]*\)/',
                '# static: export removed',
                $content
            );

            file_put_contents($loaderCmake, $content);
        }

        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DBUILD_SHARED_LIBS=OFF',
                '-DBUILD_TESTS=OFF',
                '-DUPDATE_DEPS=OFF',
                '-DBUILD_WSI_XCB_SUPPORT=OFF',
                '-DBUILD_WSI_XLIB_SUPPORT=OFF',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DBUILD_WSI_DIRECTFB_SUPPORT=OFF',
            )
            ->build();
    }
}
