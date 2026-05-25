<?php

declare(strict_types=1);

namespace SPC\doctor\item;

use SPC\builder\traits\UnixSystemUtilTrait;
use SPC\doctor\AsCheckItem;
use SPC\doctor\CheckResult;

/**
 * Doctor checks specific to iOS / iPadOS cross-compilation.
 *
 * Only runs on macOS (iOS builds always cross-compile from Darwin).
 * Most checks are conditional on SPC_TARGET starting with 'ios'.
 */
class IOSToolCheckList
{
    use UnixSystemUtilTrait;

    #[AsCheckItem('if xcrun is available', limit_os: 'Darwin')]
    public function checkXcrun(): ?CheckResult
    {
        if (!$this->isIOSTarget()) {
            return CheckResult::ok('skipped (SPC_TARGET is not ios-*)');
        }
        if ($this->findCommand('xcrun') === null) {
            return CheckResult::fail(
                'xcrun is not on PATH. Install Xcode and run `xcode-select --install`, then ensure `xcrun` is available.'
            );
        }
        return CheckResult::ok();
    }

    #[AsCheckItem('if iOS device SDK (iphoneos) is installed', limit_os: 'Darwin')]
    public function checkDeviceSDK(): ?CheckResult
    {
        if (!$this->isIOSTarget()) {
            return CheckResult::ok('skipped (SPC_TARGET is not ios-*)');
        }
        $sdk = exec('xcrun --sdk iphoneos --show-sdk-path 2>/dev/null', $out, $ret);
        if ($ret !== 0 || !$sdk || !is_dir($sdk)) {
            return CheckResult::fail(
                'iOS device SDK (iphoneos) not found. Install Xcode (not just CLT) from the Mac App Store.'
            );
        }
        return CheckResult::ok($sdk);
    }

    #[AsCheckItem('if iOS simulator SDK (iphonesimulator) is installed', limit_os: 'Darwin')]
    public function checkSimulatorSDK(): ?CheckResult
    {
        if (!$this->isIOSTarget()) {
            return CheckResult::ok('skipped (SPC_TARGET is not ios-*)');
        }
        $sdk = exec('xcrun --sdk iphonesimulator --show-sdk-path 2>/dev/null', $out, $ret);
        if ($ret !== 0 || !$sdk || !is_dir($sdk)) {
            return CheckResult::fail(
                'iOS simulator SDK (iphonesimulator) not found. Install Xcode from the Mac App Store.'
            );
        }
        return CheckResult::ok($sdk);
    }

    #[AsCheckItem('if iOS clang is reachable via xcrun', limit_os: 'Darwin')]
    public function checkIosClang(): ?CheckResult
    {
        if (!$this->isIOSTarget()) {
            return CheckResult::ok('skipped (SPC_TARGET is not ios-*)');
        }
        $sdk = match (true) {
            str_contains((string) getenv('SPC_TARGET'), 'simulator') => 'iphonesimulator',
            default => 'iphoneos',
        };
        $clang = exec('xcrun --sdk ' . escapeshellarg($sdk) . ' --find clang 2>/dev/null', $out, $ret);
        if ($ret !== 0 || !$clang || !is_file($clang)) {
            return CheckResult::fail("clang not found in iOS SDK ({$sdk}). Reinstall Xcode CLT.");
        }
        return CheckResult::ok($clang);
    }

    private function isIOSTarget(): bool
    {
        return str_starts_with((string) getenv('SPC_TARGET'), 'ios');
    }
}
