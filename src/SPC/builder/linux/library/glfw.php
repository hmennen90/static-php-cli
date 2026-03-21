<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class glfw extends LinuxLibraryBase
{
    public const NAME = 'glfw';

    protected function build(): void
    {
        // GLFW needs X11 headers/libs from the system (/usr).
        // The musl toolchain restricts CMAKE_FIND_ROOT_PATH to buildroot only.
        // Symlink system X11 headers and libs into buildroot so cmake finds them.
        $x11Dirs = ['X11', 'GL'];
        foreach ($x11Dirs as $dir) {
            $src = "/usr/include/{$dir}";
            $dst = BUILD_ROOT_PATH . "/include/{$dir}";
            if (is_dir($src) && !file_exists($dst)) {
                symlink($src, $dst);
            }
        }
        // Symlink X11 libraries
        foreach (glob('/usr/lib/libX*.so*') as $lib) {
            $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
            if (!file_exists($dst)) {
                symlink($lib, $dst);
            }
        }
        foreach (glob('/usr/lib/libxcb*.so*') as $lib) {
            $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
            if (!file_exists($dst)) {
                symlink($lib, $dst);
            }
        }
        // GL/EGL libs for mesa
        foreach (glob('/usr/lib/libGL*.so*') as $lib) {
            $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
            if (!file_exists($dst)) {
                symlink($lib, $dst);
            }
        }
        foreach (glob('/usr/lib/libEGL*.so*') as $lib) {
            $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
            if (!file_exists($dst)) {
                symlink($lib, $dst);
            }
        }

        UnixCMakeExecutor::create($this)
            ->setBuildDir("{$this->source_dir}/vendor/glfw")
            ->setReset(false)
            ->addConfigureArgs(
                '-DGLFW_BUILD_EXAMPLES=OFF',
                '-DGLFW_BUILD_TESTS=OFF',
                '-DGLFW_BUILD_WAYLAND=OFF',
            )
            ->build('.');
        // patch pkgconf
        $this->patchPkgconfPrefix(['glfw3.pc']);
    }
}
