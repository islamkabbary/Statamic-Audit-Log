<?php

namespace IslamKabbary\AuditLog\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * The running Statamic major version (adapted from silaseo/seo).
 *
 * Read from Composer's runtime API; returns null rather than guessing when it cannot be told.
 * Used only to pick CP styling: Statamic 6 dropped the v4/v5 utility classes (btn, input-text…).
 */
final class StatamicVersion
{
    private const PACKAGE = 'statamic/cms';

    private static ?int $memo = null;

    private static bool $resolved = false;

    /**
     * Handles the shapes Composer produces: "6.24.2.0", "v4.58.3", and the normalised form of a
     * dev branch, "4.9999999.9999999.9999999-dev", which is what a `4.x-dev` pin reports.
     */
    public static function parse(?string $version): ?int
    {
        if ($version === null) {
            return null;
        }

        $version = ltrim(trim($version), 'vV');

        if (str_starts_with($version, 'dev-')) {
            $version = substr($version, 4);
        }

        if (preg_match('/^(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];

        return $major > 0 ? $major : null;
    }

    public static function major(): ?int
    {
        if (! self::$resolved) {
            self::$resolved = true;
            self::$memo = self::detect();
        }

        return self::$memo;
    }

    public static function atLeast(int $major): bool
    {
        $current = self::major();

        return $current !== null && $current >= $major;
    }

    /** Test seam. */
    public static function swap(?int $major): void
    {
        self::$memo = $major;
        self::$resolved = true;
    }

    private static function detect(): ?int
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
                return self::parse(InstalledVersions::getVersion(self::PACKAGE))
                    ?? self::parse(InstalledVersions::getPrettyVersion(self::PACKAGE));
            }
        } catch (Throwable) {
            // Unknown.
        }

        return null;
    }
}
