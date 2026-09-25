# 05 — Security Model

Threat model in one sentence: the application holds credentials to many mailboxes and renders
attacker-controlled content (every inbound email is attacker-controlled), so the two primary risks
are **credential exposure** and **script/content execution from email HTML**, followed by account
takeover of the application itself and SSRF via image proxying.

## 1. Application authentication and sessions
- Fortify (headless) for login, logout, password reset, password confirmation, TOTP 2FA.
- **Registration disabled.** Users are created with `php artisan user:create` (self-hosted,
  single-server product). Revisit with OD-1.
- Sanctum SPA (stateful) authentication: session cookie `HttpOnly`, `Secure`, `SameSite=Lax`;
  CSRF via `XSRF-TOKEN` cookie + header; sessions stored in Redis; session regenerated on login.
- Rate limiting: login 5/min per email+IP; API default 600/min per user; connection-test endpoint
  10/min.
- 2FA: deferred by the M2 implementation brief; schema support exists, but no application 2FA
  flow is enabled in M2. Operators must restrict access to the local instance and use a strong
  password. Require a separate 2FA security gate before exposing real credentials on a public
  deployment. This is a known reduction from the original M0 M2 gate, not an implicit approval
  to deploy without 2FA.
- Sensitive actions (replace account credentials, delete account, purge) require recent
  password confirmation (Fortify `password.confirm`, 15 min).

## 2. Authorization
- Every model reachable by the API is user-owned; policies check `user_id` ownership.
- Controllers resolve records through user-scoped queries (`$user->messages()->findOrFail()`),
  never unscoped `Model::find()`. Bulk endpoints re-validate that **all** ids belong to the user
  (count check in the same query).
- Feature tests for every endpoint include a "user B cannot access user A's resource" case.

## 3. Mail credentials
- Stored only in `mail_account_credentials.ciphertext`, encrypted with a **dedicated** Laravel
  `Encrypter` (AES-256-GCM) keyed by `MAIL_CREDENTIALS_KEY` (separate from `APP_KEY`, so rotating
  the app key doesn't destroy credentials and one leaked key doesn't expose both). `key_id` enables
  rotation: `credentials:rotate` re-encrypts with the new key; old keys stay configured
  (`MAIL_CREDENTIALS_PREVIOUS_KEYS`) until rotation is complete.
- Only `CredentialVault` decrypts; decrypted values are passed as `#[\SensitiveParameter]` and
  held in a small value object whose `__toString`, `__debugInfo`, and `jsonSerialize` are redacted.
- Write-only API: credentials can be set/replaced, never read. API resources for accounts expose
  `has_password: true` only. Credentials never appear in API responses, logs, exceptions, job
  payloads (jobs carry `accountId` only), `sync_runs`, audit context, or Horizon.
- Error responses/logs use allowlisted codes and application-authored descriptions. Raw library
  exception text, protocol transcripts, request bodies, and DSNs are not logged; heuristic secret
  redaction alone is insufficient. Disable production debug output and redact secret HTTP fields.
- **TLS required**: `security` is `tls` (implicit) or `starttls`; plaintext connections are not
  offered (dev servers use TLS or a dev-only env flag `MAIL_ALLOW_INSECURE_DEV=true` that is
  refused when `APP_ENV=production`). Certificate and hostname verification are mandatory in
  production. Private self-hosted certificates use an operator-configured CA bundle (OD-11).
- The key lives in the server's `.env` (never committed); backups of the database without the key
  are useless for credential recovery by design. Document key backup separately.

Credentials inevitably reach the authenticated IMAP/SMTP transport in memory; the vault controls
decryption, not an impossible guarantee that no other code sees plaintext. Protect APP_KEY and
Fortify recovery/2FA material too. Encrypted credentials do not encrypt messages or attachments:
use host-volume and backup encryption, least-privilege service users, and restricted blob access.

Connection settings and tests are also SSRF surfaces. The operator controls allowed destination
ports (993/143 for IMAP, 465/587 for SMTP by default) and explicit private-network exceptions.
Resolve and validate IPv4/IPv6 targets for every connection, pin permitted addresses while retaining
the original hostname for TLS validation, and block loopback, metadata, link-local and private
networks unless a narrow operator allowlist permits the mail server. Users cannot relax this policy.
STARTTLS failure must abort before authentication; no plaintext downgrade. Test jobs expire their
encrypted temporary settings after ten minutes and return sanitized results only.

## 4. HTML email rendering

Defense in depth — every layer assumes the others might fail.

### 4.1 Server-side sanitization (at ingestion, versioned)
`symfony/html-sanitizer` with an explicit allowlist:
- Allowed: common text/layout/table elements, `img`, `a`. Strip `style`, `class`, and IDs in v1;
  use trusted renderer styles. CSS support needs a separately reviewed parser/sanitizer (OD-9).
- Removed: `script`, `iframe`, `object`, `embed`, `form`, `input`, `button`, `meta`, `link`,
  `base`, `svg`, `math`, event-handler attributes, `javascript:`/`data:` URLs in links,
  `srcset`, `<style>` blocks (M3 keeps them removed; revisit with a CSS sanitizer if rendering
  fidelity suffers — see OD-9).
- Links: rewritten to `target="_blank" rel="noopener noreferrer nofollow"`; only `http`, `https`,
  `mailto` schemes.
- Images:
  - `cid:` → an authorized attachment route only for a unique matching MIME part; never a path
    supplied by the sender. Inline raster images must pass type and dimension validation.
  - Remote `http(s)` → moved to `data-remote-src`, `src` replaced by a placeholder;
    `remote_content_count` records how many were blocked.
  - `data:` images allowed only for raster types (png, jpeg, gif, webp) under a size cap.
- Remove all unapproved URL-bearing attributes, including background, poster, ping, srcset,
  SVG references and relative URLs. Only server-issued inline/proxy routes become image sources.
- Output stored in `message_bodies.html_sanitized` with `sanitizer_version`; a version bump causes
  queued re-sanitization from the raw blob. Until current-version output exists, serve escaped
  plain text; never serve known-stale HTML and never parse attacker MIME in an HTTP request.
  Ingestion sanitizes into neutral attachment/resource references; rendering resolves authorized
  routes after message IDs exist. Do not persist expiring signed URLs in cached HTML.

### 4.2 Isolated rendering
- HTML is served by `GET /api/messages/{id}/render?images=blocked|allowed` as a standalone
  document, displayed in:
  `<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer">`
  - **no `allow-scripts`**, no `allow-forms`, no `allow-top-navigation`. `allow-same-origin` lets
    the parent measure height but is not a separate-origin security boundary.
- Response headers on `/render`:
  - `Content-Security-Policy: sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'`
  - `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
    `Cache-Control: private, no-store`.
  The CSP is the network backstop: even if the sanitizer misses a remote reference, the browser
  refuses to load it (remote images reach the page only via the same-origin proxy).
  The response-level sandbox also applies when the render URL is opened directly. Generic
  middleware must not add `X-Frame-Options: DENY` to this route; only the shell denies framing.
- Plain-text mail: rendered as escaped text by Vue (never `v-html`), links auto-detected client-side.
- The SPA never uses `v-html` for email content. ESLint rule `vue/no-v-html` is an error.
- Hardening option (M7, OD-8): serve `/render` and attachments from a separate origin
  (e.g. `usercontent.<domain>`) so a sanitizer+CSP failure still cannot reach app cookies.

### 4.3 Remote content and privacy
- Remote images are **blocked by default**. The reader shows "N remote images blocked — Load
  once / Always load from this sender".
- When allowed, images are fetched through `GET /api/image-proxy?token=<signed-resource>`:
  - Require session authorization and a short-lived HMAC token bound to user, message, resource
    identifier, and expiry. Resolve the recorded URL server-side. Issue tokens only after explicit
    load consent or a sender preference; a signature alone is not authorization. Redact query
    strings from access logs. Never accept an arbitrary caller-supplied destination URL.
  - SSRF protection: only `http(s)`, ports 80/443, DNS resolved once and the IP checked against
    private/loopback/link-local/metadata ranges (IPv4 and IPv6), request pinned to that IP, no
    redirects to disallowed IPs (max 3 redirects, each re-checked).
  - Limits: 5 s timeout, 10 MB max, `Content-Type` must be a raster image type (no SVG),
    sniff/decode raster bytes, enforce pixel/dimension caps and reject mismatched MIME or polyglots;
    response re-served with `nosniff` and a fixed content type; no cookies, auth or referrer forwarded;
    generic User-Agent.
  - This hides the user's IP and cookies from senders; it does not hide the *fact* that the mail
    was opened (tracking pixels still fire when the user chooses to load images). That is why
    blocking is the default.
- Sender allowlist: `remote_content_allowlist` (user_id, address) table added in M3. Sender addresses
  can be spoofed; UI explains this preference is not proof of identity. "Load once" applies only
  to the current message-opening session. No prefetch or background image loading. Remote links
  are user-initiated navigation and may reveal their IP; the image proxy is not a link proxy.

## 5. Attachments
- Downloads via `/api/attachments/{id}/download` with
  `Content-Disposition: attachment; filename*=UTF-8''…`, `X-Content-Type-Options: nosniff`, and the
  stored content type — except types that could execute in the browser (`text/html`,
  `image/svg+xml`, `application/xhtml+xml`, JavaScript, XML), which are served as
  `application/octet-stream`.
- Inline preview only for validated raster images. PDFs and other active or ambiguous formats
  download as attachments in v1; a future isolated previewer requires its own security review.
- Filenames are sanitized for the header and never used as filesystem paths (storage uses
  sha256 paths).
- Virus scanning is out of scope initially; the blob store design allows adding a scanner later.

## 6. MIME parsing robustness
- Parsing runs in the worker, never in a web request. Limits: max message size
  (`max_message_bytes`), max MIME depth (20), max parts (500), max header size; exceeding limits
  yields `parse_status = partial` with raw preserved.
- Worker memory limit + recycling (`--max-jobs`) contain parser pathologies.

## 7. Audit
`audit_events` records: login success/failure (user id when known), 2FA changes, credential
creation/replacement, account creation/deletion/purge, sync enable/disable, folder deletion,
tag deletion, bulk moves/deletes/restores, local purge, UIDVALIDITY resets, and settings that
affect remote writes (`write_back_*`). Viewable in settings (M7). Append-only.

## 8. General web security
- Security headers for the SPA: CSP (`default-src 'self'`, no inline scripts; Vite dev server
  allowed only in local env), `X-Frame-Options: DENY` equivalent via `frame-ancestors 'none'` for
  the app shell, HSTS in production, `Referrer-Policy: strict-origin-when-cross-origin`.
- Mass assignment: form requests + explicit `$fillable`; local-organization columns are not
  fillable from generic endpoints, only through dedicated action endpoints.
- Dependencies: `composer audit` and `npm audit` in CI.
- Logs: structured, no message bodies, no credentials; email addresses allowed in debug logs only
  in non-production.

Sanitizer configuration and URL handling must be checked against the selected version's
[official Symfony HTML Sanitizer documentation](https://symfony.com/doc/current/html_sanitizer.html).
Library selection alone does not establish safe CSS or email rendering; adversarial fixtures and
browser network assertions are M3 release gates ([08](08-testing-strategy.md)).
