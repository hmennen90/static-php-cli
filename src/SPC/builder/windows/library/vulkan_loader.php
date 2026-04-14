<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\store\FileSystem;

class vulkan_loader extends WindowsLibraryBase
{
    public const NAME = 'vulkan-loader';

    protected function build(): void
    {
        // Vulkan-Loader hardcodes SHARED in its CMakeLists.txt — patch to STATIC
        $loaderCmake = $this->source_dir . '\loader\CMakeLists.txt';
        $content = file_get_contents($loaderCmake);
        $content = str_replace('add_library(vulkan SHARED)', 'add_library(vulkan STATIC)', $content);
        // Remove install(EXPORT) which fails with static builds
        $content = preg_replace('/install\(EXPORT\s+VulkanLoaderConfig[^)]*\)/', '# static: export removed', $content);
        file_put_contents($loaderCmake, $content);

        FileSystem::resetDir($this->source_dir . '\build');

        cmd()->cd($this->source_dir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DBUILD_TESTS=OFF ' .
                '-DBUILD_SHARED_LIBS=OFF ' .
                '-DVULKAN_HEADERS_INSTALL_DIR=' . BUILD_ROOT_PATH . ' ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release -j{$this->builder->concurrency}"
            );

        // Manually install — cmake install fails with STATIC due to export set issues
        FileSystem::createDir(BUILD_LIB_PATH);
        $libSrc = $this->source_dir . '\build\loader\Release\vulkan-1.lib';
        if (!file_exists($libSrc)) {
            // Fallback: some cmake versions put it without Release subdir
            $libSrc = $this->source_dir . '\build\loader\vulkan-1.lib';
        }
        copy($libSrc, BUILD_LIB_PATH . '\vulkan-1.lib');
    }
}
