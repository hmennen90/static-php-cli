<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\shell\UnixShell;

trait ffmpeg
{
    protected function build(): void
    {
        $shell = new UnixShell();
        $shell->cd($this->source_dir)
            ->exec(
                './configure'
                . ' --prefix=' . BUILD_ROOT_PATH
                . ' --disable-shared'
                . ' --enable-static'
                . ' --disable-programs'
                . ' --disable-doc'
                . ' --disable-network'
                . ' --disable-autodetect'
                . ' --disable-iconv'
                . ' --enable-small'
            )
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');
        $this->patchPkgconfPrefix(['libavcodec.pc', 'libavformat.pc', 'libavutil.pc', 'libswscale.pc', 'libswresample.pc']);
    }
}
