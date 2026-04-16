<?php

declare(strict_types=1);

namespace SPC\builder\macos\library;

class ffmpeg extends MacOSLibraryBase
{
    use \SPC\builder\unix\library\ffmpeg;

    public const NAME = 'ffmpeg';
}
