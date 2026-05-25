---
outline: 'deep'
---

# Build for iOS / iPadOS

This document describes how to build PHP as a static library (`libphp.a`)
for iOS / iPadOS apps. The library is intended to be embedded into a Swift
or Objective-C wrapper project in Xcode.

::: warning Experimental
The iOS target is experimental. It only builds the `embed` SAPI, ships
without CLI / FPM / phpmicro and disables JIT (both opcache and PCRE) because
iOS forbids `fork()`, `exec()`, `dlopen()` and dynamic code generation.
:::

## Prerequisites

- macOS host (Apple Silicon or Intel)
- Xcode (not just Command-Line Tools - the iOS SDKs are only in the full Xcode)
- `xcrun --sdk iphoneos --show-sdk-path` and `xcrun --sdk iphonesimulator --show-sdk-path` must both work
- Same tooling required by the macOS build (homebrew, bison, re2c, pkg-config)

Run the doctor to verify:

```bash
SPC_TARGET=ios-arm64 bin/spc doctor
```

## Supported slices

The iOS target is selected via the `SPC_TARGET` environment variable.

| `SPC_TARGET`            | SDK              | Arch     | Notes                                |
| ----------------------- | ---------------- | -------- | ------------------------------------ |
| `ios-arm64`             | iphoneos         | arm64    | Real devices (iPhone / iPad)         |
| `ios-simulator-arm64`   | iphonesimulator  | arm64    | Apple Silicon Mac running Simulator  |
| `ios-simulator-x86_64`  | iphonesimulator  | x86_64   | Intel Mac running Simulator          |

You build one slice at a time and combine them with `lipo` or an `.xcframework`
yourself.

## What you get

- `buildroot/lib/libphp.a` — a Mach-O static archive
- `buildroot/lib/libphp.ios.json` — manifest with slice, SDK path, arch, PHP
  version, deployment target
- `buildroot/include/...` — PHP headers for the embed API
- `buildroot/bin/php-config` — the standard PHP config helper

The default deployment target is iOS 14.0. Override with the standard Apple
env var:

```bash
IPHONEOS_DEPLOYMENT_TARGET=16.0 SPC_TARGET=ios-arm64 bin/spc build ...
```

## Minimal example

A minimal device build with only the `json` extension (always compiled in
as part of PHP core in 8.3+):

```bash
# Download PHP sources for the version you want
bin/spc download php-src --with-php=8.4

# Build libphp.a for arm64 iOS
SPC_TARGET=ios-arm64 bin/spc build --build-embed json
```

Inspect the result:

```bash
file buildroot/lib/libphp.a
# > buildroot/lib/libphp.a: current ar archive random library

lipo -info buildroot/lib/libphp.a
# > Non-fat file: buildroot/lib/libphp.a is architecture: arm64
```

## Multi-slice (`.xcframework`) workflow

Build each slice into a separate `buildroot`:

```bash
for target in ios-arm64 ios-simulator-arm64 ios-simulator-x86_64; do
    rm -rf buildroot source/php-src
    BUILD_ROOT_PATH="$(pwd)/buildroot-${target}" \
    SPC_TARGET="${target}" \
    bin/spc build --build-embed mbstring,zip,intl,phar
done
```

Then combine the two simulator slices and turn the result into an
`xcframework`:

```bash
lipo -create \
    buildroot-ios-simulator-arm64/lib/libphp.a \
    buildroot-ios-simulator-x86_64/lib/libphp.a \
    -output libphp-simulator.a

xcodebuild -create-xcframework \
    -library buildroot-ios-arm64/lib/libphp.a \
    -headers buildroot-ios-arm64/include \
    -library libphp-simulator.a \
    -headers buildroot-ios-simulator-arm64/include \
    -output libphp.xcframework
```

## What is automatically forced on iOS

Regardless of CLI flags, the iOS builder:

- Forces SAPI to `embed` (libphp.a). CLI / FPM / CGI / micro / FrankenPHP
  are not built.
- Adds `--disable-opcache-jit` and `--without-pcre-jit` (iOS forbids W^X
  code generation).
- Adds `-DTARGET_OS_IPHONE=1 -DTARGET_OS_IOS=1` to CFLAGS.
- Sets the autoconf cache variables for `fork`, `vfork`, `pcntl_fork`,
  `dlopen`, `dlsym`, `dlclose`, `system`, `exec` and
  `posix_spawn_file_actions_addchdir_np` to "no" so PHP's source code falls
  into the no-spawn / no-dl fallback paths.
- Patches Zend's fiber asm selection so `host_os=ios` uses Mach-O assembly
  files instead of the ELF variant.

## What still won't work

- Anything in PHP that hard-requires `fork()` or `proc_open()` will throw at
  runtime even though the build succeeds. The PHP API is still present.
- Extensions that pull in a `dlopen()` chain (e.g. shared extensions) are
  silently disabled.
- `phpinfo()` reports the original configure command; that's fine but it
  also reports iOS deployment target which may confuse log readers.

## Troubleshooting

If `./configure` fails inside the iOS build:

1. Check `log/php-src.config.log` for the autoconf failure.
2. Make sure `xcrun --sdk iphoneos --find clang` resolves.
3. If new symbols are unavailable in the SDK (`__API_UNAVAILABLE(ios)`),
   add their `ac_cv_func_*=no` to the iOS patcher in
   `src/SPC/builder/ios/IOSBuilder.php` (see `patchPhpSourceForIOS()`).

If make fails with `unknown directive` in `Zend/asm/*_elf_gas.S`, the
fiber-asm patch did not apply: confirm
`grep darwin\\*\|ios\\* source/php-src/configure` shows the merged case
statement.
