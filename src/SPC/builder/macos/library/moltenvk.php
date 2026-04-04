<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\store\FileSystem;

class moltenvk extends MacOSLibraryBase
{
    public const NAME = 'moltenvk';

    protected function build(): void
    {
        // MoltenVK is distributed as pre-built binaries via GitHub Releases.
        // The tarball contains: MoltenVK/MoltenVK/static/MoltenVK.xcframework/macos-arm64_x86_64/libMoltenVK.a
        //                       MoltenVK/MoltenVK/include/MoltenVK/*.h
        //                       MoltenVK/MoltenVK/include/vulkan/*.h
        $xcfwBase = $this->source_dir . '/MoltenVK/MoltenVK/static/MoltenVK.xcframework/macos-arm64_x86_64';
        $includeBase = $this->source_dir . '/MoltenVK/MoltenVK/include';

        // Copy static library
        copy($xcfwBase . '/libMoltenVK.a', BUILD_LIB_PATH . '/libMoltenVK.a');

        // Copy MoltenVK headers
        FileSystem::copyDir($includeBase . '/MoltenVK', BUILD_INCLUDE_PATH . '/MoltenVK');

        // Copy Vulkan headers (needed by extensions that link against Vulkan)
        FileSystem::copyDir($includeBase . '/vulkan', BUILD_INCLUDE_PATH . '/vulkan');
    }
}
