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
permissions even when the workspace filesystem does not. If an existing PostgreSQL volume predates
the test-database init script, create the test DB once with
`docker compose exec postgres createdb -U mailcenter -O mailcenter mailcenter_test`.

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
docker compose run --rm app php artisan test
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

The backend tests use `mailcenter_test` on real PostgreSQL and the two local Redis services; they
never use SQLite or real email. Frontend component tests use Vitest and jsdom. `php vendor/bin/*`
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
docker compose exec app php artisan test
docker compose exec vite npm run typecheck && docker compose exec vite npm run lint
docker compose exec vite npm run format:check && docker compose exec vite npm test
docker compose exec vite npm run build
```

Automated tests use a deterministic in-memory IMAP client (`tests/Support/FakeImapClient.php`) and
the library's fake stream; no real mailbox is needed. The test base class keeps the cache in memory
and disables `after_commit` for queue fakes.
