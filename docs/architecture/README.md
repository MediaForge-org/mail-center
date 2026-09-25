# MailCenter — M0 Architecture Specification

Status: **M0 specification complete; M1 foundation added**. Mail features remain future work.
Pre-M1 dependency and release gates remain explicit in [10](10-open-decisions-and-risks.md).
The documents in this folder are the
contract that M1+ implementation work follows. If an implementation needs to deviate, update the
relevant document first.

## Reading order

| # | Document | Purpose |
|---|----------|---------|
| 01 | [System overview & key decisions](01-system-overview.md) | Stack, components, runtime topology, decision log with rationale |
| 02 | [Data model](02-data-model.md) | PostgreSQL tables, relationships, ownership of columns, indexes |
| 03 | [Synchronization](03-synchronization.md) | IMAP sync algorithm, idempotency, failure recovery, queues |
| 04 | [Organization semantics](04-organization-semantics.md) | Remote vs. local state, folders, tags, flags, views, threads |
| 05 | [Security](05-security.md) | Auth, credentials, HTML rendering, remote content, audit |
| 06 | [Code structure](06-code-structure.md) | Laravel backend layout, Vue frontend layout, API surface |
| 07 | [Repository & deployment](07-repository-and-deployment.md) | Repo layout, Docker, `.gitignore`, backups |
| 08 | [Testing strategy](08-testing-strategy.md) | Test layers, fixtures, IMAP test servers, CI checks |
| 09 | [Milestones](09-milestones.md) | Refined milestone sequence with scope and exit criteria |
| 10 | [Open decisions & risks](10-open-decisions-and-risks.md) | Pre-M1 gates, later spikes, accepted limitations |
| 11 | [Search](11-search.md) | PostgreSQL query contract, indexing, rebuilds, performance |

## One-paragraph summary

A single Laravel application (PHP) serves a JSON API and a Vue 3 + TypeScript single-page app built
by Vite, from one repository. PostgreSQL is the system of record; Redis backs queues (Horizon),
cache, locks and sessions. Mail is pulled from IMAP servers by queued jobs that are **idempotent and
checkpointed**, stored as raw RFC 822 blobs on disk plus parsed rows in PostgreSQL. Every synced
message becomes exactly one **message** row per account, linked to one or more **remote locations**
(IMAP folder + UIDVALIDITY + UID). User organization (internal folder, tags, starred, important,
done, notes) lives in **local columns/tables that sync code never writes**, so remote changes can
never erase organization and local organization never moves or deletes anything on the server. The
only permitted remote writes (later milestones) are explicitly enabled flag write-backs
(`\Seen`, `\Flagged`) through an outbox, SMTP sending, and optional Sent `APPEND`.
HTML mail is sanitized server-side and rendered in a sandboxed,
script-less iframe with a strict CSP; remote images are blocked by default and, when allowed, loaded
through a signed server-side proxy.

## Git / local tooling rule (repeated here on purpose)

`graphify-out/` is local, disposable Graphify output. It is never committed, never used as source,
never referenced at runtime, and must be listed in `.gitignore` and `.dockerignore` as soon as those
files are created. Both ignore files are present in M0. See
[07](07-repository-and-deployment.md#gitignore). Agents may use read-only Git inspection;
all Git state changes and remote operations belong to the user.

## Takeover record

The inherited working tree contained this index plus documents 01–06, all untracked, and only
a one-line tracked root README. No framework, manifests, migrations, tests, or ignore files
existed. Documents 07–10 were linked but absent. The takeover preserves the monolith, domain
boundaries, message/location split, provider seam, and three-pane SPA design, completes the
missing documents, and corrects identity, visibility, synchronization, and security contracts.
Document 11 collects the search contract that was previously only sketched in the data model.
