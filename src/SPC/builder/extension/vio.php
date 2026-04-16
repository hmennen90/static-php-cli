<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('vio')]
class vio extends Extension
{
    public function patchBeforeBuildconf(): bool
    {
        if (file_exists(SOURCE_PATH . '/php-src/ext/vio')) {
            return false;
        }
        FileSystem::copyDir(SOURCE_PATH . '/ext-vio', SOURCE_PATH . '/php-src/ext/vio');

        // Remove vio's bundled vendor libs that duplicate php-glfw's (glad, stb, miniaudio)
        // Both config.m4 and config.w32 list these; removing prevents LNK2005 on Windows
        // and potential issues on Unix.
        $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
        if (file_exists($configM4)) {
            // Replace the entire vendor source block with just a comma to end the source list
            FileSystem::replaceFileStr(
                $configM4,
                "vendor/glad/src/glad.c \\\n" .
                "    vendor/stb/stb_image_impl.c \\\n" .
                "    vendor/stb/stb_truetype_impl.c \\\n" .
                "    vendor/stb/stb_image_write_impl.c \\\n" .
                "    vendor/miniaudio/miniaudio_impl.c,",
                ','  // just the comma that ends the PHP_NEW_EXTENSION source list
            );
        }
        $configW32 = SOURCE_PATH . '/php-src/ext/vio/config.w32';
        if (file_exists($configW32)) {
            $w32content = file_get_contents($configW32);
            // Remove all vendor source lines (glad, stb_*, miniaudio)
            $w32content = preg_replace(
                '/\s*"vendor\\\\\\\\(?:glad\\\\\\\\src\\\\\\\\glad|stb\\\\\\\\stb_\w+|miniaudio\\\\\\\\miniaudio_impl)\.c " \+/m',
                '',
                $w32content
            );
            file_put_contents($configW32, $w32content);
        }

        // On macOS: add Metal .m source to config.m4 source list so phpize picks it up.
        // We rename .m to .c and compile with -x objective-c via CFLAGS in patchBeforeMake().
        if (PHP_OS_FAMILY === 'Darwin') {
            $metalSrc = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.m';
            $metalC   = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.c';
            if (file_exists($metalSrc) && !file_exists($metalC)) {
                copy($metalSrc, $metalC);
            }

            // Add vio_metal.c to the PHP_NEW_EXTENSION source list in config.m4
            $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
            if (file_exists($configM4)) {
                FileSystem::replaceFileStr(
                    $configM4,
                    'src/vio_backend_null.c \\',
                    "src/vio_backend_null.c \\\n    src/backends/metal/vio_metal.c \\"
                );
            }
        }

        return true;
    }

    public function patchBeforeConfigure(): bool
    {
        $extraLibs = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extraLibs[] = '-lc++';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extraLibs[] = '-lstdc++';
            // GPU drivers require dynamic linking
            putenv('SPC_NO_STATIC_LINK=1');
            // X11 dependencies (same as glfw)
            $extraLibs = array_merge($extraLibs, [
                '-lX11', '-lXrandr', '-lXinerama', '-lXcursor', '-lXi',
                '-lXext', '-lXfixes', '-lXrender', '-lxcb', '-lXau', '-lXdmcp',
            ]);
            $extraLibs[] = '-ldl';
            $extraLibs[] = '-lpthread';
            $extraLibs[] = '-lm';
        }

        $existing = getenv('SPC_EXTRA_LIBS') ?: '';
        foreach ($extraLibs as $lib) {
            if (!str_contains($existing, $lib)) {
                $existing .= ' ' . $lib;
            }
        }
        putenv('SPC_EXTRA_LIBS=' . trim($existing));

        return true;
    }

    public function patchBeforeMake(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        // Patch the generated Makefile so the Metal .c file (copied from .m)
        // is compiled as Objective-C with ARC enabled.
        $makefile = SOURCE_PATH . '/php-src/Makefile';
        if (!file_exists($makefile)) {
            return false;
        }

        $content = file_get_contents($makefile);

        // The Makefile may have duplicate rules for vio_metal.lo (one explicit,
        // one from a pattern). Remove all generated compile rules for vio_metal.lo
        // and replace with a single custom rule that uses -x objective-c.
        // First, remove any existing compile rules for this target.
        $content = preg_replace(
            '/^ext\/vio\/src\/backends\/metal\/vio_metal\.lo:.*\n(?:\t.*\n)*/m',
            '',
            $content
        );

        // Add a single custom compile rule at the end of the Makefile
        $content .= "\n# Custom ObjC compile rule for Metal backend\n";
        $content .= "ext/vio/src/backends/metal/vio_metal.lo: \$(srcdir)/ext/vio/src/backends/metal/vio_metal.c\n";
        $content .= "\t\$(LIBTOOL) --silent --preserve-dup-deps --tag=CC --mode=compile \$(CC) \$(CFLAGS) \$(CFLAGS_CLEAN) \$(EXTRA_CFLAGS) -x objective-c -fobjc-arc -c \$< -o \$@ -MMD -MF ext/vio/src/backends/metal/vio_metal.dep -MT \$@\n";

        file_put_contents($makefile, $content);
        return true;
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $args = '--enable-vio';
        $args .= ' --with-glfw=' . BUILD_ROOT_PATH;
        $args .= ' --with-glslang=' . BUILD_ROOT_PATH;
        $args .= ' --with-spirv-cross=' . BUILD_ROOT_PATH;

        // Optional: ffmpeg
        if ($this->builder->getLib('ffmpeg') !== null) {
            $args .= ' --with-ffmpeg=' . BUILD_ROOT_PATH;
        } else {
            $args .= ' --without-ffmpeg';
        }

        // Vulkan: disabled in static builds — VMA C++ wrapper requires
        // Makefile.frag which is not processed in the static build pipeline
        $args .= ' --without-vulkan';

        // Metal: macOS only (Objective-C source is patched into the build above)
        if (PHP_OS_FAMILY === 'Darwin') {
            $args .= ' --with-metal';
        } else {
            $args .= ' --without-metal';
        }

        return $args;
    }

    public function getWindowsConfigureArg(bool $shared = false): string
    {
        $args = '--enable-vio --with-glfw --with-glslang --with-spirv-cross';
        $args .= ' --with-d3d11 --with-d3d12';

        if ($this->builder->getLib('vulkan-loader') !== null) {
            $args .= ' --with-vulkan';
        }
        if ($this->builder->getLib('ffmpeg') !== null) {
            $args .= ' --with-ffmpeg';
        }

        return $args;
    }
}
