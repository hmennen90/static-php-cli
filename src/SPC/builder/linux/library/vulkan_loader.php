<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class vulkan_loader extends LinuxLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Symlink X11 headers/libs into buildroot (vulkan-loader WSI support needs them)
        $x11Dirs = ['X11'];
        foreach ($x11Dirs as $dir) {
            $src = "/usr/include/{$dir}";
            $dst = BUILD_ROOT_PATH . "/include/{$dir}";
            if (is_dir($src) && !file_exists($dst)) {
                symlink($src, $dst);
            }
        }

        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DBUILD_TESTS=OFF',
                '-DBUILD_WSI_XCB_SUPPORT=ON',
                '-DBUILD_WSI_XLIB_SUPPORT=ON',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DVULKAN_HEADERS_INSTALL_DIR=' . BUILD_ROOT_PATH,
            )
            ->build('.');
    }
}
