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
        // Vulkan-Loader only supports shared libraries on Linux by default.
        // Patch CMakeLists.txt to build a static library instead.
        FileSystem::replaceFileStr(
            $this->source_dir . '/loader/CMakeLists.txt',
            'add_library(vulkan SHARED)',
            'add_library(vulkan STATIC)'
        );

        // Symlink X11/XCB headers and libs into buildroot so cmake finds them
        foreach (['X11', 'xcb'] as $dir) {
            $src = "/usr/include/{$dir}";
            $dst = BUILD_ROOT_PATH . "/include/{$dir}";
            if (is_dir($src) && !file_exists($dst)) {
                @symlink($src, $dst);
            }
        }

        // Symlink X11/XCB pkg-config files into buildroot so cmake's pkg_check_modules works
        $pkgDst = BUILD_ROOT_PATH . '/lib/pkgconfig';
        @mkdir($pkgDst, 0755, true);
        $pkgDirs = array_filter(array_unique([
            '/usr/lib/pkgconfig',
            '/usr/lib64/pkgconfig',
            '/usr/lib/' . php_uname('m') . '-linux-gnu/pkgconfig',
            '/usr/lib/' . php_uname('m') . '-linux-musl/pkgconfig',
            '/usr/share/pkgconfig',
        ]), 'is_dir');
        $pkgPrefixes = ['xcb', 'x11', 'xau', 'xdmcp', 'xrandr', 'xinerama', 'xcursor', 'xi', 'xext', 'xfixes', 'xrender'];
        foreach ($pkgDirs as $dir) {
            foreach (glob("{$dir}/*.pc") as $pc) {
                $name = strtolower(basename($pc, '.pc'));
                foreach ($pkgPrefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $dst = "{$pkgDst}/" . basename($pc);
                        if (!file_exists($dst)) {
                            @symlink($pc, $dst);
                        }
                        break;
                    }
                }
            }
        }

        // Symlink X11/XCB libraries
        $libDirs = array_filter(array_unique([
            '/usr/lib',
            '/usr/lib64',
            '/usr/lib/' . php_uname('m') . '-linux-gnu',
            '/usr/lib/' . php_uname('m') . '-linux-musl',
        ]), 'is_dir');
        $libPrefixes = ['libX', 'libxcb', 'libXau', 'libXdmcp'];
        foreach ($libDirs as $libDir) {
            foreach ($libPrefixes as $prefix) {
                foreach (glob("{$libDir}/{$prefix}*") as $lib) {
                    if (!is_file($lib) && !is_link($lib)) {
                        continue;
                    }
                    $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
                    if (!file_exists($dst)) {
                        @symlink($lib, $dst);
                    }
                }
            }
        }

        // Build only (skip install which fails due to export set incompatibility with static builds)
        UnixCMakeExecutor::create($this)
            ->appendEnv(['PKG_CONFIG_PATH' => BUILD_ROOT_PATH . '/lib/pkgconfig:/usr/lib/pkgconfig:/usr/share/pkgconfig'])
            ->addConfigureArgs(
                '-DBUILD_TESTS=OFF',
                '-DBUILD_WSI_XCB_SUPPORT=OFF',
                '-DBUILD_WSI_XLIB_SUPPORT=ON',
                '-DBUILD_WSI_WAYLAND_SUPPORT=OFF',
                '-DVULKAN_HEADERS_INSTALL_DIR=' . BUILD_ROOT_PATH,
            )
            ->toStep(2)
            ->build();

        // Manually install the static library (cmake install fails with STATIC due to export set issues)
        copy($this->source_dir . '/build/loader/libvulkan.a', BUILD_LIB_PATH . '/libvulkan.a');
    }
}
