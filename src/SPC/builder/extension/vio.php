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

        // Makefile.frag objects (VMA C++ wrapper, Metal .m) are only added to
        // shared_objects_vio which isn't used in static builds. We add sources
        // directly to PHP_NEW_EXTENSION and remove the Makefile.frag rules.
        // NOTE: VMA insertion must happen BEFORE vendor removal, because vendor
        // removal changes the trailing `\` to `,` after vio_vulkan.c.
        $configM4 = SOURCE_PATH . '/php-src/ext/vio/config.m4';
        $makefileFrag = SOURCE_PATH . '/php-src/ext/vio/Makefile.frag';

        // VMA C++ wrapper: add .cpp to config.m4 source list for static builds.
        if (file_exists($configM4)) {
            FileSystem::replaceFileStr(
                $configM4,
                'src/backends/vulkan/vio_vulkan.c \\',
                "src/backends/vulkan/vio_vulkan.c \\\n    src/backends/vulkan/vio_vma_wrapper.cpp \\"
            );
        }

        // Remove the VMA block from Makefile.frag to prevent duplicate rules
        if (file_exists($makefileFrag)) {
            $content = file_get_contents($makefileFrag);
            $content = preg_replace(
                '/^# VMA C\+\+.*?^endif\s*$/ms',
                '',
                $content
            );
            file_put_contents($makefileFrag, $content);
        }

        // Remove vio's bundled vendor libs that duplicate php-glfw's (glad, stb, miniaudio)
        // Only do this when the glfw PHP extension is also being built, since it provides
        // the same symbols. Without glfw, vio needs its own vendor implementations.
        $hasGlfwExt = $this->builder->getExt('glfw') !== null;

        if ($hasGlfwExt) {
            if (file_exists($configM4)) {
                FileSystem::replaceFileStr(
                    $configM4,
                    " \\\n    vendor/glad/src/glad.c" .
                    " \\\n    vendor/stb/stb_image_impl.c" .
                    " \\\n    vendor/stb/stb_truetype_impl.c" .
                    " \\\n    vendor/stb/stb_image_write_impl.c" .
                    " \\\n    vendor/miniaudio/miniaudio_impl.c,",
                    ','
                );
            }
            $configW32 = SOURCE_PATH . '/php-src/ext/vio/config.w32';
            if (file_exists($configW32)) {
                FileSystem::replaceFileStr(
                    $configW32,
                    "\" +\n" .
                    "        \"vendor\\\\glad\\\\src\\\\glad.c \" +\n" .
                    "        \"vendor\\\\stb\\\\stb_image_impl.c \" +\n" .
                    "        \"vendor\\\\stb\\\\stb_truetype_impl.c \" +\n" .
                    "        \"vendor\\\\stb\\\\stb_image_write_impl.c \" +\n" .
                    "        \"vendor\\\\miniaudio\\\\miniaudio_impl.c\";",
                    '";'
                );
            }
        }

        // On Windows, VIO's config.w32 registers ARG_WITH("glfw") and ARG_WITH("vulkan").
        // If the standalone glfw/vulkan PHP extensions are also present, they register
        // ARG_ENABLE("glfw") / ARG_WITH("vulkan") for the same option names.
        // PHP's buildconf hoists ALL ARG declarations, creating duplicate entries in
        // configure_args[] that can cause --enable-glfw and --with-vulkan to be
        // silently ignored. Remove VIO's conflicting ARG declarations when the
        // standalone extensions handle them.
        if (PHP_OS_FAMILY === 'Windows') {
            $configW32 = SOURCE_PATH . '/php-src/ext/vio/config.w32';
            if (file_exists($configW32)) {
                $w32Content = file_get_contents($configW32);
                if ($this->builder->getExt('glfw') !== null) {
                    $w32Content = preg_replace('/^\s*ARG_WITH\("glfw"[^;]*;\s*$/m', '// removed: ARG_WITH("glfw") — provided by ext/glfw', $w32Content);
                }
                if ($this->builder->getExt('vulkan') !== null) {
                    $w32Content = preg_replace('/^\s*ARG_WITH\("vulkan"[^;]*;\s*$/m', '// removed: ARG_WITH("vulkan") — provided by ext/vulkan', $w32Content);
                }
                file_put_contents($configW32, $w32Content);
            }
        }

        // Metal: macOS only — compile .m as .c with ObjC flags injected later.
        if (PHP_OS_FAMILY === 'Darwin') {
            $metalSrc = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.m';
            $metalC = SOURCE_PATH . '/php-src/ext/vio/src/backends/metal/vio_metal.c';
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

            // Remove the Metal block from Makefile.frag to prevent a duplicate
            // rule for vio_metal.lo (we handle it via config.m4 above).
            $makefileFrag = SOURCE_PATH . '/php-src/ext/vio/Makefile.frag';
            if (file_exists($makefileFrag)) {
                $content = file_get_contents($makefileFrag);
                $content = preg_replace(
                    '/^# Metal backend.*?^endif\s*$/ms',
                    '',
                    $content
                );
                file_put_contents($makefileFrag, $content);
            }
        }

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
        // The Makefile expands $(abs_srcdir) to the full path, so match
        // any path ending with the Metal source file.
        $content = preg_replace(
            '#-c\s+(\S+/ext/vio/src/backends/metal/vio_metal\.c)#',
            '-x objective-c -fobjc-arc -c $1',
            $content
        );
        file_put_contents($makefile, $content);

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

        // Vulkan: enable if vulkan-headers are available (VMA wrapper is now
        // added to PHP_NEW_EXTENSION in patchBeforeBuildconf)
        if ($this->builder->getLib('vulkan-headers') !== null) {
            $args .= ' --with-vulkan=' . BUILD_ROOT_PATH;
        } else {
            $args .= ' --without-vulkan';
        }

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
        $args = '--enable-vio --with-glslang --with-spirv-cross';
        $args .= ' --with-d3d11 --with-d3d12';

        // Only pass --with-glfw if the glfw PHP extension is NOT present,
        // to avoid duplicate/conflicting ARG registrations on the command line.
        if ($this->builder->getExt('glfw') === null) {
            $args .= ' --with-glfw';
        }

        // Only pass --with-vulkan if the vulkan PHP extension is NOT present.
        if ($this->builder->getExt('vulkan') === null && $this->builder->getLib('vulkan-loader') !== null) {
            $args .= ' --with-vulkan';
        }

        if ($this->builder->getLib('ffmpeg') !== null) {
            $args .= ' --with-ffmpeg';
        }

        return $args;
    }
}
