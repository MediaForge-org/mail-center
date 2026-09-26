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
