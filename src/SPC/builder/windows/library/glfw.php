<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\store\FileSystem;

class glfw extends WindowsLibraryBase
{
    public const NAME = 'glfw';

    protected function build(): void
    {
        $glfwDir = $this->source_dir . '\vendor\glfw';
        FileSystem::resetDir($glfwDir . '\build');

        cmd()->cd($glfwDir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DBUILD_SHARED_LIBS=OFF ' .
                '-DGLFW_BUILD_EXAMPLES=OFF ' .
                '-DGLFW_BUILD_TESTS=OFF ' .
                '-DGLFW_BUILD_DOCS=OFF ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release --target install -j{$this->builder->concurrency}"
            );
    }
}
