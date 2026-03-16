<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\store\FileSystem;

class zlib extends WindowsLibraryBase
{
    public const NAME = 'zlib';

    protected function build(): void
    {
        // reset cmake
        FileSystem::resetDir($this->source_dir . '\build');

        // start build
        cmd()->cd($this->source_dir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DBUILD_SHARED_LIBS=OFF ' .
                '-DSKIP_INSTALL_FILES=ON ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release --target install -j{$this->builder->concurrency}"
            );
        // zlib >=1.3.2 changed output names (zlibstatic.lib -> zs.lib),
        // and 1.3.3+ will use libz.lib. Detect whichever exists and
        // normalize to the names PHP and other consumers expect.
        $staticCandidates = ['zlibstatic.lib', 'zs.lib', 'libzs.lib', 'libz.lib'];
        $found = null;
        foreach ($staticCandidates as $candidate) {
            if (file_exists(BUILD_LIB_PATH . '\\' . $candidate)) {
                $found = $candidate;
                break;
            }
        }
        if ($found === null) {
            throw new \RuntimeException('zlib build produced no known static library. Looked for: ' . implode(', ', $staticCandidates));
        }
        copy(BUILD_LIB_PATH . '\\' . $found, BUILD_LIB_PATH . '\zlib_a.lib');
        // Create zlibstatic.lib alias for openssl and CMake FindZLIB consumers
        if ($found !== 'zlibstatic.lib') {
            copy(BUILD_LIB_PATH . '\\' . $found, BUILD_LIB_PATH . '\zlibstatic.lib');
        }

        // Clean up shared lib artifacts (try all known names, suppress errors)
        foreach (['zlib.dll', 'z.dll', 'libz.dll'] as $dll) {
            @unlink(BUILD_ROOT_PATH . '\\bin\\' . $dll);
        }
        foreach (['zlib.lib', 'z.lib', 'libz.lib'] as $implib) {
            $path = BUILD_LIB_PATH . '\\' . $implib;
            if ($implib !== $found && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
