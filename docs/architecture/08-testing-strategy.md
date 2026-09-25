# 08 — Testing Strategy

M0 validates the specification and repository safety only. The following tests are requirements
for later milestones, not tests already present or executed.

## Test layers

| Layer | Tool / environment | Purpose |
|---|---|---|
| Domain unit | Pest, pure DTOs, deterministic clock and fake transports | identity, filters, state machines, retry classification |
| Database integration | Pest against real PostgreSQL | unique constraints, transactions, owner FKs, locks, search and races |
| Queue integration | real Redis and PostgreSQL; separate worker processes | lost jobs, duplicate delivery, timeouts, lease/lock behavior |
| Protocol integration | scripted IMAP server plus GreenMail; SMTP fault server | command safety, capabilities, malformed responses, disconnects |
| Frontend | Vitest + Vue Test Utils | selection, cache invalidation, revision conflicts, optimistic rollback |
| Browser | Playwright against isolated stack | three panes, keyboard flow, authorization and hostile HTML/network behavior |
| Release | Docker clean build, restore drill, load fixtures | reproducibility, resilience and performance |

SQLite is not a substitute for PostgreSQL integration tests. Queue fakes do not establish
delivery/crash safety. GreenMail alone cannot represent every provider quirk; scripted fault
injection and captured *synthetic* protocol fixtures cover missing capabilities and failures.
No test connects to real user accounts, sends external mail or uses production credentials.

## Required invariant cases

| Contract | Required observations | Gate |
|---|---|---|
| All Mail | Move into each folder including Done; tag, star, mark done, remote-remove: row remains; only local deletion/account disable excludes it. Pausing sync does not. | M3/M4 |
| Identity | Replay same UID; copies with identical bytes; same headers/size but different bodies; missing/reused Message-ID; account separation. Correct row/location counts and preserved metadata. | M2 |
| Non-destructive sync | Protocol trace contains EXAMINE/PEEK, never MOVE/COPY/CLOSE/EXPUNGE/STORE Deleted, including failures and disconnect destructors. | M2 |
| Recovery | Crash after blob publish, before/after message commit, before checkpoint, after quarantine; resume without omission or duplicate locations. | M2 |
| UID coverage | Empty mailbox, sparse UIDs, UIDNEXT boundary, expunge plus arrival with equal count, FETCH disappearance, reversed-range protection and date horizon changes. | M2 |
| Remote transitions | Partial LIST, denied folder, rename, folder disable/re-enable, UIDVALIDITY change, unresolved quarantine and multi-job coverage. Incomplete state never proves removal. | M2 |
| Local preservation | Remote moves/reset/copies/reparse preserve folder, tags, notes, deleted/done/important and non-mirrored read/star values. | M2–M4 |
| Concurrent jobs | Duplicate dispatch, Redis flush/restart, worker death, PostgreSQL lock loss, scheduler recovery and unique continuation timing. No concurrent account writer. | M2 |
| Flag intent | Opt-in defaults, enable/disable races, wrong UIDVALIDITY, partial location success, new intent during processing, expired lease, failed intent, rapid read/unread. | M3/M4 |
| Ownership | User B cannot list, mutate, render, download, proxy, poll or search user A's resources; mixed-user bulk IDs rejected; DB rejects cross-owner links. | Every API milestone |
| Threads | Newest-first child/parent arrival, multiple-parent merge, cycles/self-reference, duplicate reference ID, malformed IDs, cross-account separation. | M2/M3 |
| Bulk/undo | Stable confirmed selection, later arrival excluded, crash between chunks, retries, stale revision and undo conflict reporting. | M4/M5 |
| Freshness | Concurrent commits out of order; delete and account-disable events; no missed invalidation; idle polls cheap. | M3 |
| Sending | Failure before DATA, lost final response, worker crash after acceptance, repeated submit, independent APPEND retry. Ambiguous outcome is never automatically resent. | M6 |

## Security fixtures and browser assertions

Maintain a small documented fixture corpus: multipart alternative/related, quoted printable,
base64, mixed charsets, nested message/rfc822, broken boundaries, invalid dates, absent headers,
inline CID duplicates, very large headers/bodies/attachments and invalid filenames. Assert byte
identity and truncation/error status, not only successful parsing. Fixtures use reserved example
addresses and synthetic content, with license/provenance recorded.

Hostile HTML includes event handlers, scripts, SVG/MathML, forms, frames, base/meta refresh,
javascript/data/relative links, CSS imports/URLs, background/srcset and oversized raster images.
Test rendered iframe and direct render URL. Assert no script execution, no unexpected network
request, no top navigation, no form submission, correct CSP and stale-version plain-text fallback.
Blocked remote images must issue zero proxy requests. Tokens from another user/message, expired
tokens, URL tampering, DNS rebinding, IPv6/private/metadata addresses, redirect loops, non-image
responses and decompression/pixel bombs must fail closed. Attachment download names cannot
inject headers or escape storage. Test IMAP/SMTP connection SSRF policy independently.

Credential tests cover ciphertext round-trip, key rotation/restart, missing key, redacted errors,
serialized jobs/API resources, logs, Horizon payloads and connection-test expiry. Test CSRF,
session fixation, rate limits, registration disabled, password confirmation, TOTP and recovery.

## Search and performance

M5 checks accents, multilingual/no-stemming semantics, phrases/negation, participants, escaped
substring wildcards, attachment names, empty and punctuation-only queries, date/timezone bounds,
local Trash, disabled accounts, and index rebuild/reparse parity. Plans must keep ownership and
structured filters before pagination; never fetch global IDs then filter an already limited page.

Reference fixture target: 10 accounts and 100,000 messages initially, then 1 million before a
high-volume claim. Measure on a documented 4-core/8-GB SSD host with dataset/versions recorded.
Provisional warm targets: list 50 rows p95 <250 ms, filtered search p95 <750 ms, read-only poll
p95 <100 ms at five active browser sessions. Set statement timeouts and report timeouts explicitly;
do not silently truncate results. These budgets need measurement in M3/M5, not promises in M0.
Measure ingestion throughput and peak parser memory on large MIME; bound jobs by work budget.

## CI milestones

M1 adds lockfile-based installs, formatting/static analysis (`Pint`, Larastan, ESLint, `vue-tsc`),
backend/frontend tests, Vite build, migration from empty PostgreSQL, Compose configuration checks,
and secret/output exclusion checks. M2 adds transport and worker tests. M3 adds browser/security
tests. M5 adds search plans/benchmarks; M6 adds SMTP failure tests. Dependency audits run in CI;
findings need explicit triage, and a failed check is never reported as passing. Heavy fault/load
suites may be scheduled separately, but their milestone gates must pass before release.

M0 checks: every local documentation link resolves, code fences are balanced, required topics
and milestone gates exist, ignore patterns cover generated output and secrets, no framework was
initialized, and no Git state-changing operation was used. No application test suite exists yet.
