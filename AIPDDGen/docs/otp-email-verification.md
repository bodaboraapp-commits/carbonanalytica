# Email one-time codes (OTP) and Resend

**Status:** implemented (migrations `0005_otp_verification.sql`, `0006_otp_preference.sql`).
**Scope:** six-digit email codes for **sign-up email verification** (always) and as an **optional second factor on sign-in** (per account, off by default), delivered through the [Resend](https://resend.com) HTTP API.

A correct password still signs a normal account in directly. Accounts that have turned the second factor on get an emailed code instead, and no session exists until that code is verified.

---

## Flows

### Sign-up (always code-verified)

1. `POST /signup` creates the account, workspace and owner membership.
2. A code is issued (purpose `signup`) and the user is put in a **pending** state.
3. `GET /verify-otp` asks for the six digits, one per box.
4. On success: the email address is marked verified, the session is created, and the user lands on their next page.

### Sign-in

1. `POST /login` checks the password. Success is audited as `auth.signin.password_verified`.
2. If the account has **not** enabled the second factor (`users.otp_enabled = 0`, the default), the session is created immediately (`auth.signin`, method `password`) — no code is emailed.
3. If it **has** enabled it, a code is issued (purpose `signin`) and the pending state is stored; the session is created only after the code verifies (`auth.signin`, method `password+otp`).
4. A wrong password and an unknown account still return the same error.

### Second-factor preference

| Item | Value |
| --- | --- |
| Storage | `users.otp_enabled` (`TINYINT`, default **0**) |
| Who can change it | Platform staff, for their own account, from the Action Center security panel (`POST /admin/security/otp`) |
| Audit | `auth.otp.preference_changed` |
| Effect | Next sign-in for that account asks for the emailed code; turning it off restores password-only sign-in |

Sign-up verification is independent of this preference: a new account always proves its address with a code, and the code also verifies the address when the address was somehow still unverified.

### Pending state

Between the password and the code the session holds `_pending_auth` (`user_id`, `purpose`, `email`, `next`, `expires_at`, delivery info) and **no `user_id`**. Every signed-in route redirects to `/verify-otp`; `/login` and `/signup` also redirect there. The pending state expires after `auth.otp_pending_ttl` (15 minutes), and logging out clears it.

## Code rules

| Rule | Value |
| --- | --- |
| Format | 6 digits (`000000`–`999999`), generated with `random_int` |
| Storage | SHA-256 hash only (`otp_codes.code_hash`) — the plaintext exists only in the email |
| Lifetime | 10 minutes (`auth.otp_ttl`) |
| Attempts | 5 per code (`auth.otp_max_attempts`); wrong codes increment `attempts`, the fifth burns the code |
| Reuse | Single use; every active code for the user and purpose is consumed on success |
| Resend | Consumes the previous code and issues a new one; 5 per 10 minutes per user and purpose |
| Verification rate | 15 attempts per 10 minutes per user (`auth.otp_verify_limit`) |
| Comparison | `hash_equals` on the hashes |

## The code screen

Six single-digit boxes (`name="code[]"`, `autocomplete="one-time-code"` on the first, one `aria-label` per digit). The form posts all six values and the controller assembles slots 0–5 by index — form fields are not guaranteed to arrive in document order, so arrival order is never trusted. Submitting fewer than six digits fails validation before the code is checked.

`public/assets/otp.js` is a progressive enhancement: auto-advance, backspace/arrow navigation, paste distribution and numeric-only key filtering. Without JavaScript the six boxes still submit correctly (paste a code into one box and it is truncated by `maxlength`, so type or paste one digit at a time).

## Resend integration

Configuration (`.env.local` / environment):

```dotenv
EMAIL_TRANSPORT=resend
RESEND_API_KEY=...
RESEND_FROM="AI PDD Generator <noreply@carbonanalytica.com>"
```

- `App\ResendTransport` posts `{from,to,subject,text}` to `https://api.resend.com/emails` with cURL (stream wrapper fallback). The sending domain must be verified in the Resend account; a send-only API key is enough.
- `App\Mailer` records **every** message in `email_outbox` with `sent_at`, `error` and the provider's `provider_message_id`. Delivery failures are data, never exceptions, so a mail outage cannot roll back a sign-up.
- **Fixture domains never reach the provider.** `pddgen.local`, `example.com` and `example.org` (the demo account and the e2e fixtures) are recorded as "Not sent: test recipient domain"; this keeps the API key away from fake recipients.
- **Development mirror.** When a real transport is configured outside production, the body is also written to `storage/logs/mail.log`, so the code can be read on the machine that runs the app. Disabled with `APP_ENV=production`.
- If delivery could not be confirmed, the code screen says so and points to the log in development; the code itself is still valid, so the user can also request a new one.

## Routes

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/verify-otp` | Code screen (pending state required). |
| POST | `/verify-otp` | Check the code and create the session. |
| POST | `/verify-otp/resend` | Issue and email a new code. |
| GET | `/verify-email` | Legacy alias for old links; redirects into the flow. |
| POST | `/admin/security/otp` | Toggle the sign-in second factor for the signed-in staff account. |

## What did not change

- **Google sign-in** still signs in directly: Google has already verified the address, so no code is sent.
- **Password reset** stays link-based (`/reset-password?token=…`), and completing it also marks the address verified.
- **Invitations** stay link-based.

## Verification

- `tests/otp_auth_test.php` — 11 tests: sign-up verification, sign-in 2FA, code single-use, wrong code counting, five-attempt burn, expiry, resend invalidation, resend rate limit, pending state is not a session, stale pending expiry.
- `tests/otp_preference_test.php` — second factor off by default, password-only completion, staff toggle on/off with audit.
- Updated suites: `auth_signup_test`, `auth_signin_test`, `auth_verify_reset_test`, `security_test` (hashed code), `schema_test` (`otp_codes`, `users.otp_enabled`).
- `tools/e2e-smoke.ps1` — six-box render check, wrong code, incomplete code, sign-up verification, password-only sign-in, staff toggle → challenged sign-in → six-box code → session → toggle off, demo account, invitee verification preserving `next`. Codes are read from `storage/logs/mail.log` the way a developer would.
- Resend transport exercised end-to-end against a local mock endpoint: payload shape, provider id capture, error recording, fixture-domain skip, and the development log mirror (12 assertions).

## Limitations / follow-ups

- **No authenticator-app/TOTP or WebAuthn** — the optional second factor is email only.
- **No "remember this device"**: accounts with the preference on need a code on every sign-in.
- **The code appears in the email subject** (`123456 is your verification code`). It helps deliverability and scannability but means the code is visible in inbox lists; switch to a subject without the code if that tradeoff is unwanted.
- **Resend limits** (rate limits, quota) are surfaced only through the outbox `error` column, not a monitoring alert.
- **Development mail.log mirror** is off in production; a real delivery failure there leaves the user to retry, which the screen suggests.
- **Paste without JavaScript** is limited by `maxlength="1"` on each box; typing works, the JS enhancement is what makes paste seamless.
- **Bounces/complaints** are not ingested; Resend webhooks are a later integration.
