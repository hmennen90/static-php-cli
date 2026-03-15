<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class glfw extends LinuxLibraryBase
{
    public const NAME = 'glfw';

    protected function build(): void
    {
        // GLFW needs X11 headers/libs from the system.
        // The musl toolchain restricts CMake search to buildroot,
        // so we add /usr to CMAKE_FIND_ROOT_PATH for this build.
        UnixCMakeExecutor::create($this)
            ->setBuildDir("{$this->source_dir}/vendor/glfw")
            ->setReset(false)
            ->addConfigureArgs(
                '-DGLFW_BUILD_EXAMPLES=OFF',
                '-DGLFW_BUILD_TESTS=OFF',
                '-DGLFW_BUILD_WAYLAND=OFF',
                '"-DCMAKE_FIND_ROOT_PATH=' . BUILD_ROOT_PATH . ';/usr"',
            )
            ->build('.');
        // patch pkgconf
        $this->patchPkgconfPrefix(['glfw3.pc']);
    }
}
