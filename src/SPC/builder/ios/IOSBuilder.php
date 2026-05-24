<?php

declare(strict_types=1);

namespace SPC\builder\ios;

use SPC\builder\macos\MacOSBuilder;
use SPC\builder\macos\SystemUtil as MacOSSystemUtil;
use SPC\exception\WrongUsageException;
use SPC\store\FileSystem;
use SPC\store\SourcePatcher;
use SPC\util\GlobalEnvManager;
use SPC\util\SPCConfigUtil;

/**
 * iOS / iPadOS builder.
 *
 * Strategy:
 *   - We inherit from MacOSBuilder so that all 70+ macOS library overrides
 *     (under src/SPC/builder/macos/library) are reused as-is. PHPOS_FAMILY
 *     is still Darwin on the host, so osfamily2dir() returns 'macos'.
 *   - Only the PHP SAPI build is iOS-specific: we force --enable-embed=static
 *     and disable CLI/FPM/CGI/MICRO because those need fork()/exec() which
 *     are forbidden on iOS.
 *   - SDK path / arch / version-min are derived from SPC_TARGET (one of
 *     ios-arm64, ios-simulator-arm64, ios-simulator-x86_64) and injected
 *     into CFLAGS/LDFLAGS/CC etc. before the macOS toolchain code reads
 *     them.
 *
 * NOT implemented:
 *   - CLI, FPM, CGI, micro, FrankenPHP -- forbidden on iOS sandbox.
 *   - JIT (opcache) -- forbidden on iOS (no W^X violations allowed).
 *   - dynamic-loading -- iOS has no dlopen() for arbitrary dylibs.
 */
class IOSBuilder extends MacOSBuilder
{
    public function __construct(array $options = [])
    {
        // Inject iOS-specific env vars BEFORE the macOS env init runs so
        // that CFLAGS / CC / SDKROOT etc. are visible to GlobalEnvManager
        // and the toolchain code in the macOS builder.
        self::primeIOSEnvironment();

        parent::__construct($options);

        // Force-disable opcache JIT on iOS, no matter what the user requested.
        // iOS does not allow the dynamic code generation required for JIT.
        if (!isset($this->options['disable-opcache-jit'])) {
            $this->options['disable-opcache-jit'] = true;
        }
    }

    /**
     * Pre-set iOS env so that the macOS toolchain logic sees the iOS SDK.
     *
     * We override env vars even if previously set, because the env.ini for
     * the [macos] section will have shipped MAC_ARCH-based defaults that
     * are wrong for iOS cross-compiling.
     */
    public static function primeIOSEnvironment(): void
    {
        $target = SystemUtil::getTarget();
        $sdk = SystemUtil::getSdkName();
        $arch = SystemUtil::getArch();
        $sdk_path = SystemUtil::getSdkPath($sdk);
        $version_min_flag = SystemUtil::getArchCFlags($arch);

        $cc = SystemUtil::findSdkTool('clang', $sdk);
        $cxx = SystemUtil::findSdkTool('clang++', $sdk);
        $ar = SystemUtil::findSdkTool('ar', $sdk);
        $ranlib = SystemUtil::findSdkTool('ranlib', $sdk);
        $ld = SystemUtil::findSdkTool('ld', $sdk);
        $strip = SystemUtil::findSdkTool('strip', $sdk);

        // SPC_TARGET must be set so SPCTarget::getTargetOS() can route correctly.
        // Note: we treat iOS as Darwin for the purposes of SPCConfigUtil/UnixSystemUtilTrait.
        putenv("SPC_TARGET={$target}");

        // Override CC / CXX / AR / LD with SDK-resolved paths.
        // We keep CC / CXX as bare paths (the toolchain check verifies they
        // are existing files). The target / sysroot flags travel via CFLAGS
        // and CPPFLAGS for the *configure* invocation, and via CFLAGS for
        // the make step.
        putenv("CC={$cc}");
        putenv("CXX={$cxx}");
        // CPP: autoconf defaults to /lib/cpp which does not exist on macOS.
        // Force it to `clang -E` with the iOS target+sysroot so preprocessor
        // sanity checks pick up the right SDK headers.
        putenv("CPP={$cc} {$version_min_flag} -isysroot {$sdk_path} -E");
        putenv("CXXCPP={$cxx} {$version_min_flag} -isysroot {$sdk_path} -E");
        putenv("AR={$ar}");
        putenv("RANLIB={$ranlib}");
        putenv("LD={$ld}");
        putenv("STRIP={$strip}");

        // SDKROOT is required by Apple toolchain conventions.
        putenv("SDKROOT={$sdk_path}");
        putenv('IPHONEOS_DEPLOYMENT_TARGET=' . SystemUtil::getDeploymentTarget());

        // Architecture: keep SPC_ARCH consistent with the slice.
        putenv("SPC_ARCH={$arch}");
        putenv('MAC_ARCH=' . match ($arch) {
            'arm64' => 'arm64',
            'x86_64' => 'x86_64',
            default => $arch,
        });

        // CFLAGS / CXXFLAGS / LDFLAGS: replace the macOS defaults the env.ini
        // would otherwise inject (--target=...-apple-darwin). We use putenv()
        // directly (not GlobalEnvManager) because GlobalEnvManager skips vars
        // that are already set, so this overrides the [macos] block.
        //
        // NOTE: -fembed-bitcode is intentionally NOT set: Apple deprecated
        // bitcode in Xcode 14 and removed driver support in Xcode 26, where
        // it now leaks as a literal file path into the linker invocation.
        $isysroot = "-isysroot {$sdk_path}";
        $base_cflags = "{$version_min_flag} {$isysroot} -Os";
        putenv("SPC_DEFAULT_C_FLAGS={$base_cflags}");
        putenv("SPC_DEFAULT_CXX_FLAGS={$base_cflags}");
        putenv("SPC_DEFAULT_LD_FLAGS={$isysroot}");

        // EXTRA_CFLAGS for php-src configure/make.
        // -fno-common: PHP 8.4 prefers this
        // -DTARGET_OS_IOS=1: many libs sniff this
        putenv(
            'SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS='
            . '-g -fstack-protector-strong -fpic -Werror=unknown-warning-option '
            . "-DTARGET_OS_IPHONE=1 -DTARGET_OS_IOS=1 {$base_cflags}"
        );

        // iOS PHP can only be built as embed/static.
        putenv('SPC_CMD_VAR_PHP_EMBED_TYPE=static');

        // Override the macOS configure prefix to drop --with-valgrind=no
        // (irrelevant on iOS) and inject iOS-specific cross flags. We keep the
        // disable-all/disable-phpdbg/disable-shared/enable-static pattern.
        $hostTriple = match ($arch) {
            'arm64' => 'aarch64-apple-ios',
            'x86_64' => 'x86_64-apple-ios',
            default => "{$arch}-apple-ios",
        };
        putenv(
            'SPC_CMD_PREFIX_PHP_CONFIGURE='
            . './configure --prefix= '
            . '--host=' . $hostTriple . ' '
            . '--with-valgrind=no '
            . '--enable-shared=no --enable-static=yes '
            . '--disable-all --disable-phpdbg '
            . '--disable-fpm --disable-cli --disable-cgi '
            // OPcache JIT is dropped (W^X violations are forbidden on iOS:
            // pthread_jit_write_protect_np needs the dynamic-codesigning
            // entitlement). NOTE: there is no --disable-opcache in PHP 8.5+ -
            // OPcache is always compiled in. Its MINIT creates a lock file
            // under /tmp, which the iOS sandbox rejects; the embedding app
            // must therefore disable OPcache at runtime via the embed SAPI's
            // ini_defaults hook (opcache.enable=0 + opcache.lockfile_path
            // pointed at the app's tmp dir). See the iOS wrapper's PHPRuntime.
            // SLJIT/PCRE2 JIT is dropped for the same W^X reason.
            . '--disable-opcache-jit '
            . '--without-pcre-jit '
            . '--disable-dl-test '
        );

        // Concurrency: defer to host CPU count if not set explicitly.
        if (getenv('SPC_CONCURRENCY') === false) {
            putenv('SPC_CONCURRENCY=' . SystemUtil::getCpuCount());
        }
    }

    /**
     * On iOS we *only* support embed (libphp.a). Override the macOS build
     * to ignore CLI/FPM/CGI/MICRO/FRANKENPHP targets and force embed.
     */
    public function buildPHP(int $build_target = BUILD_TARGET_NONE): void
    {
        // Force build target = EMBED only, regardless of what was passed in.
        if (($build_target & BUILD_TARGET_EMBED) !== BUILD_TARGET_EMBED) {
            logger()->warning('iOS target only supports embed SAPI - forcing BUILD_TARGET_EMBED.');
            $build_target = BUILD_TARGET_EMBED;
        }
        // Strip any other SAPI flags that may have been requested.
        $build_target = BUILD_TARGET_EMBED;

        $this->emitPatchPoint('before-php-buildconf');
        SourcePatcher::patchBeforeBuildconf($this);

        shell()->cd(SOURCE_PATH . '/php-src')->exec(getenv('SPC_CMD_PREFIX_PHP_BUILDCONF'));

        $this->emitPatchPoint('before-php-configure');
        SourcePatcher::patchBeforeConfigure($this);

        // Apply iOS-specific source patches that the macOS builder skips.
        self::patchPhpSourceForIOS();

        $phpVersionID = $this->getPHPVersionID();
        if ($phpVersionID < 80100) {
            throw new WrongUsageException('iOS target requires PHP 8.1+ (8.4 recommended).');
        }

        $config_file_path = $this->getOption('with-config-file-path', false) ?
            ('--with-config-file-path=' . $this->getOption('with-config-file-path') . ' ') : '';
        $config_file_scan_dir = $this->getOption('with-config-file-scan-dir', false) ?
            ('--with-config-file-scan-dir=' . $this->getOption('with-config-file-scan-dir') . ' ') : '';

        // CPPFLAGS must carry the iOS target triple too: autoconf's
        // AC_CHECK_HEADERS et al. invoke `$CPP $CPPFLAGS` without CFLAGS, so
        // without an explicit --target= here the preprocessor sees an iOS
        // sysroot but a MacOSX default target and fails the sanity check.
        $iosCommonFlags = SystemUtil::getArchCFlags(SystemUtil::getArch())
            . ' -isysroot ' . SystemUtil::getSdkPath();
        $envs_build_php = MacOSSystemUtil::makeEnvVarString([
            'CFLAGS' => getenv('SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS'),
            'CPPFLAGS' => $iosCommonFlags . ' -I' . BUILD_INCLUDE_PATH,
            'LDFLAGS' => $iosCommonFlags . ' -L' . BUILD_LIB_PATH,
        ]);

        $this->seekPhpSrcLogFileOnException(fn () => shell()->cd(SOURCE_PATH . '/php-src')->exec(
            getenv('SPC_CMD_PREFIX_PHP_CONFIGURE') . ' ' .
                '--enable-embed=static ' .
                $config_file_path .
                $config_file_scan_dir .
                $this->makeStaticExtensionArgs() . ' ' .
                $envs_build_php
        ));

        $this->emitPatchPoint('before-php-make');
        SourcePatcher::patchBeforeMake($this);

        $this->cleanMake();

        logger()->info('building embed (libphp.a) for iOS slice: ' . SystemUtil::getTarget());
        $this->buildEmbedIOS();
    }

    /**
     * iOS-specific PHP source patches: stub out fork/exec/dlopen entry points
     * that PHP optimistically configures into existence on Darwin but are
     * forbidden on iOS.
     *
     * NOTE: these are minimal "compile fix" patches; the real semantic fix is
     * to disable the corresponding configure options upstream and let dead code
     * elimination handle the rest. This block is intentionally conservative.
     * Items that turn out to be necessary will be documented in IOS_BUILD_STATUS.md.
     */
    public static function patchPhpSourceForIOS(): void
    {
        $configure = SOURCE_PATH . '/php-src/configure';
        if (!file_exists($configure)) {
            return;
        }
        // Zend fiber asm selection: configure.ac maps host_os=darwin* -> mac
        // (mach-o asm) and host_os=ios falls through to the "other" branch
        // which selects _elf_gas - that fails to assemble with the Apple
        // toolchain. Inject an ios* match into the existing case statement.
        FileSystem::replaceFileStr(
            $configure,
            "case \$host_os in #(\n  darwin*) :\n    fiber_os=\"mac\" ;;",
            "case \$host_os in #(\n  darwin*|ios*) :\n    fiber_os=\"mac\" ;;"
        );
        // Force ac_cv_func_fork=no, ac_cv_func_pcntl_fork=no etc. so that
        // ext/pcntl + Zend hard-coded paths refuse to use them.
        // We append to configure rather than editing - that way reruns are idempotent.
        $marker = '# >>> iOS: disable fork/exec/dlopen autodetection <<<';
        $contents = FileSystem::readFile($configure);
        if (!str_contains($contents, $marker)) {
            $inject = "\n{$marker}\n"
                . "ac_cv_func_fork=no\n"
                . "ac_cv_func_vfork=no\n"
                . "ac_cv_func_pcntl_fork=no\n"
                . "ac_cv_func_dlopen=no\n"
                . "ac_cv_func_dlsym=no\n"
                . "ac_cv_func_dlclose=no\n"
                . "ac_cv_func_system=no\n"
                . "ac_cv_func_exec=no\n"
                // posix_spawn_file_actions_addchdir_np exists in the macOS SDK
                // but is marked __API_UNAVAILABLE(ios) so the compiler refuses
                // when --target=arm64-apple-ios* is set. Force the cache var
                // off so proc_open.c falls back to its non-spawn pathway
                // (which is also gated by fork=no above).
                . "ac_cv_func_posix_spawn_file_actions_addchdir_np=no\n"
                . "ac_cv_have_decl_posix_spawn_file_actions_addchdir_np=no\n"
                . "# <<< iOS: end disable <<<\n";
            // Inject right after the autoconf-generated `# Initialize some
            // variables set by options.` marker (line ~1316). That's after the
            // shell-tracing setup but before any ac_cv_func_* probes.
            $pattern = '/^(# Initialize some variables set by options\.)/m';
            $patched = preg_replace($pattern, $inject . '$1', $contents, 1);
            if ($patched === null || $patched === $contents) {
                // Fallback: prepend to the file body after the shebang.
                $patched = preg_replace('/^(#!\/bin\/sh.*\n)/', '$1' . $inject, $contents, 1);
            }
            if ($patched !== null && $patched !== $contents) {
                FileSystem::writeFile($configure, $patched);
                logger()->info('iOS: patched configure to disable fork/exec/dlopen/posix_spawn autodetection');
            } else {
                logger()->warning('iOS: failed to patch configure (no insertion point matched)');
            }
        }
    }

    /**
     * Skip the macOS embed sanity check (it runs the binary, which we can't
     * do for an iOS slice on a macOS host without a simulator harness).
     */
    public function testPHP(int $build_target = BUILD_TARGET_NONE): void
    {
        logger()->warning('iOS: skipping runtime sanity check (cannot execute iOS binaries on macOS host). Use Xcode/simulator instead.');
    }

    /**
     * iOS-specific embed build: skip the dylib step (iOS has none), produce libphp.a.
     */
    protected function buildEmbedIOS(): void
    {
        $vars = MacOSSystemUtil::makeEnvVarString($this->getIOSMakeExtraVars());
        $concurrency = getenv('SPC_CONCURRENCY') ? '-j' . getenv('SPC_CONCURRENCY') : '';

        // Tell PHP makefile not to try to build the cli or dylib targets.
        shell()->cd(SOURCE_PATH . '/php-src')
            ->exec('sed -i "" "s|^EXTENSION_DIR = .*|EXTENSION_DIR = /' . basename(BUILD_MODULES_PATH) . '|" Makefile')
            ->exec("make {$concurrency} INSTALL_ROOT=" . BUILD_ROOT_PATH . " {$vars} install-sapi install-build install-headers");

        $libphp = BUILD_LIB_PATH . '/libphp.a';
        if (!file_exists($libphp)) {
            throw new WrongUsageException("iOS embed build did not produce {$libphp}. Inspect php-src/config.log for missing symbols.");
        }
        // NB: do NOT call deployBinary() here. The macOS deployBinary() runs
        // dsymutil + strip, neither of which support .a static archives
        // (dsymutil errors out, strip on .a removes too much). The library
        // is already in place at $libphp.

        // Strip non-relevant nested archives (same trick as macOS build).
        $AR = getenv('AR') ?: 'ar';
        f_passthru("{$AR} -t " . BUILD_LIB_PATH . "/libphp.a | grep '\\.a$' | xargs -n1 {$AR} d " . BUILD_LIB_PATH . '/libphp.a');

        $this->patchPhpScripts();

        // Drop a manifest next to libphp.a so the user knows which slice this is.
        $manifest = [
            'slice' => SystemUtil::getTarget(),
            'sdk' => SystemUtil::getSdkName(),
            'arch' => SystemUtil::getArch(),
            'sdk_path' => SystemUtil::getSdkPath(),
            'deployment_target' => SystemUtil::getDeploymentTarget(),
            'php_version' => $this->getPHPVersion(),
        ];
        FileSystem::writeFile(BUILD_LIB_PATH . '/libphp.ios.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * iOS has no FrankenPHP, no fpm, no cgi, no micro: re-declare these
     * as no-ops so any accidental call is loud.
     */
    protected function buildCli(): void
    {
        throw new WrongUsageException('iOS does not support CLI SAPI.');
    }

    protected function buildFpm(): void
    {
        throw new WrongUsageException('iOS does not support FPM SAPI.');
    }

    protected function buildCgi(): void
    {
        throw new WrongUsageException('iOS does not support CGI SAPI.');
    }

    protected function buildMicro(): void
    {
        throw new WrongUsageException('iOS does not support phpmicro SAPI.');
    }

    protected function buildFrankenphp(): void
    {
        throw new WrongUsageException('iOS does not support FrankenPHP.');
    }

    private function getIOSMakeExtraVars(): array
    {
        $config = (new SPCConfigUtil($this, ['libs_only_deps' => true]))->config(
            $this->ext_list,
            $this->lib_list,
            $this->getOption('with-suggested-exts'),
            $this->getOption('with-suggested-libs')
        );
        return array_filter([
            'EXTRA_CFLAGS' => getenv('SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS'),
            'EXTRA_LDFLAGS' => '-isysroot ' . SystemUtil::getSdkPath() . ' -L' . BUILD_LIB_PATH,
            'EXTRA_LDFLAGS_PROGRAM' => '-L' . BUILD_LIB_PATH,
            'EXTRA_LIBS' => $config['libs'],
        ]);
    }
}
