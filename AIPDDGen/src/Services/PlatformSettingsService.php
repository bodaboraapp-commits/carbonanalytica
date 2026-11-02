<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit;
use App\Db;

/**
 * Platform-wide settings changed from the Super Admin dashboard.
 *
 * Missing schema/rows degrade to safe defaults so a half-migrated deployment
 * still serves the app.
 */
final class PlatformSettingsService
{
    public const MAINTENANCE_MODE = 'maintenance_mode';
    public const SIGNUPS_ENABLED = 'signups_enabled';

    /** @var array<string, string> */
    private const DEFAULTS = [
        self::MAINTENANCE_MODE => '0',
        self::SIGNUPS_ENABLED => '1',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;
        try {
            $rows = Db::select('SELECT setting_key, setting_value FROM platform_settings');
            foreach ($rows as $row) {
                $values[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {
            // Table not migrated yet: defaults are correct (app usable).
        }

        return self::$cache = $values;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public static function maintenanceMode(): bool
    {
        return self::get(self::MAINTENANCE_MODE) === '1';
    }

    public static function signupsEnabled(): bool
    {
        return self::get(self::SIGNUPS_ENABLED) === '1';
    }

    /** @return list<string> editable keys */
    public static function editableKeys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * Flip a boolean setting. Staff-only callers; audited.
     *
     * @return array<string, string> the new settings values
     */
    public static function setEnabled(string $actorUserId, string $key, bool $enabled): array
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown platform setting: ' . $key);
        }

        TaskCenterService::assertStaff($actorUserId);

        $value = $enabled ? '1' : '0';

        Db::execute(
            'INSERT INTO platform_settings (setting_key, setting_value, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)',
            [$key, $value, $actorUserId],
        );

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => 'admin.settings.changed',
            'target_type' => 'platform_setting',
            'target_id' => $key,
            'metadata' => ['enabled' => $enabled],
        ]);

        self::$cache = null;
        return self::all();
    }

    /** For tests: reset the in-process cache. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
