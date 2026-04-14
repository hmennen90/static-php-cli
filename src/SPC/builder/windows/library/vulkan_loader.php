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
        // Vulkan-Loader hardcodes SHARED in its CMakeLists.txt for WIN32.
        // Replace with STATIC and remove the .def/.rc files (not needed for static).
        $loaderCmake = $this->source_dir . '\loader\CMakeLists.txt';
        $content = file_get_contents($loaderCmake);

        $content = preg_replace(
            '/add_library\(vulkan\s+SHARED\s+\$\{NORMAL_LOADER_SRCS\}\s+\$\{CMAKE_CURRENT_SOURCE_DIR\}\/\$\{API_TYPE\}-1\.def\s+\$\{RC_FILE_LOCATION\}\)/',
            'add_library(vulkan STATIC ${NORMAL_LOADER_SRCS})',
            $content
        );

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

        // Manually install the static library
        FileSystem::createDir(BUILD_LIB_PATH);

        // Find the built .lib — name depends on OUTPUT_NAME property
        $candidates = [
            $this->source_dir . '\build\loader\Release\vulkan-1.lib',
            $this->source_dir . '\build\loader\Release\vulkan.lib',
            $this->source_dir . '\build\loader\vulkan-1.lib',
            $this->source_dir . '\build\loader\vulkan.lib',
        ];
        $found = false;
        foreach ($candidates as $libSrc) {
            if (file_exists($libSrc)) {
                copy($libSrc, BUILD_LIB_PATH . '\vulkan-1.lib');
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new BuildFailureException('Cannot find built vulkan loader static library');
        }
    }
}
