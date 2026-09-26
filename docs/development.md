# MailCenter development setup (M1–M2)

The reference environment is Linux/Fedora with Docker Engine and the Compose plugin. All PHP,
PostgreSQL, Redis and Node services run in containers; Fedora host services are not needed.
Allow roughly four CPU cores and 8 GB RAM, and keep ports 8061 and 5173 free. The stack is a
local development setup; it publishes those ports on loopback and does not configure public TLS.

## First start

From a fresh clone:

```bash
cp .env.example .env
docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm --no-deps --user root vite chown 1000:1000 /var/www/html/node_modules
docker compose run --rm --no-deps vite npm ci
docker compose up -d
docker compose exec app php artisan user:create
```

The user command prompts privately for name, email and a password of at least 12 characters.
Open <http://localhost:8061/login> and sign in. The workspace shows only structural placeholders;
there is no mail connectivity in M1. Do not use actual mailbox credentials. No default login is
created by migrations or seeders.

If your host UID/GID differ from 1000, add `LOCAL_UID` and `LOCAL_GID` with those numeric values
to the ignored `.env`, and use the same UID:GID in the one-time `chown` command. The `:z` volume
labels support Fedora SELinux. The dedicated `node_modules` Docker volume preserves executable
permissions even when the workspace filesystem does not. The `postgres_data` named volume holds
the persistent development database `mailcenter`. Normal container restarts, image rebuilds,
updates and migrations retain that volume. **Never run `docker compose down -v` casually:** `-v`
deletes the PostgreSQL volume and all development users, accounts, credentials and synchronized
messages. `docker compose down` keeps the volume.

`.env` holds the local APP_KEY and development database password. It stays ignored by Git and
excluded from Docker build context. `.env.example` contains only disposable local defaults.
Change them before using a reachable or shared environment. Never put real credentials in
`.env.example` or in command arguments. `graphify-out/` stays ignored and outside build inputs.
The local stack uses loopback HTTP, so its example session cookie has `Secure=false`; require
HTTPS and `SESSION_SECURE_COOKIE=true` in any production deployment (M7).

## Verify and operate

```bash
docker compose ps
docker compose exec app php artisan migrate:status
docker compose exec app php artisan horizon:status
docker compose run --rm test php artisan test
docker compose run --rm app php vendor/bin/pint --test
docker compose run --rm app php vendor/bin/phpstan analyse --no-progress
docker compose run --rm --no-deps vite npm test
docker compose run --rm --no-deps vite npm run typecheck
docker compose run --rm --no-deps vite npm run lint
docker compose run --rm --no-deps vite npm run format:check
docker compose run --rm --no-deps vite npm run build
```

`http://localhost:8061/up` checks PHP liveness. `/ready` checks PostgreSQL and both Redis
connections; it returns 503 if any are unavailable. `/api/me` requires the authenticated session.
`/mail` redirects an unauthenticated browser to `/login`. The Horizon dashboard is disabled in M1
because there is no operator role model yet; Horizon workers still run. View service logs with
`docker compose logs --tail=100 app web vite horizon scheduler`. Stop services while keeping data
with `docker compose down`.

Backend tests use a separate `postgres-test` container and its temporary database
`mailcenter_test`; the development `postgres_data` volume is never mounted there. PHPUnit forces
the test environment, host and database even if `.env` exports development values. Its bootstrap
checks the resolved Laravel configuration and active database before `RefreshDatabase` can run.
Use `docker compose run --rm test php artisan test`, not the `app` service, for backend tests.
Frontend component tests use Vitest and jsdom. `php vendor/bin/*`
is intentional: some mounted filesystems do not preserve the executable bit on Composer scripts.
The production Vite build lands in ignored `public/build/`. The running dev server supplies HMR
assets at port 5173. The static shell follows the system light/dark preference through CSS tokens.

## Resolved M1 stack

| Component | Reference version / source |
|---|---|
| PHP | 8.5.10 FPM image, with `pdo_pgsql`, `pcntl`, `intl`, `zip`, PhpRedis 6.3.0 |
| Laravel | 13.33.0 in `composer.lock`; [Laravel 13 requirements](https://laravel.com/docs/13.x/deployment) |
| PostgreSQL | 18.6 image; [official image and PG18 volume layout](https://hub.docker.com/_/postgres) |
| Redis | 8.2.9 image, separate queue/lock and cache/session services; [official tags](https://hub.docker.com/_/redis/tags) |
| Vue | 3.5.43 in `package-lock.json` |
| Vite / TypeScript | 8.3.1 / 5.9.3 in `package-lock.json` |
| Node | 22 Alpine development image; npm dependencies installed from `package-lock.json` |
| Auth / queues | Fortify, Sanctum cookie sessions, Horizon; versions in `composer.lock` |

The lockfiles are the source of truth for PHP and JavaScript package versions. The app image is
for local development and bind mounts the source; a production image and TLS deployment belong
to M7. Static folder labels are UI examples; no folder, account or message tables exist in M1.

M1 follows [the architecture specification](architecture/README.md). The explicit M1 scope
excludes folders beyond placeholders, so the M0 milestone table's suggested folder seeding is
deferred. The official [AGPL-3.0-only license](../LICENSE) is selected by the user; the M0 license
gate is now resolved. Before public distribution, review the final dependency/license inventory.

## M2: mail accounts and synchronization

M2 adds IMAP account management and background synchronization (INBOX by default). It only reads
mail: nothing is moved, deleted, flagged or sent on the server.

### One-time setup

Credentials are encrypted with a dedicated key that must never be committed. Generate one, put it
in the ignored `.env`, and recreate the PHP services so they read it:

```bash
echo "MAIL_CREDENTIALS_KEY=base64:$(openssl rand -base64 32)" >> .env
docker compose run --rm app php artisan migrate
docker compose up -d --force-recreate app horizon scheduler
```

Losing the key makes stored mailbox passwords unreadable (re-enter them). To rotate, set a new
`MAIL_CREDENTIALS_KEY`/`MAIL_CREDENTIALS_KEY_ID` and list the old key as
`MAIL_CREDENTIALS_PREVIOUS_KEYS='{"v1":"base64:…"}'`. IMAP ports are limited to 143/993 and
loopback/private/link-local destinations are refused; to reach a mail server on a private network,
list its range in `MAIL_IMAP_PRIVATE_ALLOWLIST` (comma-separated CIDRs). TLS certificates are
always verified.

### Using it

Sign in, open **Manage accounts** in the sidebar, choose **Add account**, optionally **Test
connection**, then add it. The `scheduler` container runs `sync:dispatch-due` every minute and
Horizon (`sync` queue, connection `mail_sync`) runs one bounded job per account; the first job
downloads newest mail first and continues in follow-up jobs until the mailbox is covered. **Sync now**
queues an immediate run. Run one manually with
`docker compose exec app php artisan sync:dispatch-due`. Failures show on the account with a
sanitized message; a rejected password stops retries until a new password is saved.

### Checks

```bash
docker compose exec app php vendor/bin/pint --test
docker compose exec app php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose run --rm test php artisan test
docker compose exec vite npm run typecheck && docker compose exec vite npm run lint
docker compose exec vite npm run format:check && docker compose exec vite npm test
docker compose exec vite npm run build
```

Automated tests use a deterministic in-memory IMAP client (`tests/Support/FakeImapClient.php`) and
the library's fake stream; no real mailbox is needed. The test base class keeps the cache in memory
and disables `after_commit` for queue fakes.

## Development database backups

Before risky local changes, create a timestamped PostgreSQL custom-format dump:

```bash
sh scripts/backup-dev-db.sh
```

The script writes `backups/dev/mailcenter-YYYYMMDDTHHMMSSZ-PID.dump` outside the PostgreSQL
volume. `backups/` is Git ignored; dump files contain private mail and credentials, so keep them
local and protected. The script requests owner-only permissions and removes incomplete dumps on
failure; some mounted filesystems ignore `chmod`, so check its warning and secure the directory
at the filesystem level when that happens.

To restore a chosen backup, stop application writers, then restore into the persistent development
database. This replaces the current development database contents:

```bash
docker compose stop app horizon scheduler
docker compose exec -T postgres sh -c 'exec pg_restore -U "$POSTGRES_USER" -d mailcenter --clean --if-exists --no-owner --no-acl' < backups/dev/CHOSEN.dump
docker compose start app horizon scheduler
```

For a fresh clone, `docker compose up -d postgres` initializes the volume before backup or restore.
Never use `docker compose down -v` as a reset step.

## M3.5 plain-text reader

`GET /api/messages/{id}` returns an individual message in a `data` envelope.
Both message and account must belong to the authenticated user. Locally deleted
messages and deleted or disabled accounts return 404, matching the currently
available unified mailbox views. Paused synchronization and remotely removed mail
remain readable. Explicit access to disabled accounts is deferred with account views.

The reader uses only stored `message_bodies.text_plain`. `body_status` is
`available` or `unavailable`; missing/empty text or failed parsing returns
`text_plain: null`. No MIME parsing or HTML retrieval occurs in the request path.
Stored recipient metadata, including Bcc when present, is exposed only to the owner.

Selection is stored as `?message=<id>` on the current mailbox URL. Sidebar
navigation clears selection; browser history restores it. Selection never changes
read, star, important or done flags. The reader shows one message; conversation
thread presentation remains a later M3 item. HTML rendering, remote resources and
attachment downloads are not implemented.

## M3.6 safe HTML reader

HTML is sanitized during ingestion using `symfony/html-sanitizer` (locked at 8.1.7),
chosen because the architecture specifies its explicit element/attribute allowlist
and maintained URL sanitizers. Configuration follows the
[official Symfony documentation](https://symfony.com/doc/current/html_sanitizer.html).
The application does not parse HTML with regular expressions.

`App\Messages\EmailHtml::VERSION` identifies the stored policy. Detail JSON adds
only `html_available` and `remote_content_count`, never HTML. The authorized
`GET /api/messages/{id}/render` endpoint only serves nonempty current-version
stored output, using the same ownership/deletion/disabled-account scope as detail.
It never reads blobs or parses MIME. Unknown/stale/missing HTML yields 404 at the
render endpoint and plain-text fallback via detail.

After installing dependencies and restarting queue workers, an operator can queue
existing stale bodies with:

```sh
docker compose exec app php artisan messages:sanitize-html
```

This command was not run against development data during implementation. It scans
in bounded batches and dispatches ID-only jobs to the configured queue. Jobs read
the hash-derived private blob path, validate size and SHA-256, and update only
sanitized body fields. Missing/corrupt blobs or parser errors leave output stale.
Rerunning is safe; a policy version bump requires rerunning the command. Missing
body rows remain unavailable rather than being reconstructed by HTTP requests.
HTML inputs above 1 MiB remain plain-text-only.

M3.6 deliberately strips **all** image sources (including raster data URLs and CID).
Unlike the eventual architecture resource-reference model, no remote URL is retained
for later loading. The count covers blocked HTTP(S)/protocol-relative `img src`
values; other resource attributes are discarded without counting. Styles, classes,
IDs, active elements, relative links and unapproved attributes are removed. Links
allow only HTTP, HTTPS and mailto and force a new tab with
`noopener noreferrer nofollow`.

The reader uses an iframe with
`sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"` and
`referrerpolicy="no-referrer"`; users can switch to plain text.
The response CSP is exactly:

```text
sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'
```

Other headers: `Content-Type: text/html; charset=UTF-8`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`Cache-Control: private, no-store`. No framing-denial header is added.

Security coverage includes synthetic hostile fixtures, authorization/header tests,
ingestion and queued rebuild tests, and frontend iframe/fallback tests. Run the
additional database-free browser smoke check with:

```sh
python3 tests/browser/html-render-smoke.py
```

It requires local Google Chrome and the app container, uses the real sanitizer/CSP
in a temporary localhost harness, and checks framed/direct rendering without
automatic resource requests or active content. It is not an authenticated
end-to-end deployment test. Attachment/CID rendering and remote-image loading
remain out of scope.

## M3.7 attachment listing and downloads

The additive attachment migration must be applied through the normal deployment
migration process before using this code. It was applied only to the isolated test
database during implementation; development data was not migrated or reprocessed.

The `attachments` table stores the message reference, content-addressed blob
reference, sanitized filename, validated download MIME type, decoded byte size,
disposition, optional Content-ID, stable MIME tree path and part order. The unique
`(message_id, mime_part_path)` index also supports message-scoped lookup. Filename
trigram indexing is deferred with search. `messages.attachments_extracted_at`
marks completed extraction, including messages with no files.

Ingestion extracts files in a transaction, preserving decoded binary bytes without
charset conversion. Blobs use the existing SHA-256 store and are deduplicated.
Extraction is bounded by the configured message byte limit (also the total decoded
attachment limit), 500 visited parts and MIME depth 20. A failed extraction leaves
the raw message intact and its extraction marker unset for an operator retry.
Only non-inline attachments set `has_attachments`; stored inline parts are still
listed as downloadable files. Attached messages are single downloads, not expanded
into duplicated nested attachments.

After normal migrations and restarting queue workers, an operator can explicitly run:

```sh
docker compose exec app php artisan messages:extract-attachments
```

This scans pending messages in batches of 200 and queues ID-only jobs. Jobs verify
the raw blob SHA-256 and size before parsing. Completed messages are skipped;
row locking and a transaction make repeated or concurrent jobs safe. Missing or
corrupt raw blobs remain pending. This command was **not** run on development mail.
There is no MIME parsing in detail or download HTTP requests.

Message detail adds `attachments: [{id, filename, content_type, size_bytes, inline,
downloadable}]`, ordered by MIME part order. No blob hashes, paths, Content-IDs or
parser identifiers are exposed. The reader omits an empty attachment section and
uses normal download links to:

```text
GET /api/messages/{message}/attachments/{attachment}
```

Downloads require the current authenticated owner of both the message and account;
local deletion, deleted/disabled accounts, foreign ownership, and cross-message
attachment substitution return 404. Sync-paused accounts remain readable.
Storage paths are derived only from verified internal SHA-256 references, never
filenames or request paths. Missing/corrupt files return 404.

Responses use a streamed binary file response, verified byte length, attachment
disposition with encoded Unicode filename, `nosniff`, `private, no-store` and
`no-referrer`. Active types (HTML, SVG, JavaScript, XML) and unknown types use
`application/octet-stream`. This is MIME policy validation, not a malware verdict.
Control characters and path separators are removed from filenames, names are
bounded to 240 UTF-8 bytes, and missing names become `attachment`. Duplicate names
remain separate records. Zero-byte files are valid.

Antivirus scanning is not implemented. There are no previews, CID render routes,
inline embedding or remote-image loading. The download path verifies the full
blob hash before streaming, so very large files incur a sequential integrity read.
Background parsing reads a bounded raw message into memory; downloads do not load
the complete file into PHP memory. Attachment metadata currently shares the detail
request rather than having a separate loading/error state.

## M3.8 account mailboxes and exact counts

`/mail/account/{accountId}` opens an owned, non-deleted account through the existing
message list. `GET /api/messages?view=all&account_id={id}` uses the shared
`MessageFilter`; explicit account selection permits disabled accounts and
sync-paused accounts, excludes locally deleted mail, and retains done/read/removed
mail. Foreign, deleted or nonexistent accounts return 404. Account selection
combined with Inbox/Unread currently returns 422 rather than introducing extra
account-filtered system views. Signed cursors include the account identity and
remain bound to the authenticated user; existing unified cursor scopes are retained.

Explicit owned message detail, HTML render and attachment download now also allow
disabled accounts. This supersedes the temporary disabled-account restrictions
described in M3.5–M3.7 above. Deleted accounts/messages remain inaccessible, and
opening mail never re-enables an account or changes message flags.

`GET /api/mailbox-counts` returns:
```json
{
  "views": {
    "all": {"total": 5, "unread": 4},
    "inbox": {"total": 2, "unread": 1},
    "unread": {"total": 3, "unread": 3}
  },
  "accounts": {
    "12": {"total": 5, "unread": 4}
  }
}
```

These are message counts, not location/thread counts. All Mail includes removed
unread messages in its unread subtotal. Inbox uses the local system Inbox, not
remote folder membership, excludes done and removed mail. Unread excludes removed
mail. Unified views exclude disabled/deleted accounts; account counts include
disabled but exclude deleted accounts. All scopes enforce message/account
ownership and exclude local deletion. Empty owned accounts have real zero counts.

The endpoint uses six bounded SQL queries: three view aggregates, one system Inbox
identity lookup, one owned account ID list and one grouped account aggregate. It
never queries per account and returns `private, no-store`. Counts and feeds share
the query predicates. Separate queries reflect their execution-time database
snapshots; counts are not held as a cross-query transactional snapshot during sync.

Counts refresh on initial authenticated load, successful first-page loads (including
view/account changes and the explicit Refresh mailbox action), and account changes.
`MailPage.refreshCounts` is the hook for later mutations. There is no added polling;
background sync changes become visible on navigation or explicit mailbox refresh.
Loading/failure hides counts instead of inventing zeroes. Stale count/list responses
are ignored. Account management remains at `/mail/accounts`.

### Query-plan evidence

An isolated test fixture creates 100,000 synthetic messages plus one seed message,
including a mailbox whose messages precede 95,000 newer messages in another account.
Before the account-feed index, its first page scanned past 95,000 unrelated rows
and took 33.95 ms locally. With the index, it read 51 rows in 0.23 ms. Added only:

```sql
CREATE INDEX messages_account_feed_idx
ON messages (mail_account_id, sort_date DESC, id DESC)
WHERE deleted_at IS NULL;
```

The final fixture also populates Inbox and read states. Measured exact aggregates:
All Mail 37.78 ms, Inbox 36.28 ms, Unread 33.77 ms, grouped accounts 38.41 ms.
These are local synthetic timings, not production guarantees. Exact counts require
scanning matching rows; selective/cached counter redesign is deferred until actual
scale warrants it. Existing Inbox/Unread indexes remain; no speculative count
indexes were added.

`MailboxPlanTest` records EXPLAIN ANALYZE/BUFFERS output in the ignored
`storage/logs/mailcenter-m38-plans.json` using only the guarded isolated test DB.
The new index migration has **not** been applied to persistent development data.
Normal deployment migrations are required separately. No reprocessing is needed.

## M3.9 local read state and optional Seen mirroring

`PATCH /api/messages/{id}/read` accepts a required boolean `is_read`. It returns
`data: {id, is_read, read_writeback}`. Ownership and local deletion are enforced;
disabled owned accounts allow local organization. `OrganizationService` serializes
changes and maintains local revision/timestamp, user change version and monotonic
read-intent generation. Identical initialized state is a no-op, except an explicit
retry of a failed remote intent. Selection never marks a message read.

Account management exposes `write_back_seen`, default **false**. Enabling increments
the account mirror generation; it does not bulk-push existing local states. The next
complete remote observation initializes existing messages unless a newer explicit
local action takes precedence. Disabling supersedes queued intent. An already
issued STORE cannot be undone by cancelling a job.

With mirroring enabled, the local action and durable `remote_flag_changes` intent
commit in one transaction. Dispatch occurs after commit; dispatch failure leaves
recoverable intent and does not report the saved local action as failed. Jobs carry
only an account ID. New pending intent supersedes older pending/failed intent;
processing work can finish but generation and lease checks prevent it acknowledging
new intent. Failed intent remains visible and blocks remote reconciliation.

The `writeback` Redis/Horizon queue uses timeout 120s and retry_after 180s. Workers
claim at most 100 intents with 150s leases and a 90s work budget. Sync and writeback
share the Redis account overlap lock and PostgreSQL advisory account lock; lock
ownership is checked before remote batches. There is no network IO inside the
worker's database transactions. One connection batches compatible folder/state
operations (up to 100 UIDs), verifies UIDVALIDITY and permanent Seen permission,
sends only `UID STORE +/-FLAGS.SILENT (\Seen)`, then re-fetches exact UID flags.
Cleanup disconnects; it never CLOSEs or expunges. Every active copy must verify.
Partial completion is recorded; retries safely reapply and verify all active copies.

`php artisan writeback:sweep` is scheduled every five minutes. It redispatches
pending intent older than two minutes whose persisted backoff is due and expired
processing leases. Transient backoff starts at 60s and caps at 3600s; ten failed
attempts retain a terminal failed row. Missing unconfirmed locations remain pending;
confirmed removal supersedes with an account warning. UIDVALIDITY mismatch sends no
STORE and schedules synchronization. Authentication failure pauses remote work;
password replacement wakes pending work. Terminal failures can be explicitly retried
from the reader. Local state remains saved throughout.

Sync always tracks remote flags separately. Mirroring off preserves existing local
read choices. Mirroring on requires a complete observation of all active locations
for the current account/read generations and no unresolved local intent. Observation
timestamps and the last complete remote baseline prevent mixed old/new samples from
overwriting local state; verified writeback invalidates pre-write observations.
Removal of the last location never marks a message unread.

The reader has explicit Mark read/Mark unread controls, saving/error feedback and
remote pending/failure status. Successful mutations update loaded rows and reader,
remove read rows from Unread, and refresh authoritative sidebar/account counts.
Marking unread from the retained reader refreshes Unread in server order. API failure
leaves displayed state unchanged. Navigation remains independent.

Deployment requires the new normal migration and restarted Horizon workers; neither
was run against persistent development mail by this implementation. Keep the
scheduler running for recovery. No dependencies were added. Automated tests use only
the guarded isolated `postgres-test` / `mailcenter_test` service.

Limitations: real-provider STORE interoperability still needs an operator-controlled
smoke test; automated tests use the connector stream and fake IMAP server. Remote
completion status refreshes with reader/account reload, without live notifications.
Crash recovery can wait for the shared overlap lock to expire. This package adds the
organization change-version seam, not the future general `/changes` API or migration
of every earlier mutation to that protocol. Starred/Flagged and all other organization
mutations remain unimplemented.

## M3.10a CID / inline raster images

Sanitizer version **2** retains CID images as neutral `data-mc-resource` fingerprints,
not paths or URLs. The existing Symfony allowlist is retained; sender-supplied markers
are removed before trusted markers are created. Content-IDs are case-sensitive opaque
tokens: surrounding header brackets/space are normalized, URI percent escapes decoded
once, and controls, whitespace, URL/path delimiters and overlong identities rejected.
Unusual IDs outside the conservative token subset remain blocked. Duplicate IDs are
blocked across the bounded MIME tree, including body parts not listed as attachments.
The render step also rejects duplicate attachment identities and only matches parts
belonging to the authorized message. A hash is only an internal lookup key, never an
authorization credential or filesystem/blob reference.

The render response resolves a unique referenced inline raster to
`GET /api/messages/{message}/inline/{attachment}`. This endpoint repeats owner,
message/account deletion and exact attachment membership checks. Disabled owned
accounts retain access. The resource must be referenced by current-version sanitized
HTML and have an unambiguous Content-ID; stale, missing or inaccessible resources
return 404. No request parses raw MIME, and JSON detail responses still omit HTML.

Only `image/png`, `image/jpeg`, `image/gif` and `image/webp` are eligible. Stored type
must agree with both PHP image-header detection and fileinfo byte sniffing. Blob hash,
actual length, positive dimensions, maximum 8192 pixels per axis, 16 million pixels
and 10 MiB are checked. These are signature/dimension checks, not a full image decoder
or antivirus scan. SVG, HTML, XML, PDF, unknown types, mismatches and corrupt blobs
remain blocked. Existing attachment downloads are unchanged.

Inline responses stream the validated blob with its raster Content-Type,
Content-Length, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, and
`Cache-Control: private, no-store`; there is no attachment Content-Disposition.
The existing render CSP and iframe sandbox are unchanged. Trusted renderer CSS caps
image width to the reader. Plain-text fallback and the reader's format toggle remain.
No data-image support or HTTP/HTTPS image loading/proxying was added.

### Existing messages

No schema migration or automatic reprocessing is needed. Old sanitizer versions fall
back to plain text until the operator chooses to enqueue the existing background jobs:

```sh
docker compose exec app php artisan messages:sanitize-html
docker compose exec app php artisan messages:extract-attachments
```

The second command is only necessary when extraction is still pending. The jobs use
verified retained raw blobs; either order is safe. Missing attachments leave markers
unresolved until extraction completes. Commands were **not** run on persistent
`mailcenter` during implementation. A queue worker must be running to process them.

`python3 tests/browser/html-render-smoke.py` uses production sanitizer/resolver code
with generated local raster fixtures, without booting Laravel or connecting to a DB.
Chromium verifies actual PNG/JPEG decoding inside the sandboxed iframe and zero
requests to a separate local remote-image trap, including a CSP-only bypass probe.
Endpoint session/ownership authorization is tested separately by the isolated backend
suite; this database-free browser fixture is not a live-account end-to-end test.

## Browser-resource authentication and workspace repair

### Session boundary

The reported 401 was reproduced in Chromium with a real login: JSON fetches worked,
while no-referrer HTML, attachment and CID requests returned 401 with the same cookie.
Sanctum's SPA middleware starts a session only after an Origin/Referer match. The
reader iframe/downloads deliberately use `no-referrer`, and the render document has
that policy too, so those browser resources do not reliably enter Sanctum's stateful
API middleware.

The three GET URLs remain unchanged, but are registered in `routes/web.php` with
`web` session middleware, `auth:web` and the existing API throttle. JSON APIs retain
`auth:sanctum`. Ownership, deleted-message/account checks, exact attachment membership,
CSP, no-referrer and sandbox permissions are unchanged. Guests receive 401. No bearer
URL tokens, public resources or CSRF exemptions were added. Attachment links rely on
the server's Content-Disposition instead of forcing browser download of any response;
an expired-session JSON error is no longer saved as an attachment. The reader detects
non-HTML iframe responses and offers a restrained error/plain-text fallback.

### Scopes and layout

- Global `/mail/all`, `/mail/inbox`, `/mail/unread` combine enabled owned accounts.
- Explicit `/mail/account/{id}/all|inbox|unread` uses the same MessageFilter, includes
  disabled owned accounts, excludes deleted messages/accounts and applies the same
  view predicates. Old `/mail/account/{id}` links redirect to `/all`.
- Signed cursors remain bound to user, account and view. Scope navigation clears the
  selected-message query parameter; history and direct links restore it.
- Global navigation and account navigation are separately labelled. Account view tabs
  appear only in the selected account's header. Future views and example folders are
  hidden; no organization functionality was added.
- Rows use two compact lines with sender, subject, preview, date and subtle account/status
  indicators. The reader uses a compact subject/sender/action header and collapsible
  recipient details. Attachments follow the body. Parent-side measurement sizes the
  same-origin sandboxed HTML iframe to its content (100–2000px), with no email scripts.
- Two pointer/keyboard dividers resize sidebar/list; the reader fills remaining width.
  Arrow keys move 20px (Shift:60px), Home/End reach bounds. Local browser preferences
  survive reload. Minimum widths: sidebar 180 / list 320 / reader 360 px; sidebar max 360 / list
  max1100, also constrained by viewport. Below 900 px, horizontal overflow is intentional.

### Automatic synchronization and measurements

The scheduler checks every minute; the live account's interval is 180 seconds, matching
its default. Sync status uses authoritative account fields already exposed by the API.
Account management and account mailboxes show interval, last success, next scheduled
attempt, running, paused and backoff/authentication states. Due timestamps are labelled
as awaiting the scheduler, not promised start times. "Refresh view" reloads local API
state; "Sync now" remains the explicit remote action.

Visible workspaces observe account status every 30s (5s while a known sync runs), pause
background-tab polling, and refresh on tab return. A changed last-success timestamp
refreshes message data and authoritative counts. The former idle-state polling gap
could leave new synchronized mail invisible until navigation/manual refresh.

Read-only production diagnostics on 2026-09-26: Gmail, 23 synchronized messages, one
enabled folder. Six recent successful incremental runs had 0–1s durations at the DB's
one-second timestamp precision, with approximately 3–4 min between starts. A read-only
protocol profile under the existing Redis/advisory account locks measured:

| Stage | Milliseconds |
|---|---:|
| Connect/TLS/authenticate |752.6|
| LIST |102.8|
| EXAMINE |204.8|
| Membership SEARCH |41.2|
| Flags FETCH |103.2|
| Disconnect |1.6|
| Total, including local overhead |1219.2|

This profile fetched no bodies and changed no flags/settings/message rows. It is one
sample, not a provider performance guarantee. The complete stored runs had no new mail;
therefore ingestion, HTML sanitization and extraction did not run. The inspected driver
returns when work finishes; 240s is a ceiling, not a minimum delay. A completed one-folder
incremental pass does not need repeated folder passes. No sync optimization, checkpoint,
retry, locking, UIDVALIDITY or memory-limit change was justified by this measurement.

### Repeatable browser verification

Run after backend tests, never concurrently with a suite that resets the test DB.
This fixture uses the existing strict `tests/bootstrap.php` guard, migrates **only**
`postgres-test/mailcenter_test`, and creates synthetic users/mail in the test container.
It never loads real mailbox credentials or reprocesses development mail.

```sh
docker compose run --rm --no-deps --name mailcenter-browser-test \
  -p 127.0.0.1:8072:8072 \
  -e DB_CONNECTION=pgsql -e DB_PORT=5432 \
  -e APP_URL=http://127.0.0.1:8072 \
  -e SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8072 \
  -e SESSION_COOKIE=mailcenter_browser_test -e SESSION_SECURE_COOKIE=false \
  -e SESSION_DRIVER=file -e APP_DEBUG=false -e PHP_CLI_SERVER_WORKERS=4 \
  test sh -c 'php tests/browser/session-fixture.php && php -S 0.0.0.0:8072 -t public tests/browser/session-router.php'
# In another terminal (requires google-chrome and Python websockets):
python3 tests/browser/session-resource-smoke.py
# Stop only this disposable browser-test container afterward:
docker stop mailcenter-browser-test
```

The script tests no-referrer session resources, real attachment download bytes, real
reader iframe/CID decoding, foreign/substituted resources, logout revocation, 2560×1600
and 1440×900 layouts, and persisted divider widths. It writes a synthetic-mail screenshot
to `/tmp/mailcenter-repair-desktop.png`. The separate HTML smoke test continues asserting
zero remote-image requests. Browser/server tests supplement rather than replace the
isolated hostile-content and deletion-authorization backend tests.

## M3.10b — remote email images

Remote images remain blocked until the reader chooses **Load images** or **Always load from
[address]**. Loading can notify a sender that the message was opened. The proxy hides browser
IP/cookies/referrer, not the unique tracking URL. Exact, lowercased sender preferences are private
to each user; they do not establish identity (From addresses can be spoofed). The reader can revoke
the preference. There is no domain trust, prefetch, background fetching or shared image cache.

Sanitizer **version 3** stores `data-mc-remote` SHA-256 markers and a private JSONB
`message_bodies.remote_resources` mapping. Only the mapping contains original URLs. Neither JSON
message detail nor render output exposes that mapping. Ingestion and the existing sanitizer job
persist both atomically without network access. Old versions fall back to plain text.

The schema migration adds the mapping and `remote_content_allowlist` (unique user/address).
**Operator deployment steps, not automatically run by this implementation:**

```sh
docker compose build app
# Deploy/restart app and workers with this image using the usual update procedure.
docker compose exec app php artisan migrate --force
docker compose exec app php artisan messages:sanitize-html
```

Keep the normal queue worker running to process the sanitizer jobs. Raw MIME is read only by the
background sanitizer, never by render/proxy HTTP handlers. No attachment re-extraction is required.
The image adds PHP GD with JPEG/WebP support; non-Docker PHP installations need GD, cURL, fileinfo,
and the PHP CLI/Process support used for bounded DNS lookups. Composer package versions are unchanged.

### Authorization and network boundary

- CSRF-protected `POST /api/messages/{id}/remote-images` accepts `once`, `always`, or `block`.
  No destination or sender override is accepted; the address comes from the authorized message.
- Once grants are random, per opening, session-bound and message/user-bound, expire after ten
  minutes, and are revoked on reader navigation/unmount. The frontend does not persist them.
  Refresh requires consent again. A failed close request still leaves a bounded expiry.
- `GET /api/messages/{id}/render` stays blocked by default, including for allowlisted senders.
  The reader chooses `images=allowed` only after consent/preference. The server checks that choice.
- `GET /api/image-proxy?token=...` uses the repaired `web`/`auth:web` session boundary.
  Five-minute Laravel authenticated-encryption/HMAC tokens bind user, message, resource and grant;
  they contain no URL or credentials. Every image request rechecks current ownership, deletion,
  sanitizer version, mapping membership and consent/preference. Signatures alone grant no access.
- DNS runs in a bounded PHP subprocess; every returned A/AAAA address must be public. The request
  connects using cURL `CURLOPT_RESOLVE` to one validated address while preserving TLS hostname/SNI
  verification. Each redirect repeats validation; at most three redirects, no loops. No proxy
  environment, cookie jar, auth, referrer or client forwarding headers. Generic UA only.
- HTTP/HTTPS, ports 80/443 only; private, local, metadata, multicast, reserved/documentation and
  IPv6 transition/local ranges are refused. DNS failure, timeout and refusal fail closed.
- One five-second network deadline spans DNS and redirect hops. Streaming stops above 10 MiB;
  response headers are also bounded. Declared MIME, fileinfo, dimensions and GD decoding must agree:
  PNG/JPEG/GIF/WebP, each axis <=8192, <=16 million pixels. Obvious active payloads are rejected.
  Accepted pixels are re-encoded to PNG to strip metadata and trailing data. Animation is not
  preserved; decoded/output size and processing memory remain bounded.
- Only controlled response headers are returned: `Content-Type: image/png`, actual Content-Length,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
  `Cache-Control: private, no-store`. Upstream cookies/location/headers are not forwarded.
- Existing render CSP and sandbox are unchanged (`img-src 'self' data:`); CID and plain text remain
  supported. No remote host is ever inserted into image sources or CSP.

Failures return an empty generic 404 and leave the rest of the message usable; no automatic retry.
Network exceptions are deliberately neither reported nor logged, because URLs contain tracking
secrets. The bundled Caddy configuration does not enable access logging. If an operator enables
access logs upstream, strip query strings (especially render grants and proxy tokens); do not log
request/response bodies or upstream URLs. Never enable cURL verbose logging for this path.

### Deterministic real-browser verification

After the isolated backend suite has finished (do not share its refresh cycle concurrently), start:

```sh
docker compose run --rm --no-deps --name mailcenter-remote-browser-test \
  -p 127.0.0.1:8072:8072 \
  -e DB_CONNECTION=pgsql -e DB_PORT=5432 \
  -e APP_URL=http://127.0.0.1:8072 -e SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8072 \
  -e SESSION_COOKIE=mailcenter_remote_browser_test -e SESSION_SECURE_COOKIE=false \
  -e SESSION_DRIVER=file -e APP_DEBUG=false -e PHP_CLI_SERVER_WORKERS=4 \
  -e MAILCENTER_REMOTE_BROWSER_TEST=1 test sh -c \
  'php tests/browser/session-fixture.php && (php -S 127.0.0.1:8073 tests/browser/remote-image-source.php >/tmp/remote-source.log 2>&1 & exec php -S 0.0.0.0:8072 -t public tests/browser/session-router.php >/tmp/remote-browser.log 2>&1)'
```

In another terminal:

```sh
python3 tests/browser/remote-image-smoke.py
python3 tests/browser/html-render-smoke.py
docker stop mailcenter-remote-browser-test
```

The browser fixture router always runs the existing database safety bootstrap first. Test-only
resolver/transport bindings simulate a public address then redirect the transport to a controlled
loopback server; production has no private-network exception or testing switch. Production SSRF,
rebinding, redirect and streaming behavior is independently tested with fake resolvers and local
transport tests. Chromium instruments requests, verifies CID decode, checks default zero upstream
requests, once and allowlist/reopen behavior, and proves upstream sees only the generic server
client with no cookies, referrer, Authorization or forwarded client IP. No public internet is needed.

## M3.10c — safe inline CSS fidelity

Sanitizer version **4** adds `EmailCss`, using locked **sabberworm/php-css-parser 9.5.0**.
The [upstream parser](https://github.com/MyIntervals/PHP-CSS-Parser) supplies strict parsing and
structured declarations/values; it is not treated as a sanitizer by itself. Each inline attribute
must parse as exactly one synthetic declaration block. We walk only approved value nodes, validate
property-specific grammars, and reconstruct declarations without comments or `!important`.
Regexes validate scalar grammars and resource bounds after parsing; they do not parse CSS.

Supported: colors/background colors, local font families/sizes/weights/styles, line height,
text/vertical alignment and decoration, margins/padding (including sides), widths/min/max widths,
heights/max heights, borders (including side shorthands), radius, collapse/spacing, and a small
block/inline/table display vocabulary. Common structural elements and table columns are preserved.
Body presentation is retained on a neutral wrapper. Legacy width/height/bgcolor/color/face/align/
valign become sanitized CSS; bounded cellpadding/cellspacing/rowspan/colspan and ltr/rtl survive.

Rejected: resource URLs in every CSS property, imports/font-face, all functions except numeric
colors, expression/behavior/bindings, variables/calc/gradients, vendor extensions, positioning,
z-index, opacity/visibility tricks, negative/extreme lengths, escaped spellings, malformed CSS,
and arbitrary HTML event attributes. Inline CSS is bounded to 8 KiB/100 declarations, function
count and value-tree depth are bounded, lengths use only px/pt/em/rem/% with numeric caps.
Scripts, frames, forms/inputs/buttons, SVG, MathML, meta/base and unapproved URL attributes remain
blocked. This pass does not add stylesheet or selector processing: `<style>` blocks, classes,
media queries, web fonts, flex/grid, CSS variables and Outlook-specific extensions remain unsupported.
Malformed attributes fail closed; other content remains readable. This is practical fidelity,
not full browser CSS or pixel-perfect email-client emulation.

The standalone renderer no longer imposes table collapse, cell padding or link colors. It supplies
only fallback typography, zero body margin, long-text/pre wrapping, and image/table width constraints.
Wide tables can scroll within the iframe; they cannot widen the application. CSP, sandbox, session
authentication, CID authorization, remote consent/proxy and plain-text behavior are unchanged.
All CSS processing occurs during ingestion or the background sanitizer job, never from raw MIME
in HTTP handlers. No schema change is needed for this package.

After installing locked dependencies and deploying/restarting workers, the operator may run:

```sh
docker compose exec app php artisan messages:sanitize-html
```

Until reprocessed, older HTML falls back to plain text. This command was **not** run against
persistent development mail during implementation.

`tests/Fixtures/html/account-notification.html` is a synthetic transactional template. Run
`python3 tests/browser/html-fidelity-smoke.py` for a three-way Chromium comparison (trusted source,
former stripped presentation, v4). It checks eleven computed layout/style metrics, CID decode,
narrow-pane containment and no external requests; screenshot: `/tmp/mailcenter-html-fidelity.png`.
The database-free hostile smoke now includes CSS URL/position/expression/binding payloads.
The guarded session/remote-image fixture also uses this styled email, exercising default blocking,
consent, allowlisting/revocation and same-origin proxy fetching with a controlled upstream.
