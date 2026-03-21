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
        // Symlink all X11/GL library files (static .a and shared .so) into buildroot
        // Search both /usr/lib/ (Alpine) and multiarch paths like /usr/lib/x86_64-linux-gnu/ (Ubuntu/Debian)
        $libDirs = array_filter(array_unique([
            '/usr/lib',
            '/usr/lib64',                                       // CentOS/RHEL
            '/usr/lib/' . php_uname('m') . '-linux-gnu',       // x86_64-linux-gnu (Ubuntu/Debian)
            '/usr/lib/' . php_uname('m') . '-linux-musl',      // musl variant
        ]), 'is_dir');
        $prefixes = ['libX', 'libxcb', 'libXau', 'libXdmcp', 'libGL', 'libEGL'];
        $linked = 0;
        foreach ($libDirs as $libDir) {
            foreach ($prefixes as $prefix) {
                foreach (glob("{$libDir}/{$prefix}*") as $lib) {
                    if (!is_file($lib) && !is_link($lib)) continue;
                    $dst = BUILD_ROOT_PATH . '/lib/' . basename($lib);
                    if (!file_exists($dst)) {
                        @symlink($lib, $dst);
                        $linked++;
                    }
                }
            }
        }
        logger()->debug("Symlinked {$linked} X11/GL libs into buildroot");
        // List what we have for debugging
        $available = glob(BUILD_ROOT_PATH . '/lib/libX*');
        logger()->debug('Available X11 libs in buildroot: ' . implode(', ', array_map('basename', $available)));

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
