<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\store\FileSystem;

class spirv_cross extends WindowsLibraryBase
{
    public const NAME = 'spirv-cross';

    protected function build(): void
    {
        FileSystem::resetDir($this->source_dir . '\build');

        cmd()->cd($this->source_dir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DSPIRV_CROSS_SHARED=OFF ' .
                '-DSPIRV_CROSS_CLI=OFF ' .
                '-DSPIRV_CROSS_ENABLE_TESTS=OFF ' .
                '-DSPIRV_CROSS_STATIC=ON ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release --target install -j{$this->builder->concurrency}"
            );
    }
}
