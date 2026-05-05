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
            $fontstash = SOURCE_PATH . DIRECTORY_SEPARATOR . 'php-src' . DIRECTORY_SEPARATOR
                . 'ext' . DIRECTORY_SEPARATOR . 'glfw' . DIRECTORY_SEPARATOR . 'vendor'
                . DIRECTORY_SEPARATOR . 'nanovg' . DIRECTORY_SEPARATOR . 'src'
                . DIRECTORY_SEPARATOR . 'fontstash.h';
            if (file_exists($fontstash)) {
                // Normalize CRLF before matching — Windows sources may have \r\n
                $content = file_get_contents($fontstash);
                $content = str_replace("\r\n", "\n", $content);
                if (!str_contains($content, '#define STBTT_STATIC')) {
                    $content = str_replace(
                        "#define STB_TRUETYPE_IMPLEMENTATION\n",
                        "#define STB_TRUETYPE_IMPLEMENTATION\n#define STBTT_STATIC\n",
                        $content
                    );
                    file_put_contents($fontstash, $content);
                }
                logger()->info('[vio] Patched fontstash.h with STBTT_STATIC');
            } else {
                logger()->warning('[vio] fontstash.h not found at: ' . $fontstash);
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

            // When the glfw PHP extension is present, its ARG_ENABLE("glfw")
            // conflicts with vio's ARG_WITH("glfw") - PHP's configure.js only
            // processes the first registration, so vio's ARG_WITH is ignored and
            // HAVE_GLFW never gets set. Fix: remove vio's ARG_WITH("glfw") and
            // replace the detection block with a direct buildroot path check.
            if ($this->builder->getExt('glfw') !== null) {
                $w32Content = preg_replace('/^\s*ARG_WITH\("glfw"[^;]*;\s*\n/m', '', $w32Content);
                // ARG_ENABLE("glfw") from php-glfw conflicts with vio's
                // ARG_WITH("glfw") in PHP's configure.js - second registration
                // is ignored, so PHP_GLFW-based detection never finds headers.
                // Replace with direct buildroot path check.
                // Match the v1.10.2 upstream block (PHP_GLFW-based, with default
                // "yes" → PHP_PHP_BUILD reassignment). Older block variants are
                // kept as fallbacks in case upstream shifts wording again.
                $glfw_v1_10_2 = <<<'JSBLOCK'
    if (PHP_GLFW != "no") {
        if (PHP_GLFW == "yes") {
            PHP_GLFW = PHP_PHP_BUILD;
        }
        if (CHECK_HEADER_ADD_INCLUDE("GLFW/glfw3.h", "CFLAGS_VIO", PHP_GLFW + "\\include")) {
            if (CHECK_LIB("glfw3dll.lib", "vio", PHP_GLFW + "\\lib") ||
                CHECK_LIB("glfw3.lib", "vio", PHP_GLFW + "\\lib")) {
                AC_DEFINE("HAVE_GLFW", 1, "Whether GLFW is available");
            } else {
                WARNING("GLFW library not found in " + PHP_GLFW + "\\lib");
            }
        } else {
            WARNING("GLFW headers not found in " + PHP_GLFW + "\\include");
        }
    }
JSBLOCK;
                $glfw_pre_v1_10 = <<<'JSBLOCK'
    if (CHECK_HEADER_ADD_INCLUDE("GLFW/glfw3.h", "CFLAGS_VIO", PHP_PHP_BUILD + "\\include")) {
        if (CHECK_LIB("glfw3dll.lib", "vio", PHP_PHP_BUILD + "\\lib") ||
            CHECK_LIB("glfw3.lib", "vio", PHP_PHP_BUILD + "\\lib")) {
            AC_DEFINE("HAVE_GLFW", 1, "Whether GLFW is available");
        } else {
            WARNING("GLFW library not found in " + PHP_PHP_BUILD + "\\lib");
        }
    } else {
        WARNING("GLFW headers not found in " + PHP_PHP_BUILD + "\\include");
    }
JSBLOCK;
                $newGlfw = <<<'JSBLOCK'
    // GLFW: detect via buildroot (glfw ext provides ARG_ENABLE)
    if (CHECK_HEADER_ADD_INCLUDE("GLFW/glfw3.h", "CFLAGS_VIO", PHP_PHP_BUILD + "\\include")) {
        if (CHECK_LIB("glfw3dll.lib", "vio", PHP_PHP_BUILD + "\\lib") ||
            CHECK_LIB("glfw3.lib", "vio", PHP_PHP_BUILD + "\\lib")) {
            AC_DEFINE("HAVE_GLFW", 1, "Whether GLFW is available");
        } else {
            WARNING("GLFW library not found in buildroot");
        }
    } else {
        WARNING("GLFW headers not found in buildroot");
    }
JSBLOCK;
                if (str_contains($w32Content, $glfw_v1_10_2)) {
                    $w32Content = str_replace($glfw_v1_10_2, $newGlfw, $w32Content);
                } elseif (str_contains($w32Content, $glfw_pre_v1_10)) {
                    $w32Content = str_replace($glfw_pre_v1_10, $newGlfw, $w32Content);
                } else {
                    logger()->warning('[vio] GLFW detection block in upstream config.w32 did not match any known variant - HAVE_GLFW may be silently undefined');
                }
            }
            if ($this->builder->getExt('vulkan') !== null) {
                $w32Content = preg_replace('/^\s*ARG_WITH\("vulkan"[^;]*;\s*\n/m', '', $w32Content);

                // Same problem as GLFW: removing ARG_WITH("vulkan") leaves
                // PHP_VULKAN undefined, so the upstream PHP_VULKAN-based detection
                // silently fails. Replace it with a direct buildroot check.
                $vulkan_v1_10_2 = <<<'JSBLOCK'
    if (PHP_VULKAN != "no") {
        if (PHP_VULKAN == "yes") {
            PHP_VULKAN = PHP_PHP_BUILD;
        }
        if (CHECK_HEADER_ADD_INCLUDE("vulkan/vulkan.h", "CFLAGS_VIO", PHP_VULKAN + "\\include")) {
            if (CHECK_LIB("vulkan-1.lib", "vio", PHP_VULKAN + "\\lib")) {
                AC_DEFINE("HAVE_VULKAN", 1, "Whether Vulkan is available");
            } else {
                WARNING("Vulkan library not found in " + PHP_VULKAN + "\\lib");
            }
        } else {
            WARNING("Vulkan headers not found in " + PHP_VULKAN + "\\include");
        }
    }
JSBLOCK;
                $newVulkan = <<<'JSBLOCK'
    // Vulkan: detect via buildroot (vulkan ext provides ARG_ENABLE)
    if (CHECK_HEADER_ADD_INCLUDE("vulkan/vulkan.h", "CFLAGS_VIO", PHP_PHP_BUILD + "\\include")) {
        if (CHECK_LIB("vulkan-1.lib", "vio", PHP_PHP_BUILD + "\\lib")) {
            AC_DEFINE("HAVE_VULKAN", 1, "Whether Vulkan is available");
        } else {
            WARNING("Vulkan library not found in buildroot");
        }
    } else {
        WARNING("Vulkan headers not found in buildroot");
    }
JSBLOCK;
                if (str_contains($w32Content, $vulkan_v1_10_2)) {
                    $w32Content = str_replace($vulkan_v1_10_2, $newVulkan, $w32Content);
                }
            }

            file_put_contents($configW32, $w32Content);
        }

        // VMA wrapper: on Windows, PHP uses config.w32.h not config.h, and HAVE_VULKAN
        // is defined via /D compiler flags. Patch the include to be platform-aware.
        // Also strip the `#ifdef HAVE_CONFIG_H` guard upstream added in v1.10.x —
        // PHP_NEW_EXTENSION's compile rule does not pass -DHAVE_CONFIG_H, so the
        // guarded include would be skipped, leaving HAVE_VULKAN undefined and the
        // whole .cpp empty (linker errors for vio_vma_create et al.).
        $vmaWrapper = SOURCE_PATH . '/php-src/ext/vio/src/backends/vulkan/vio_vma_wrapper.cpp';
        if (file_exists($vmaWrapper)) {
            $vmaContent = file_get_contents($vmaWrapper);
            $platformInclude = "#ifdef _WIN32\n#include \"config.w32.h\"\n#else\n#include \"config.h\"\n#endif";

            $guarded = "#ifdef HAVE_CONFIG_H\n#include \"config.h\"\n#endif";
            if (str_contains($vmaContent, $guarded)) {
                $vmaContent = str_replace($guarded, $platformInclude, $vmaContent);
                file_put_contents($vmaWrapper, $vmaContent);
            } elseif (str_contains($vmaContent, '#include "config.h"')) {
                $vmaContent = str_replace('#include "config.h"', $platformInclude, $vmaContent);
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

        // Pass --with-glfw only when the glfw PHP extension is NOT present.
        // When glfw ext IS present, patchBeforeBuildconf removes vio's
        // ARG_WITH("glfw") and replaces detection with a direct buildroot
        // check - no command-line flag needed.
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
