<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\store\FileSystem;
use SPC\util\executor\UnixCMakeExecutor;

class glfw extends LinuxLibraryBase
{
    public const NAME = 'glfw';

    protected function build(): void
    {
        // GLFW needs X11 headers/libs from the system (/usr).
        // The musl toolchain restricts CMAKE_FIND_ROOT_PATH to buildroot only,
        // and the toolchain.cmake file overrides the -D flag from the command line.
        // Temporarily patch the toolchain to include /usr in the search path.
        $toolchain = SOURCE_PATH . '/toolchain.cmake';
        $original = file_get_contents($toolchain);
        $patched = str_replace(
            'SET(CMAKE_FIND_ROOT_PATH "' . BUILD_ROOT_PATH . '")',
            'SET(CMAKE_FIND_ROOT_PATH "' . BUILD_ROOT_PATH . ';/usr")',
            $original
        );
        FileSystem::writeFile($toolchain, $patched);

        UnixCMakeExecutor::create($this)
            ->setBuildDir("{$this->source_dir}/vendor/glfw")
            ->setReset(false)
            ->addConfigureArgs(
                '-DGLFW_BUILD_EXAMPLES=OFF',
                '-DGLFW_BUILD_TESTS=OFF',
                '-DGLFW_BUILD_WAYLAND=OFF',
            )
            ->build('.');

        // Restore original toolchain
        FileSystem::writeFile($toolchain, $original);

        // patch pkgconf
        $this->patchPkgconfPrefix(['glfw3.pc']);
    }
}
