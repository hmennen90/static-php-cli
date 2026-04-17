<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends MacOSLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader on macOS supports APPLE_STATIC_LOADER which builds a static
        // lib but skips the install step with return(). Remove only the return() block
        // so that the static lib gets installed.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);

            // Remove the return() that skips install - target only the specific block:
            //   if(APPLE_STATIC_LOADER)
            //     return()
            //   endif()
            // This block is separate from the add_library block. Match precisely
            // by requiring return() on the line after the if().
            $content = preg_replace(
                '/^if\s*\(\s*APPLE_STATIC_LOADER\s*\)\s*\n\s*return\(\)\s*\n\s*endif\(\)/m',
                '# static build: install is not skipped',
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
                '-DAPPLE_STATIC_LOADER=ON',
            )
            ->build();
    }
}
