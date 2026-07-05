<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

use SPC\util\executor\UnixCMakeExecutor;

class harfbuzz extends MacOSLibraryBase
{
    public const NAME = 'harfbuzz';

    protected function build(): void
    {
        // Minimal static build (see the Linux builder for rationale). CoreText
        // is disabled too — vio only needs HarfBuzz's built-in OpenType shaper,
        // so we avoid pulling the CoreText framework into the static link.
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DHB_HAVE_FREETYPE=OFF',
                '-DHB_HAVE_GLIB=OFF',
                '-DHB_HAVE_GOBJECT=OFF',
                '-DHB_HAVE_ICU=OFF',
                '-DHB_HAVE_CORETEXT=OFF',
                '-DHB_BUILD_SUBSET=OFF',
                '-DHB_BUILD_UTILS=OFF',
            )
            ->build();
    }
}
