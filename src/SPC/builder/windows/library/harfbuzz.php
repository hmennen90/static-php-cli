<?php

declare(strict_types=1);

namespace SPC\builder\windows\library;

use SPC\store\FileSystem;

class harfbuzz extends WindowsLibraryBase
{
    public const NAME = 'harfbuzz';

    protected function build(): void
    {
        FileSystem::resetDir($this->source_dir . '\build');

        // Minimal static build: HarfBuzz's built-in OpenType shaper is all vio
        // needs (Arabic/Thai shaping + BiDi), so ICU/GLib/FreeType and the
        // Windows text backends (GDI/Uniscribe/DirectWrite) stay off to keep the
        // static lib self-contained.
        cmd()->cd($this->source_dir)
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                '-B build ' .
                '-A x64 ' .
                "-DCMAKE_TOOLCHAIN_FILE={$this->builder->cmake_toolchain_file} " .
                '-DCMAKE_BUILD_TYPE=Release ' .
                '-DCMAKE_POLICY_DEFAULT_CMP0091=NEW ' .
                '-DBUILD_SHARED_LIBS=OFF ' .
                '-DHB_HAVE_FREETYPE=OFF ' .
                '-DHB_HAVE_GLIB=OFF ' .
                '-DHB_HAVE_GOBJECT=OFF ' .
                '-DHB_HAVE_ICU=OFF ' .
                '-DHB_HAVE_GDI=OFF ' .
                '-DHB_HAVE_UNISCRIBE=OFF ' .
                '-DHB_HAVE_DIRECTWRITE=OFF ' .
                '-DHB_BUILD_SUBSET=OFF ' .
                '-DHB_BUILD_UTILS=OFF ' .
                '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH . ' '
            )
            ->execWithWrapper(
                $this->builder->makeSimpleWrapper('cmake'),
                "--build build --config Release --target install -j{$this->builder->concurrency}"
            );
    }
}
