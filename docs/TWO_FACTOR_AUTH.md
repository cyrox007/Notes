# TOTP two-factor authentication

Workspace Organizer implements standards-based TOTP compatible with Google Authenticator and other RFC 6238 applications.

The source archive supplied for evaluation was used only to understand the expected user flow. The Workspace implementation is independent and keeps the runtime vendor-free.

## Security model

- each user gets a unique 160-bit Base32 TOTP secret;
- the secret is encrypted at rest with the existing application data-encryption primitive and user-specific AAD;
- the default time step is 30 seconds and the verifier accepts at most one adjacent step of clock drift;
- a successfully used TOTP counter is persisted so the same code cannot be replayed;
- the password step does not create an authenticated session when TOTP is enabled;
- the second-factor challenge has its own rate-limit bucket;
- ten high-entropy one-time recovery codes are issued at enrollment and stored only as SHA-256 hashes;
- enabling, disabling, regenerating recovery codes, successful challenges and failed challenges are security events;
- enrollment and one-time recovery-code display use no-store responses.

## Enrollment

Open Profile → Two-factor authentication.

1. Enter the current account password and start setup.
2. Add the displayed key to an authenticator app. On a mobile device the `otpauth://` link can hand the account directly to a compatible app.
3. Enter the current six-digit code and the account password to confirm setup.
4. Save the recovery codes shown once after confirmation.

No external QR/image service is used, so the TOTP secret is not disclosed to a third party. A local QR renderer can be added later without changing the server-side contract.

## Login

After a correct username/password pair, accounts with TOTP enabled are moved into a short-lived pending challenge rather than an authenticated session. A valid current TOTP or unused recovery code completes the login and rotates the session identifier.

## Recovery and reset

A recovery code can replace a TOTP during login and is destroyed immediately after use. A signed-in user can regenerate the recovery-code set after confirming the password and an existing second factor.

Disabling TOTP requires both the current password and a valid TOTP/recovery code. Administrative recovery/reset is intentionally a separate control-plane concern and should be implemented with an audited identity-verification process rather than a silent bypass.
