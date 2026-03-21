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
        // Symlink X11/GL static and shared libraries into buildroot
        $patterns = [
            '/usr/lib/libX*.a', '/usr/lib/libX*.so*',
            '/usr/lib/libxcb*.a', '/usr/lib/libxcb*.so*',
            '/usr/lib/libGL*.a', '/usr/lib/libGL*.so*',
            '/usr/lib/libEGL*.a', '/usr/lib/libEGL*.so*',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $lib) {
                $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
                if (!file_exists($dst)) {
                    @symlink($lib, $dst);
                }
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
