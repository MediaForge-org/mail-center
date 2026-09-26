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
