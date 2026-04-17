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
        // Vulkan-Loader hardcodes add_library(vulkan SHARED) on Linux inside an
        // if(APPLE_STATIC_LOADER)...STATIC...else()...SHARED...endif() block.
        // Replace the entire block with a single STATIC call, and remove the
        // later APPLE_STATIC_LOADER guard that calls return() to skip install.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);
            // Replace the if/else/endif block around add_library with just STATIC
            $content = preg_replace(
                '/if\s*\(\s*APPLE_STATIC_LOADER\s*\)\s*\n\s*add_library\(vulkan\s+STATIC\).*?else\(\)\s*\n\s*add_library\(vulkan\s+SHARED\)\s*\n\s*endif\(\)/s',
                'add_library(vulkan STATIC)',
                $content
            );
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
                '-DBUILD_WSI_XCB_SUPPORT=OFF',
                '-DBUILD_WSI_XLIB_SUPPORT=OFF',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DBUILD_WSI_DIRECTFB_SUPPORT=OFF',
            )
            ->build();
    }
}
