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

        $hasGlfwExt = $this->builder->getExt('glfw') !== null;

        // Patch php-glfw's fontstash.h to make stb_truetype symbols static.
        // Without this, nanovg.c compiles stb_truetype with STBTT_DEF=extern and
        // custom allocator fons__tmpalloc. In a static build, the linker resolves
        // vio's stbtt_PackBegin calls to php-glfw's version, which dereferences
        // the alloc_context pointer (NULL from vio) causing a SIGSEGV crash.
        if ($hasGlfwExt) {
            // glfw's patchBeforeBuildconf copies sources to php-src/ext/glfw/,
            // so we must patch the copy, not the original in ext-glfw/.
            $fontstash = SOURCE_PATH . '/php-src/ext/glfw/vendor/nanovg/src/fontstash.h';
            if (file_exists($fontstash)) {
                FileSystem::replaceFileStr(
                    $fontstash,
                    "#define STB_TRUETYPE_IMPLEMENTATION\n",
                    "#define STB_TRUETYPE_IMPLEMENTATION\n#define STBTT_STATIC\n"
                );
            }
        }

        // Remove vio's bundled vendor sources that duplicate php-glfw's when both
        // extensions are built together. php-glfw compiles glad, stb_image, and
        // miniaudio inline in its own .c files (phpglfw_texture.c, phpglfw_audio.c,
        // etc.), so vio's separate _impl.c files cause duplicate symbols on Unix.
        // Windows MSVC handles duplicate symbols without error, so skip there.
        //
        // IMPORTANT: stb_truetype_impl.c must NOT be removed. php-glfw's
        // stb_truetype uses fons__tmpalloc (fontstash scratch buffer) instead of
        // malloc, so vio cannot share php-glfw's stbtt_PackBegin - calling it
        // with alloc_context=NULL causes a NULL-pointer dereference crash.
        // php-glfw's stb_truetype symbols are made static via STBTT_STATIC in
        // fontstash.h, so no duplicate symbol conflict occurs.
        if ($hasGlfwExt && file_exists($configM4)) {
            $m4Content = file_get_contents($configM4);
            // Remove vendor/* lines EXCEPT stb_truetype_impl.c from PHP_NEW_EXTENSION.
            // Matches: " \<newline><whitespace>vendor/path" - whitespace-agnostic.
            $m4Content = preg_replace('/ \\\\\n\s*vendor\/(?!stb\/stb_truetype_impl\.c)[^,\s]+/', '', $m4Content);
            file_put_contents($configM4, $m4Content);
        }

        // Patch config.w32: normalize CRLF to LF for reliable string matching,
        // add VMA C++ wrapper, and remove conflicting ARG_WITH declarations
        // that prevent the standalone glfw/vulkan PHP extensions from being
        // enabled by configure.
        $configW32 = SOURCE_PATH . '/php-src/ext/vio/config.w32';
        if (file_exists($configW32)) {
            $w32Content = file_get_contents($configW32);
            $w32Content = str_replace("\r\n", "\n", $w32Content);

            // VMA C++ wrapper: add to source list for Vulkan support
            if ($this->builder->getLib('vulkan-headers') !== null) {
                $w32Content = str_replace(
                    "        \"src\\\\backends\\\\vulkan\\\\vio_vulkan.c \" +\n",
                    "        \"src\\\\backends\\\\vulkan\\\\vio_vulkan.c \" +\n" .
                    "        \"src\\\\backends\\\\vulkan\\\\vio_vma_wrapper.cpp \" +\n",
                    $w32Content
                );
            }

            // Remove ARG_WITH declarations that conflict with standalone extensions.
            // VIO's ARG_WITH("glfw") overrides ext/glfw's ARG_ENABLE("glfw") when
            // --disable-all is active, because ARG_WITH doesn't see --with-glfw
            // and resets PHP_GLFW to "no".
            if ($this->builder->getExt('glfw') !== null) {
                $w32Content = preg_replace('/^\s*ARG_WITH\("glfw"[^;]*;\s*\n/m', '', $w32Content);
            }
            if ($this->builder->getExt('vulkan') !== null) {
                $w32Content = preg_replace('/^\s*ARG_WITH\("vulkan"[^;]*;\s*\n/m', '', $w32Content);
            }

            file_put_contents($configW32, $w32Content);
        }

        // VMA wrapper: on Windows, PHP uses config.w32.h not config.h, and HAVE_VULKAN
        // is defined via /D compiler flags. Patch the include to be platform-aware.
        $vmaWrapper = SOURCE_PATH . '/php-src/ext/vio/src/backends/vulkan/vio_vma_wrapper.cpp';
        if (file_exists($vmaWrapper)) {
            $vmaContent = file_get_contents($vmaWrapper);
            if (str_contains($vmaContent, '#include "config.h"')) {
                $vmaContent = str_replace(
                    '#include "config.h"',
                    "#ifdef _WIN32\n#include \"config.w32.h\"\n#else\n#include \"config.h\"\n#endif",
                    $vmaContent
                );
                file_put_contents($vmaWrapper, $vmaContent);
            }
        }

        // Metal: macOS only - compile .m as .c with ObjC flags injected later.
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
