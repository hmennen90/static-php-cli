<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends MacOSLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader uses APPLE_STATIC_LOADER on macOS which builds a static lib
        // but skips the install step with return(). Patch it to allow installation.
        $loaderCmake = $this->source_dir . '/loader/CMakeLists.txt';
        if (file_exists($loaderCmake)) {
            $content = file_get_contents($loaderCmake);
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
                '-DAPPLE_STATIC_LOADER=ON',
            )
            ->build();
    }
}
