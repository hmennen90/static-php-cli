<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\store\FileSystem;
use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends LinuxLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader hardcodes add_library(vulkan SHARED) on Linux.
        // Patch the loader CMakeLists.txt to build a static library instead.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);
            // Change SHARED to STATIC in the add_library call
            $content = preg_replace(
                '/add_library\(vulkan\s+SHARED\b/',
                'add_library(vulkan STATIC',
                $content
            );
            // Remove the APPLE_STATIC_LOADER return() that skips install
            $content = preg_replace(
                '/if\s*\(\s*APPLE_STATIC_LOADER\s*\).*?return\(\).*?endif\(\)/s',
                '# static build: removed APPLE_STATIC_LOADER return()',
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
