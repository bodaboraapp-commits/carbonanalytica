-- ---------------------------------------------------------------------------
-- Sign-in two-factor preference (product-owner request)
--
-- Email OTP for the second factor is now OFF by default: a correct password
-- creates the session directly. A platform admin can switch it on for their own
-- account from the Action Center; when enabled, the next sign-in asks for a
-- 6-digit code.
--
-- Sign-up verification still uses a one-time code (it proves the email address),
-- which is independent of this sign-in preference.
-- ---------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN otp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at;
