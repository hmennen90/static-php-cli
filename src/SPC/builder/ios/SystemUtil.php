<?php

declare(strict_types=1);

namespace SPC\builder\ios;

use SPC\builder\traits\UnixSystemUtilTrait;
use SPC\exception\EnvironmentException;
use SPC\exception\WrongUsageException;

/**
 * iOS / iPadOS system utilities.
 *
 * iOS builds always cross-compile from a macOS host using xcrun / xcode-select toolchain.
 * Two SDKs are relevant:
 *   - iphoneos          (real devices, arm64)
 *   - iphonesimulator   (simulator, arm64 on Apple Silicon, x86_64 on Intel)
 */
class SystemUtil
{
    /** Unix System Util compat */
    use UnixSystemUtilTrait;

    /** iPhone OS SDK identifier */
    public const SDK_DEVICE = 'iphoneos';

    /** iPhone Simulator SDK identifier */
    public const SDK_SIMULATOR = 'iphonesimulator';

    /**
     * Logical CPU count of build host (iOS builds run on macOS host).
     */
    public static function getCpuCount(): int
    {
        $cpu = exec('sysctl -n hw.ncpu', $output, $ret);
        if ($ret !== 0) {
            throw new EnvironmentException(
                'Failed to get cpu count from macOS sysctl (iOS builds run on macOS host)',
                'Please ensure you are running this command on a macOS system with sysctl available.'
            );
        }
        return (int) $cpu;
    }

    /**
     * Get the iOS slice identifier from the SPC_TARGET env var.
     *
     * Recognised targets:
     *   - ios-arm64                  -> device, arm64, iphoneos SDK
     *   - ios-simulator-arm64        -> simulator, arm64, iphonesimulator SDK
     *   - ios-simulator-x86_64       -> simulator, x86_64, iphonesimulator SDK
     */
    public static function getTarget(): string
    {
        $target = (string) getenv('SPC_TARGET');
        if ($target === '' || !str_starts_with($target, 'ios')) {
            // default to device arm64 when nothing else is set
            return 'ios-arm64';
        }
        return $target;
    }

    /**
     * Returns 'iphoneos' or 'iphonesimulator' for the active target.
     */
    public static function getSdkName(): string
    {
        return match (self::getTarget()) {
            'ios-simulator-arm64', 'ios-simulator-x86_64' => self::SDK_SIMULATOR,
            default => self::SDK_DEVICE,
        };
    }

    /**
     * Returns the architecture for the active target (arm64 / x86_64).
     */
    public static function getArch(): string
    {
        return match (self::getTarget()) {
            'ios-simulator-x86_64' => 'x86_64',
            default => 'arm64',
        };
    }

    /**
     * Resolve and cache the absolute SDK path via xcrun.
     */
    public static function getSdkPath(?string $sdk = null): string
    {
        $sdk ??= self::getSdkName();
        $cache_env = 'SPC_IOS_SDK_PATH_' . strtoupper($sdk);
        if ($cached = getenv($cache_env)) {
            return $cached;
        }
        $path = exec('xcrun --sdk ' . escapeshellarg($sdk) . ' --show-sdk-path 2>/dev/null', $out, $ret);
        if ($ret !== 0 || !$path || !is_dir($path)) {
            throw new EnvironmentException(
                "Failed to locate iOS SDK '{$sdk}' via xcrun.",
                'Please install Xcode + command-line tools and run `xcode-select --install`.'
            );
        }
        putenv("{$cache_env}={$path}");
        return $path;
    }

    /**
     * Resolve a toolchain binary (clang, ar, ld...) for the active SDK.
     */
    public static function findSdkTool(string $tool, ?string $sdk = null): string
    {
        $sdk ??= self::getSdkName();
        $path = exec('xcrun --sdk ' . escapeshellarg($sdk) . ' --find ' . escapeshellarg($tool) . ' 2>/dev/null', $out, $ret);
        if ($ret !== 0 || !$path || !is_file($path)) {
            throw new EnvironmentException(
                "Failed to find '{$tool}' in iOS SDK '{$sdk}'.",
                'Please install Xcode + command-line tools.'
            );
        }
        return $path;
    }

    /**
     * iOS deployment target version (LLVM availability check).
     */
    public static function getDeploymentTarget(): string
    {
        return getenv('IPHONEOS_DEPLOYMENT_TARGET') ?: '14.0';
    }

    /**
     * Get target triple + version-min flags for the active slice.
     */
    public static function getArchCFlags(string $arch): string
    {
        return match ($arch) {
            'arm64', 'aarch64' => '--target=arm64-apple-ios' . self::getDeploymentTarget()
                . (self::getSdkName() === self::SDK_SIMULATOR ? '-simulator' : ''),
            'x86_64' => '--target=x86_64-apple-ios' . self::getDeploymentTarget() . '-simulator',
            default => throw new WrongUsageException('unsupported iOS arch: ' . $arch),
        };
    }
}
