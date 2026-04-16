<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

class ffmpeg extends LinuxLibraryBase
{
    use \SPC\builder\unix\library\ffmpeg;

    public const NAME = 'ffmpeg';
}
