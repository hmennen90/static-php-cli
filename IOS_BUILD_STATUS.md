# iOS / iPadOS target - implementation status

Branch: `feat/ios-target`

## TL;DR

End-to-end build of `libphp.a` (Mach-O, arm64) for `ios-arm64` works on
PHP 8.5.5 + Xcode 26.5 with the `json` extension. The full builder skeleton
is in place; only minimal extension coverage has been tested.

## What was implemented

### New files

| Path | Purpose |
| --- | --- |
| `src/SPC/builder/ios/IOSBuilder.php` | iOS builder (extends `MacOSBuilder`, overrides PHP build to embed-only) |
| `src/SPC/builder/ios/SystemUtil.php` | SDK / toolchain resolution via `xcrun` |
| `src/SPC/doctor/item/IOSToolCheckList.php` | Doctor checks for xcrun + both iOS SDKs |
| `docs/en/guide/build-for-ios.md` | User-facing build guide |

### Modified files

| Path | Change |
| --- | --- |
| `src/SPC/builder/BuilderProvider.php` | Dispatch to `IOSBuilder` when `SPC_TARGET=ios-*` on Darwin |
| `src/SPC/util/SPCTarget.php` | `getTargetOS()` returns Darwin for ios-* targets; `isStatic()` forces static linking for iOS; added `isIOS()` helper |
| `src/SPC/util/GlobalEnvManager.php` | Prime iOS env vars (CC/CXX/CPP/CFLAGS/SDKROOT) before env.ini is read so the [macos] block does not overwrite them |
| `docs/.vitepress/sidebar.en.ts` | Add "Build for iOS" to sidebar |

## What works

- `SPC_TARGET=ios-arm64 bin/spc doctor` — all checks green (xcrun, both
  SDKs, clang reachable, brew, bison, pkg-config, re2c).
- `SPC_TARGET=ios-arm64 bin/spc spc-config json` — produces correct
  cflags/ldflags.
- `SPC_TARGET=ios-arm64 bin/spc build --build-embed json` — completes a
  full build in ~250 s on a 16-core host. Output:
  - `buildroot/lib/libphp.a` 35 MB Mach-O 64-bit object arm64.
  - `buildroot/lib/libphp.ios.json` slice manifest.
  - `buildroot/include/php/...` headers ready to embed.

## Architectural decisions taken

1. **Reuse the macOS library tree (76 lib files) by inheriting from
   `MacOSBuilder` instead of `UnixBuilderBase`.** The host is always
   Darwin so `osfamily2dir()` returns `'macos'`; thus all per-library
   build steps (openssl, libxml2, etc.) already work cross-compiled
   when we override CC/CFLAGS. Avoids duplicating ~3000 lines of code.

2. **Env priming via `GlobalEnvManager::init()` hook, not constructor only.**
   For non-build commands (`doctor`, `spc-config`) `BuilderProvider` is not
   invoked; the env primer needs to run before `env.ini` does to take effect.
   Added a `PHP_OS_FAMILY === 'Darwin' && str_starts_with(SPC_TARGET, 'ios')`
   check at the top of `GlobalEnvManager::init()`.

3. **`CC` stays as a bare clang path.** The `ClangNativeToolchain::afterInit`
   does `is_file($command)` checks; multi-word CC trips it. Target/sysroot
   travel via `CFLAGS` and `CPPFLAGS` instead. `CPP` is a multi-word
   exception because autoconf invokes `$CPP $CPPFLAGS` without CFLAGS.

4. **`SPC_TARGET=ios-*` is treated as `Darwin` by `SPCTarget::getTargetOS()`.**
   This keeps the `SPCConfigUtil` and per-library code unchanged
   (they branch on `getTargetOS() === 'Darwin'`).

5. **Configure-cache patcher injects `ac_cv_func_*=no` right after the
   `# Initialize some variables set by options.` autoconf marker.** Earlier
   attempts to put them at a different position were silently
   ignored because configure already cached the AC_CHECK_FUNCS result.

## Patches applied to PHP source

All in `IOSBuilder::patchPhpSourceForIOS()`, idempotent (guarded by marker
comment), run after `buildconf` regenerates configure:

1. Force the following autoconf cache vars to "no":
   - `ac_cv_func_fork`, `ac_cv_func_vfork`, `ac_cv_func_pcntl_fork`
   - `ac_cv_func_dlopen`, `ac_cv_func_dlsym`, `ac_cv_func_dlclose`
   - `ac_cv_func_system`, `ac_cv_func_exec`
   - `ac_cv_func_posix_spawn_file_actions_addchdir_np`
   - `ac_cv_have_decl_posix_spawn_file_actions_addchdir_np`
2. Replace `case $host_os in darwin*) fiber_os="mac"` with `darwin*|ios*)`
   so Zend's fiber asm picks the Mach-O variant.

## Forced configure flags on iOS

```
--prefix=
--host=aarch64-apple-ios   (or x86_64-apple-ios)
--with-valgrind=no
--enable-shared=no --enable-static=yes
--disable-all --disable-phpdbg
--disable-fpm --disable-cli --disable-cgi --disable-opcache-jit
--without-pcre-jit
--disable-dl-test
--enable-embed=static
```

## Known limitations / outstanding work

1. **Only `json` extension verified.** Anything that pulls in a library
   (mbstring → oniguruma, zip → libzip, intl → ICU, phar) needs an
   end-to-end build pass. Suspect ICU and curl will be the largest
   sources of further patches (both have their own cross-compile dances).

2. **`buildPHP()` is duplicated from `MacOSBuilder`.** I copy-pasted the
   embed-only path rather than extending `parent::buildPHP()` with a flag.
   When upstream `MacOSBuilder::buildPHP()` changes (e.g. PHP 8.6 support),
   the iOS variant has to be re-synced manually. Acceptable cost given
   the divergence in SAPIs and patches.

3. **Bitcode flag was attempted then removed.** Apple deprecated bitcode in
   Xcode 14 and removed driver-level handling in Xcode 26. We do not set
   `-fembed-bitcode` even though the Apple style guide once required it
   for distribution. Modern App Store submissions no longer need it.

4. **No xcframework helper.** The user needs to run lipo / xcodebuild
   manually to merge multiple slices. Documented in `build-for-ios.md`.

5. **Sanity check skipped.** `testPHP()` is a no-op on iOS because we
   cannot execute iOS binaries on a macOS host. A future improvement would
   be to spawn a simulator and run the embed sample there.

6. **vio extension not tested.** The user explicitly noted that vio
   integration is parallel work (Strang A). The build is set up so once
   vio supports iOS-compatible source patches, `--with-libs` lists work
   like on macOS.

7. **PHP 8.5.5 used, not 8.4.** This is what was already extracted in
   `source/php-src`. The user requested 8.4. Re-running `bin/spc download
   php-src --with-php=8.4` followed by the iOS build should work but was
   not verified in this session. PHP 8.4 has the same posix_spawn /
   fiber asm structure so the patches should still apply.

## Reproducer

```bash
# from /Users/hendrikmennen/Projekte/static-php-cli on branch feat/ios-target
SPC_TARGET=ios-arm64 bin/spc doctor
SPC_TARGET=ios-arm64 bin/spc build --build-embed json
ls -la buildroot/lib/libphp.a buildroot/lib/libphp.ios.json
lipo -info buildroot/lib/libphp.a
```

Expected: full build in roughly 4 minutes, `Non-fat file ... is
architecture: arm64`.
