<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends LinuxLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader uses APPLE_STATIC_LOADER on macOS to build a static lib.
        // Despite the name, this flag works on Linux too - it selects the STATIC
        // code path in the if/else/endif block, preserving all target definitions
        // (like loader_specific_options). We patch the return() block that skips
        // the install step.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);
            // Remove the APPLE_STATIC_LOADER return() block that skips install
            $content = preg_replace(
                '/if\s*\(\s*APPLE_STATIC_LOADER\s*\)\s*\n.*?return\(\)\s*\n\s*endif\(\)/s',
                '# static build: install is not skipped',
                $content
            );
            file_put_contents($loaderCmake, $content);
        }

        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DBUILD_SHARED_LIBS=OFF',
                '-DBUILD_TESTS=OFF',
                '-DUPDATE_DEPS=OFF',
                '-DAPPLE_STATIC_LOADER=ON',
                '-DBUILD_WSI_XCB_SUPPORT=OFF',
                '-DBUILD_WSI_XLIB_SUPPORT=OFF',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DBUILD_WSI_DIRECTFB_SUPPORT=OFF',
            )
            ->build();
    }
}
