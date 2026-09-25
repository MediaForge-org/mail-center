# 10 — Decisions and Risks Before Implementation

M0 is complete as a specification. No runtime compatibility, provider integration or performance
claim has been proven by code. The following gates make that distinction explicit.

## Before M1 starts

| Gate | Required resolution | Owner / evidence |
|---|---|---|
| G1 — Supported version matrix (resolved for M1) | PHP 8.5/Laravel 13, PostgreSQL 18, Redis 8.2 and Vue 3.5 were selected; locked package versions and container extension checks are recorded in the [M1 setup](../development.md). Revalidate support at upgrade time. | M1 implementer; lockfiles, image build and package checks |
| G2 — License (resolved for M1) | The user selected AGPL-3.0; M1 adds the official AGPL-3.0-only LICENSE and Composer metadata. Dependency compatibility remains a release review before publishing. | User selection; LICENSE and manifest |
| G3 — Reference environment (resolved for M1) | Linux/Fedora with Docker Compose is the tested reference; the [M1 setup](../development.md) documents startup and resource assumptions. Production domain/backup location remains an M7 decision. | M1 implementer/operator; working Compose startup and checks |

Do not silently substitute a different core stack to solve a gate. Library selections listed in
01 are candidates until G1 and the relevant feature spike pass. No speculative "latest" version
number is a normative dependency pin. These are remaining implementation-entry decisions, not
missing M0 application implementation.

## Product decisions settled for v1

| ID | Decision | Consequence |
|---|---|---|
| OD-1 | Private user ownership, no team workspace. Public registration off; operator creates users. | Model user boundaries now and test with two users, even for a single operator deployment. |
| OD-2 | Generic password/app-password IMAP and SMTP first; OAuth is a later adapter. | Gmail/Microsoft accounts that prohibit password auth are not promised supported initially. Provider abstraction supports later OAuth without changing organization semantics. |
| OD-3 | Remote removal retains synchronized mail in All Mail and folders. | Badge removed/unknown states; only Inbox/Unread exclude confirmed removed mail. Local deletion is separate. |
| OD-4 | Seed a custom Done folder; keep done as an independent boolean state. | Flag view label is Completed. Moving to Done never implicitly marks completion. |
| OD-5 | Per-message organization; replies get normal initial placement. | A filed conversation can receive a new Inbox message. Thread actions are explicit and affect current authorized messages. |
| OD-6 | Sync remote All Mail role by default; show folder/horizon exclusions. | Gmail archived messages are not missed just because All Mail is a label aggregate. Duplicate bytes link to multiple locations. |
| OD-7 | Delete and restore are local; no IMAP folder manipulation. | Remote copies remain. Purge is a later explicit operation with identity tombstones. |
| OD-8 | Sandboxed same-origin reader with response CSP is initial boundary. | Separate user-content origin is a hardening option before broader previews, not assumed deployed. |
| OD-9 | Strip sender CSS in v1. | Lower HTML fidelity is accepted until a dedicated CSS sanitizer passes adversarial tests. |
| OD-10 | PostgreSQL simple+unaccent, structured filters, explicit substring behavior. | No automatic language stemming; body indexed to 64 KiB with disclosed truncation. No external search in M1–M7. |
| OD-11 | Mandatory production TLS certificate/hostname verification. | Self-hosted mail uses configured trust roots; no user-facing production verification bypass. |
| OD-12 | SMTP and Sent APPEND are separate durable operations. | Account-specific APPEND opt-in defaults off; sending acceptance can remain unknown. Final correlation design is an M6 gate. |
| OD-13 | Exact raw-content identity inside each account. | Header collisions cannot erase distinct mail. MIME-rewriting servers may create visibly separate copies; no universal semantic dedupe claim. |
| OD-14 | Read/star initialize from server; later mirroring is separately opt-in and defaults off. | Local organization works with read-only accounts; only explicit mirror mode allows ongoing reconciliation/write-back. |

## Technical spikes and release blockers

| Risk | Required evidence / fallback | Deadline |
|---|---|---|
| Pure-PHP IMAP support | Compare candidate clients on rev1/rev2 capability negotiation, TLS, UTF-7/UTF-8 names, streaming, UIDVALIDITY, bounded UID queries, PEEK, errors, and safe disconnect without CLOSE. Use protocol traces, not just API names. Adapter remains replaceable. | Start M2 |
| Provider rewrites and reused IDs | Fixture corpus proves byte-identical linking and distinct-body preservation. If broader move preservation is required, design versioned provider IDs/content equivalence with collision handling before expanding the identity contract. Never weaken it to header-only merge. | M2 acceptance; revisit for each API connector |
| UID scan and lock scale | Demonstrate progress across multi-job cycles, no starvation, and account exclusion even after Redis restart. Measure time for sparse UID windows and large folders; tune bounds without skipping coverage. | M2 acceptance |
| Fatal MIME parsers | Isolated, bounded parser process; active-UID crash recovery, malformed input and memory/size budgets must work without infinite retries. Preserve raw bytes when safely fetched. | M2 acceptance |
| Rendering/proxy safety | Chosen sanitizer version and URL handling pass hostile corpus, direct navigation and zero-network-blocked-image tests. If uncertain, ship plain text until safe rendering exists. | Before M3 HTML/images |
| Search completeness/performance | Disclose indexing limit and omitted attachment/note text. Benchmark 100k/1m corpus and index rebuild. Partitioning or external services are not an unreviewed fallback. | M5 acceptance |
| SMTP exactly-once impossible | Distinguish definite rejection from uncertain acceptance; retain stable raw MIME, user-visible unknown state and independent APPEND state. Settle rewritten Sent-copy correlation. | Before M6 sending |
| Blob lifecycle and recovery | Prove GC/publication locking, crash orphan cleanup, coherent backups, key rotation and restore. Tombstones must survive purge as documented; backups retain content until expiry. | M7 acceptance |
| Resource exhaustion/privacy | Rate limit remote fetches, MIME and pixel sizes, DB statements and bulk selection; monitor storage and queue depth. Logs remain metadata-only. | With each relevant feature |

Library APIs, provider quirks and performance need experiments in their implementation milestone.
They are not reasons to initialize frameworks during M0. Any future change to account scope,
All Mail, Done or identity semantics requires a deliberate specification revision with acceptance
tests; implementers should not infer those decisions from a familiar mail client's behavior.
