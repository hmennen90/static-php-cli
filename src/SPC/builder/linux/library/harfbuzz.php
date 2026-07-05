<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

use SPC\util\executor\UnixCMakeExecutor;

class harfbuzz extends LinuxLibraryBase
{
    public const NAME = 'harfbuzz';

    protected function build(): void
    {
        // Minimal static build: HarfBuzz's own OpenType shaper covers the
        // complex scripts vio needs (Arabic joining/BiDi, Thai clustering)
        // without ICU/GLib/FreeType, so every optional integration stays off to
        // keep the static lib self-contained and dependency-free.
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DHB_HAVE_FREETYPE=OFF',
                '-DHB_HAVE_GLIB=OFF',
                '-DHB_HAVE_GOBJECT=OFF',
                '-DHB_HAVE_ICU=OFF',
                '-DHB_BUILD_SUBSET=OFF',
                '-DHB_BUILD_UTILS=OFF',
            )
            ->build();
    }
}
