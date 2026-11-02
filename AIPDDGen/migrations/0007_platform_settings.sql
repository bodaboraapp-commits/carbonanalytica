-- ---------------------------------------------------------------------------
-- Platform settings controlled from the Super Admin dashboard.
--
-- Key/value flags stored in the database so a toggle in the staff console takes
-- effect immediately on the live app (no file edits, no deploy):
--   maintenance_mode  0|1  non-staff visitors see a 503 maintenance page
--   signups_enabled   0|1  public /signup is closed when 0
--
-- Missing rows fall back to the defaults in PlatformSettingsService.
-- ---------------------------------------------------------------------------

CREATE TABLE platform_settings (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    updated_by_user_id CHAR(36) NULL,
    CONSTRAINT fk_platform_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('maintenance_mode', '0'),
    ('signups_enabled', '1');
