-- ---------------------------------------------------------------------------
-- Email OTP verification (sign-up verification and sign-in second factor)
--
--  * one active code per user and purpose; a resend consumes the previous one
--  * only the SHA-256 hash of the six-digit code is stored
--  * attempts are counted per code so a stale code cannot be brute-forced
--  * email_outbox gains the provider id, so delivery can be traced at Resend
-- ---------------------------------------------------------------------------

CREATE TABLE otp_codes (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    email VARCHAR(254) NOT NULL,
    purpose ENUM('signup', 'signin') NOT NULL,
    code_hash CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME(3) NOT NULL,
    consumed_at DATETIME(3) NULL,
    requested_ip VARCHAR(45) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_otp_codes_user_purpose (user_id, purpose, consumed_at),
    KEY idx_otp_codes_expires (expires_at),
    CONSTRAINT fk_otp_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_outbox
    ADD COLUMN provider_message_id VARCHAR(120) NULL AFTER error;
