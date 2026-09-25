# 01 — System Overview & Key Decisions

## 1. Goals that drive the architecture

1. **Unified, complete overview**: one chronological list over all accounts; "All Mail" never loses
   a message because of local organization.
2. **Data integrity over cleverness**: idempotent sync, stable identifiers, no duplicates, no
   accidental remote destruction, audit trail for destructive local actions.
3. **Speed at high volume**: tens of accounts, hundreds of thousands to low millions of messages,
   instant list navigation (keyset pagination, narrow list rows, virtualized UI).
4. **Boring, single-server operability**: one Docker Compose stack, no extra infrastructure.
5. **Extensibility without premature building**: provider drivers, search engine, rules/AI hooks
   are *seams* (interfaces/events), not implemented features.

## 2. Stack (pin exact versions at the start of M1)

| Concern | Choice | Notes |
|---|---|---|
| Language/runtime | Supported PHP release compatible with the selected Laravel release | Pure-PHP IMAP adapter; exact versions are a pre-M1 gate. |
| Backend framework | Laravel | Pin a supported stable version and dependency compatibility in M1. |
| Auth | Laravel Fortify (headless) + Sanctum SPA cookie auth | Session cookies, no tokens in JS. |
| Queues | Laravel queues on Redis + Laravel Horizon | Horizon = first-party queue supervisor/dashboard. |
| DB | PostgreSQL | Extensions: `pg_trgm`, `unaccent`, `citext`; version pinned at M1. |
| Cache/locks/sessions | Redis | No alternative server assumed compatible without testing. |
| Frontend | Vue 3 (Composition API, `<script setup>`), TypeScript strict | |
| Build | Vite + `laravel-vite-plugin` | |
| Routing/state | Vue Router, Pinia (UI state), TanStack Vue Query (server state) | |
| UI | Tailwind CSS v4, Reka UI (headless accessible primitives), lucide icons, `@tanstack/vue-virtual` | |
| MIME parsing | `zbateson/mail-mime-parser` | Robust against malformed mail; streaming. |
| IMAP client | Pure-PHP library behind our own interface. Candidates: `directorytree/imapengine`, `webklex/php-imap` | Chosen by a spike at the start of M2 (see §5, D7). |
| HTML sanitizing | `symfony/html-sanitizer` (allowlist based) | |
| SMTP | Symfony Mailer (ships with Laravel), per-account transport built at runtime | |
| Tests | Pest (PHP), Vitest + Vue Test Utils, Playwright (E2E) | |
| Static analysis | Larastan (PHPStan), Laravel Pint, ESLint, `vue-tsc`, Prettier | |
| Web server | Caddy (automatic HTTPS) → PHP-FPM | |
| Dev mail servers | GreenMail (IMAP+SMTP test server), Mailpit (SMTP catcher UI) | Dev/test only. |

Explicitly **not** used: microservices, Kafka/RabbitMQ, Elasticsearch/Meilisearch (yet),
Kubernetes, WebSockets server (yet), Inertia, separate frontend repo.

Package names above are architectural candidates, not verified compatibility claims. M1 pins
versions after the dependency gate in [10](10-open-decisions-and-risks.md). No packages are
installed in M0. Core stack choices specified by the product remain fixed.

## 3. Runtime topology (single host, Docker Compose)

```
                    ┌──────────────┐
 Browser ──HTTPS──▶ │    caddy     │── static assets (public/build)
                    └──────┬───────┘
                           │ FastCGI
                    ┌──────▼───────┐          ┌────────────┐
                    │   app (fpm)  │─────────▶│ PostgreSQL │◀─────┐
                    └──────┬───────┘          └────────────┘      │
                           │ dispatch jobs                         │
                    ┌──────▼───────┐          ┌────────────┐      │
                    │    Redis     │◀────────▶│  horizon   │──────┘
                    └──────▲───────┘          │ (workers)  │──IMAP/SMTP──▶ mail servers
                           │                  └────────────┘
                    ┌──────┴───────┐
                    │  scheduler   │  (php artisan schedule:work)
                    └──────────────┘
       Shared volume: storage/app/blobs  (raw .eml + attachments, content-addressed)
```

`app`, `horizon` and `scheduler` are the **same PHP image** with different commands.
The diagram's Redis is the non-evicting queue/lock instance; a separate Redis instance provides
cache and sessions. PostgreSQL also holds durable intent and account serialization locks.

## 4. Major components (backend)

| Component | Responsibility |
|---|---|
| **Accounts** | CRUD of mail accounts, connection settings, connection test, enable/disable sync, visibility. |
| **Credential vault** | Sole decryption entry point; hands short-lived secrets to the authorized transport. |
| **Provider drivers** | `ImapSyncDriver` now; Gmail API / Microsoft Graph later. Each implements `AccountSyncDriver`. |
| **Sync engine** | Scheduling, per-account locking, checkpointing, folder discovery, reconciliation. |
| **Ingestion** | Raw message → blob store → parse → `messages`/participants/attachments/body rows → thread assignment. Provider-independent. |
| **Blob store** | Content-addressed storage (sha256) of raw messages and attachments on a Laravel filesystem disk. |
| **Organization** | All user-driven local state changes (folder, tags, flags, notes, bulk) in one service; writes audit events; emits domain events. |
| **Views & query** | Translates a `MessageFilter` (system view, folder, account, tag, flags, dates, text) into a keyset-paginated SQL query. |
| **Search** | `SearchEngine` interface; `PostgresSearchEngine` implementation over a dedicated search-document table. |
| **Rendering** | HTML sanitization, `cid:` resolution, remote image blocking, image proxy. |
| **Remote write-back** | Outbox of flag changes pushed to IMAP (M3/M4). |
| **Sending** (M6) | Drafts, outbox state machine, SMTP transport, optional IMAP `APPEND` to Sent. |
| **Audit** | Append-only log of destructive/bulk/security-relevant actions. |

## 5. Decision log

Each decision: what, why, and the rejected alternative.

**D1 — Monolith, one repository, Laravel serves API + SPA.**
Why: one deployable, one test suite, shared auth/session, no CORS. Rejected: separate frontend repo
(no benefit for a single team; version skew), microservices (no scaling need).

**D2 — Vue SPA with JSON API, not Inertia.**
Why: a mail client is a long-lived stateful app (selection, keyboard navigation, virtual lists,
optimistic updates, background refresh), not a set of server-rendered pages. A JSON API also serves
a future mobile app. Rejected: Inertia (page-oriented, awkward for three-pane in-place navigation).

**D3 — Sanctum SPA cookie authentication (same origin), Fortify for auth flows.**
Why: HttpOnly session cookies keep tokens out of JavaScript; CSRF protection built in. Fortify gives
login, password reset, and TOTP 2FA without UI coupling. Rejected: bearer tokens in localStorage.

**D4 — PostgreSQL is the system of record; raw messages stored as blobs on disk.**
Why: raw RFC 822 is the ground truth that allows re-parsing (parser bugs, new features such as
extraction/AI later), forwarding with original attachments, and audit. Storing multi-MB blobs in
PostgreSQL bloats backups/vacuum. Content addressing (sha256) makes writes idempotent and
deduplicates identical attachments across accounts. Rejected: bytea in Postgres; parse-only storage
(lossy, irreversible).

**D5 — One `message` row per (account, logical message), N remote locations.**
Why: the same message can exist in several IMAP folders (copies, Gmail labels) and can be moved
between folders by other clients. Local organization attaches to content identity, not an IMAP
UID, and survives byte-preserving moves. MIME-rewritten copies remain separate in the safe v1
baseline; see [03](03-synchronization.md) §4 and OD-13.
Rejected: one row per IMAP UID (remote move = delete + create = lost organization).

**D6 — Local organization state lives in columns/tables that sync code never writes.**
Why: the clearest possible boundary; makes "remote change can't erase organization" and "local
folder/tag/done changes can't touch the server" structurally true, and testable. Read/star have
an explicit opt-in mirroring exception through OrganizationService. See
[04](04-organization-semantics.md).

**D7 — Pure-PHP IMAP library behind our own `ImapClient` interface; chosen by spike.**
Why: the core must not depend on a particular IMAP library or extension. Our
interface exposes only what the sync algorithm needs (EXAMINE, UID SEARCH, UID FETCH with PEEK,
UID STORE flags, LIST with SPECIAL-USE, capabilities), so the library is replaceable and a fake
implementation drives deterministic tests. Spike criteria: correct UID/UIDVALIDITY handling, raw
`BODY.PEEK[]` fetch without auto-parsing, streaming large messages, CONDSTORE support, timeouts,
error typing, maintenance activity.

**D8 — Sync via scheduled polling with queue jobs; no IMAP IDLE initially.**
Why: IDLE needs one long-lived connection per folder per account — a daemon, not a queue worker.
Polling every 2–5 minutes (configurable) is adequate for a first product. A dedicated IDLE process
can later simply *trigger* the same sync job. Rejected: IDLE in M2.

**D9 — Provider abstraction at the *sync driver* level, not at the protocol level.**
Why: IMAP (UIDs, folders), Gmail API (history IDs, labels) and Graph (delta tokens, folders) have
incompatible incremental-sync models. A lowest-common-denominator "mailbox API" would be leaky.
Instead each provider implements `AccountSyncDriver::sync(account, budget)` with its own
checkpoints, and all of them feed the shared, provider-agnostic **Ingestion** pipeline with raw
MIME + a remote-location reference. Credentials are resolved through `CredentialProvider` so
OAuth (XOAUTH2 over IMAP/SMTP) can be added without changing drivers.

**D10 — PostgreSQL full-text search behind a `SearchEngine` interface, indexed in its own table.**
Why: adequate for the first product; `message_search_documents` isolates the index so a future
driver can replace it without touching `messages`. Structured filters stay in SQL. No external
search engine is introduced in M1–M7. See [11](11-search.md).

**D11 — Keyset (cursor) pagination for all message lists.**
Why: OFFSET pagination degrades linearly and produces duplicates/skips while new mail arrives.
Sort key `(sort_date DESC, id DESC)`.

**D12 — Polling-based UI freshness via a "changes since" endpoint, no WebSockets initially.**
Why: avoids running Reverb/websocket infrastructure; a 20–30 s poll of a cheap endpoint is fine
for a single-user desktop app. Can be upgraded to Laravel Reverb later without API redesign.

**D13 — HTML mail: server-side allowlist sanitization + sandboxed iframe + strict CSP.**
Why: defense in depth; any single layer can fail. See [05](05-security.md).

**D14 — Durable intent in PostgreSQL, Redis only as a trigger.**
Why: Redis queue contents can be lost. Anything that must not be lost (flag write-backs, outgoing
mail) is persisted as a row with a state machine first; the job only processes rows, and a sweeper
re-dispatches stuck rows. Sync needs no such table because it is checkpointed and idempotent.

**D15 — Caddy + PHP-FPM, no Octane.**
Why: Caddy gives automatic TLS on a single server with a 10-line config; PHP-FPM is the most
understood runtime. Octane adds state-leak risks with no demonstrated need.

**D16 — Messages are organized individually; the reader shows the whole thread.**
Why: the requirements specify "an email has one internal folder" and bulk actions on messages.
Thread-level actions ("move conversation") are provided as explicit actions that expand to message
ids. The accepted per-message behavior is recorded in [10](10-open-decisions-and-risks.md).
