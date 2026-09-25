# 07 — Repository and Deployment

This is a deployment specification, not a runnable environment. M0 creates no Dockerfiles,
Compose services, framework skeleton, manifests, lockfiles, or migrations.

## Repository boundaries

One repository contains the Laravel application, Vue SPA, tests, architecture, and Docker
configuration. Future layout extends [06](06-code-structure.md):

```text
docs/architecture/       specification and decision record
app/ database/ routes/   Laravel application, introduced in M1+
resources/              Vue/TypeScript, styles, Blade shell
tests/                  backend, frontend, integration and E2E fixtures
docker/                 PHP image, Caddy config, local test-mail configuration
compose.yaml            shared service topology
compose.dev.yaml        explicit development overrides
compose.prod.yaml       production configuration, no bind-mounted source
.env.example            names and safe placeholders only
```

Dependency manifests and lockfiles belong to the repository when introduced. Build outputs,
vendor dependencies, messages, credentials and generated analysis never do. Architecture samples
are design contracts, not evidence that commands or classes already exist.

## Gitignore

Root `.gitignore` and `.dockerignore` are installed in M0. `/graphify-out/` must remain ignored,
outside Docker build context, and absent from source imports, migrations, tests and runtime paths.
Do not read, edit or remove it for application work. `.env`, `.env.*`, private keys and secrets are
excluded. A future `.env.example` may be versioned only with synthetic placeholders; Docker
excludes it along with all environment files. Never assume an ignore rule removes already tracked
secrets. The takeover found no tracked secrets or tracked Graphify output.

The user performs Git writes and remote operations manually. Agents may inspect status, history,
tracked paths, diffs and ignore matching. No staging, committing, fetching/pulling, branch changes,
PR creation, or history changes are part of this workflow.

## Local environment (M1)

Compose provides Caddy, PHP-FPM, Horizon, scheduler, PostgreSQL, Redis for queues/locks, and a
separate Redis cache/session service. Vite runs in an explicit development service. Only the
browser entry and Vite bind to localhost; PostgreSQL/Redis ports need not be host-published.
GreenMail and Mailpit are optional dev/test profiles added with connectivity work. Test mailbox
credentials are synthetic, never real account data. Prefer test TLS; insecure transport requires
an explicit local/test flag refused in production. Internal test destinations are narrowly
allowlisted by the operator, not by per-account settings.

M1 will document build, migrations, user creation, tests and shutdown commands after the actual
files exist. The Dockerized path is the reference environment; host installations are optional.

## Production environment (M7 release gate)

Deploy one host initially. Multi-stage image builds pin supported base images and install from
Composer/npm lockfiles; the Node build stage produces Vite assets, not a production Node server.
FPM, Horizon and scheduler run the same immutable PHP image with different commands. Caddy serves
only `public/` and forwards PHP to FPM; blobs and `.env` are never under the web root. Run services
without root where supported; only the reverse proxy publishes 80/443. PostgreSQL and Redis stay
on private networks with credentials. Keep `APP_DEBUG=false` and require HTTPS/session security.

Persistent volumes: PostgreSQL data, message blobs, queue Redis persistence, Caddy certificate
state. Queue Redis uses no eviction; cache Redis has its own memory budget and cannot evict locks
or queue jobs. Sessions in cache Redis may expire/evict and cause re-login; this never loses mail.
Redis persistence improves recovery but PostgreSQL checkpoints/outboxes remain authoritative.
Use private files or a host secret manager for keys; no keys baked into images or Compose files.

Only one scheduler runs. Horizon has separate supervisors for sync, flag write-back, UI work,
sending and maintenance; limits and timeout relationships are in [03](03-synchronization.md).
Operator dashboards require authorization and must not be publicly exposed without auth. Web
health distinguishes process liveness from readiness (DB, queue and storage accessible); scheduler
and worker heartbeat age, oldest due account and outbox age indicate background health.

Deployment sequence: take a verified backup, place writes/dispatch in maintenance, drain workers
within their hard timeout, deploy image, run compatible migrations once, restart workers and
scheduler, check readiness, resume traffic. Prefer additive migrations followed by later cleanup.
An old image may be rolled back only if schema compatibility is proven; otherwise restore the
coherent backup. A stuck SMTP send becomes `unknown`, never automatically resent by deployment.

## Backup, restore and retention

Baseline target: daily encrypted off-host backup (RPO up to 24 hours), restore within four hours
on the reference host; validate against real dataset size before production. These are targets,
not measured guarantees. Keep seven daily and four weekly snapshots; the operator may override.

For the initial coherent backup procedure, enter maintenance, stop dispatch/GC, drain workers and
pause mutations. Back up PostgreSQL, the complete blob volume, versioned deployment configuration,
and required APP_KEY/credential keyring. Keys are backed up separately with restricted access;
both sets are necessary for recovery. Record checksums and image/schema version. Resume services
after the snapshot is complete. A live database dump plus an unrelated blob copy is insufficient.

Restore onto an isolated host with outbound IMAP/SMTP blocked. Restore matching DB and blobs,
install matching keys/image, verify referenced blob hashes, auth and local metadata, then reset
stale run/lease state. Rebuild disposable search indexes if needed. Re-enable sync explicitly;
inspect pending flag writes and unknown sends before network egress. Conduct a restore drill
before M7 release and after material schema/storage changes. Redis need not be restored: sessions
can expire, durable work is redispatched. Real restored credentials must never reach CI.

Raw MIME and attachment blobs remain while referenced by retained messages/drafts. Local Trash
has no automatic expiry initially. M7 purge keeps identity tombstones so remote content does not
immediately return; its retention and recovery behavior must be specified before implementation.
Sync-run diagnostics expire after 30 days; operation undo data after seven days; audit metadata
is append-only in v1 and access-controlled. Warn operators that backups may retain purged content
until their retention window expires. Monitor disk growth and stop fetching before disk exhaustion.

## Operations signals

Expose account-level last complete sync, backlog/coverage, quarantined UID counts, retry reason,
oldest pending outbox age, worker heartbeat, disk usage, DB latency and blob-integrity failures.
Correlate errors with account/run/job IDs, never mail bodies, credentials or raw protocol traffic.
Default alerts: no scheduler heartbeat for three minutes, due sync backlog older than ten minutes,
unresolved flag intent older than one hour, and storage capacity above an operator-set threshold.
Authentication failures remain actionable even when automatic retries are stopped.
