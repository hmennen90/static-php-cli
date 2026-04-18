<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\exception\BuildFailureException;
use SPC\store\FileSystem;

class vulkan_loader extends WindowsLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Build vulkan-loader as a shared DLL. Earlier spc revisions patched this
        // to STATIC to avoid shipping vulkan-1.dll, but the static loader has
        // well-known ICD discovery issues — vkCreateInstance crashes at runtime
        // because the loader's per-process init expects to run from DllMain.
        // Shipping vulkan-1.dll alongside the binary is ~1.7 MB and is the
        // officially supported Khronos deployment model.
        FileSystem::resetDir($this->source_dir . '\build');

        cmd()->cd($this->source_dir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DBUILD_TESTS=OFF ' .
                '-DBUILD_SHARED_LIBS=ON ' .
                '-DVULKAN_HEADERS_INSTALL_DIR=' . BUILD_ROOT_PATH . ' ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release -j{$this->builder->concurrency}"
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '--install build --config Release'
            );

        // vulkan-loader's CMake install step copies vulkan-1.lib (import library)
        // to buildroot/lib/ but does NOT copy vulkan-1.dll to buildroot/bin/ on
        // Windows (the target's install config doesn't declare a RUNTIME
        // destination). Copy the DLL manually so it ends up alongside php.exe.
        $lib = BUILD_ROOT_PATH . '\lib\vulkan-1.lib';
        if (!file_exists($lib)) {
            throw new BuildFailureException('vulkan-1.lib (import) not found after install: ' . $lib);
        }

        $dllSrc = $this->source_dir . '\build\loader\Release\vulkan-1.dll';
        if (!file_exists($dllSrc)) {
            throw new BuildFailureException('vulkan-1.dll not found in build output: ' . $dllSrc);
        }
        FileSystem::createDir(BUILD_ROOT_PATH . '\bin');
        copy($dllSrc, BUILD_ROOT_PATH . '\bin\vulkan-1.dll');
    }
}
