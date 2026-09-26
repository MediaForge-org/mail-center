# M3 acceptance gate

## Repository audit (before implementation, 2026-09-26)

Classification concerns implementation; passing earlier reports is not release evidence.

| Requirement | Audit classification | Repository evidence / remaining gate |
|---|---|---|
| Three-pane message list/reader, routing, account scopes, resizing | COMPLETE | Vue workspace/list/reader; navigation and resize tests |
| All Mail, Inbox, Unread, exact counts, signed keyset pagination | COMPLETE | MessageFilter, MessageView, MessageController; API/view/count tests |
| Read-only Sent/Archive | MISSING | SystemFolders exists; MessageView/routes expose only three views. M3 account/folder reading requires these; editing remains M4. |
| Conversation reader and thread assignment | MISSING | Threads schema exists, but ingestion never assigns thread_id; reader loads one message. |
| Durable freshness | PARTIAL | user_change_versions and organization locking exist; ingestion/account/body changes and frontend polling protocol are absent. |
| Local read actions / opt-in Seen | COMPLETE | OrganizationService, SeenWriteback, shared account locks, durable generation outbox, sweeper; commit/race/protocol tests. Full regression gate pending. |
| HTML/CSS, attachments, CID, consent/proxy/session authorization | COMPLETE | Version 4 sanitizer, parser-based CSS, same-message resources, pinned DNS transport, browser fixtures. Full security/browser gate pending. |
| Durable re-sanitization maintenance | PARTIAL | Operator command and ID-only jobs exist; abandoned queue deliveries require manual replay, no durable run/checkpoint. |
| All Mail invariant acceptance | PARTIAL | Existing tests cover predicates; final combined acceptance and freshness changes need verification. |
| 100k benchmark | MISSING | MailboxPlanTest uses two accounts and single EXPLAIN samples; no ten-account latency distribution. |
| Integrated browser acceptance | PARTIAL | Separate session/resource, CSS fidelity, remote-image scripts exist; conversation/freshness and full navigation acceptance absent. |
| Final deployment/acceptance documentation | PARTIAL | Architecture index still describes reading as future work. |
| Folder editing, move/delete UI, tags, notes, bulk, Flagged | intentionally deferred | M4; not part of this gate |
| Search / sending / AI | intentionally deferred | M5/M6 or later; excluded |

Security inspection found no reason to redesign existing rendering: sandbox/CSP prohibit scripts,
forms and external image destinations; remote URLs remain private mappings; image fetching is
consent-gated and DNS-pinned. These controls must pass their existing adversarial suites again.

The audit is complete. Implementation and measured acceptance results follow below as work proceeds.

### Freshness implementation decision

Use PostgreSQL transactional triggers for mailbox-visible records, including operator/background
writes. They serialize increments on the existing per-user row; increments roll back with domain
writes. This extends the application-only writer contract to database-enforced invalidation.
Existing organization operations keep their version-first lock. Other SQL writers may acquire the
version lock at their statement boundary; PostgreSQL deadlock detection can abort conflicting
transactions (callers retry), but cannot publish a version before its data or miss a committed
change. Versions are invalidation tokens, not counts of actions. Clients sample a version *before*
reloading data and acknowledge only that version, so a concurrent later commit is polled again.

## Implemented gaps

- `Threader` assigns within an account using valid Message-ID / In-Reply-To / last 20 References,
  handles late parents, merges into the oldest thread, and splits ambiguous late-duplicate bridges.
  No subject guessing. Version lock precedes a distinct transaction advisory lock. Each pass examines
  at most 100 candidates; `thread_repairs` records continuation, and a five-minute sweeper redelivers
  abandoned work. Existing rows require the explicit backfill command below.
- `GET /api/messages/{id}/conversation` returns at most 50 lightweight chronological members and a
  signed, user/selected-message/thread-bound cursor. No bodies in membership responses. Account and
  owner scope remain mandatory; local deletion and deleted accounts are excluded. Disabled owned
  accounts remain readable. The existing individual reader handles each expanded member, including
  its own HTML, CID, attachments and image consent. Selected message stays obvious; the main list
  remains one row per message. Remote removal is labelled in the conversation.
- `sent` and `archive` use the shared `MessageView`/`MessageFilter`. Their only extra predicate is
  the user's matching local system folder. Done/remote-removed messages remain. Global views use
  enabled accounts; explicit account views permit disabled owned accounts. Counts share these
  predicates. Routes `/mail/sent`, `/mail/archive`, and account variants are read-only.
- PostgreSQL statement triggers durably invalidate visible changes, including deletion, account
  visibility, ingestion, derived bodies, attachments, read state and write-back status. Idle
  `GET /api/changes?since=N` is a single primary-key lookup and returns a decimal string version,
  avoiding JavaScript integer precision loss. Hidden tabs pause polling; visible tabs check every
  30 seconds (5 while sync is active), and on visibility restoration. Unchanged versions do not
  reload accounts/counts/messages. Failed account/count refreshes do not acknowledge the sampled version; list/reader failures retain their explicit retry states.
- Operator-requested sanitization now creates a durable `maintenance_runs` checkpoint. A dedicated
  single-worker maintenance queue processes bounded batches, leases fence duplicate deliveries,
  and a sweeper recovers expired/lost work. Old code-version runs become superseded; irreparable
  MIME remains safely stale and is counted as an error. A repeat operator run retries stale items.
  Derived writes compare retained raw identity and parser version before replacement.

## 100,000-message benchmark

Recorded raw results and EXPLAIN ANALYZE/BUFFERS plans: [m3-100k.json](benchmarks/m3-100k.json).
Reproduction: `docker compose run --rm test php artisan test --group=performance`.
The pre-boot guard permits only `postgres-test/mailcenter_test`.

Environment: Intel Core Ultra 7 256V, 8 exposed physical cores, Linux x86_64, approximately 15.18 GiB
host RAM; shared development host, **not** the provisional dedicated 4-core/8-GiB reference machine.
PostgreSQL 18.6 Alpine on an isolated 1-GiB tmpfs; PHP 8.5.10; Laravel 13.33.0. The test tmpfs cap was
increased from 512 MiB after consecutive synthetic fixtures exhausted it; `postgres_data` unchanged.

Dataset: exactly ten accounts and 100,000 messages; approximately equal Inbox/Sent/Archive,
20% unread, deterministic done/removed distributions. Fifty-row pages fetch one lookahead row.
Five warmups followed by 100 timed iterations per case; nearest-rank p50/p95. Five independent PHP
clients also execute 100 timed idle polls each (500 observations). Measurements include controller
validation, database round trips and JSON serialization. They exclude HTTP middleware, browser and
WAN latency; **these are backend service times, not PostgreSQL-only execution times**. First-touch
samples are recorded after fixture loading/ANALYZE; OS/database caches were not forcibly dropped,
so this is not a cold-disk benchmark. No cold-SSD or million-message claim is made.

| Operation | p50 ms | p95 ms |
|---|---:|---:|
| All Mail first 50 | 4.778 | 6.415 |
| Inbox first 50 | 5.906 | 8.173 |
| Unread first 50 | 4.769 | 6.279 |
| Account first 50 | 6.261 | 8.206 |
| All Mail second keyset page | 4.384 | 6.702 |
| Exact counts (all five system views + accounts) | 272.965 | 282.040 |
| Idle version poll | 1.410 | 2.200 |
| Idle version poll, five concurrent clients | 1.072 | 2.312 |

All list targets (<250 ms p95) and idle polling target (<100 ms p95) pass in this environment.
Counts have no specified milestone latency SLA; their roughly 282-ms p95 is a known cost. They use
bounded aggregate queries, never per-account requests, and are not executed on unchanged idle polls.
No feed index was added: existing user/account and partial Inbox/Unread indexes meet the target.
New indexes support actual thread lookups: account/header, account/In-Reply-To, References GIN and
(thread_id, sort_date, id); the thread composite unique key supports the new scoped membership FK.
The first per-row freshness prototype made bulk fixture insertion slow; it was replaced before
acceptance by one transactional increment per affected user per statement.

## Deployment and existing mail

These commands are **operator instructions, not commands run on development mail during acceptance**:

```sh
# Back up development data first (see development.md), then deploy schema and restart workers.
docker compose exec app php artisan migrate --force
docker compose exec app php artisan horizon:terminate
# Header-only thread assignment; queued and repeatable, no raw MIME read:
docker compose exec app php artisan messages:assign-threads
# Only when existing derived output is stale / extraction was deferred:
docker compose exec app php artisan messages:sanitize-html
docker compose exec app php artisan messages:extract-attachments
```

Keep scheduler and Horizon running. Inspect `maintenance_runs` status/stats for sanitization failures;
`thread_repairs` is the durable backlog. Pending thread repair is recovered every five minutes.
No startup migration silently re-ingests or reprocesses mail. Until backfilled, unassigned messages
remain individually readable. Sanitizer remains version **4**; stale HTML still falls back to plain text.

## Deliberate architecture deltas and limitations

- Database-enforced statement invalidation extends the original application-only version-first
  rule. Organization/account/ingestion paths lock the version first; other SQL writers may acquire
  it at statement end. Deadlock victims roll back rather than publish partial data/version. The
  client samples before reload; count queries use a repeatable-read snapshot.
- Re-sanitization remains operator-started rather than being enqueued by reads; this preserves the
  existing M3 operator workflow and prevents surprise processing of development mail.
- Maintenance cursor is a bigint message ID instead of general JSON. This is the only M3 maintenance
  cursor needed. No general event bus, web sockets or search maintenance was introduced.
- Browser automation uses Chromium CDP and the existing Python harness instead of adding Playwright.
- Thread identity matching is conservative: missing/invalid/ambiguous headers may yield separate
  conversations. At most 100 candidate edges are processed per pass; large repairs can take multiple
  sweeps. There is no subject-based merge or cross-account conversation.
- Conversation metadata is paginated; bodies/resources load only for expanded messages. An explicitly
  selected message beyond the loaded metadata page is shown separately until its chronological page
  is loaded. No bulk/thread organization action or automatic mark-as-read was added.
- HTML CSS remains intentionally limited (inline allowlist, no style blocks/external fonts). Antivirus
  scanning is not implemented. Proxy loading can still notify a sender via unique tracking URLs.

## Final acceptance evidence

The full backend suite covers All Mail retention/exclusion and exact counts, cross-user and
cross-message authorization, Seen opt-in/failed intent/races/UIDVALIDITY/shared locks, hostile CSS,
CID ambiguity, stale HTML, DNS rebinding/private IP/redirect/size/MIME/pixel-limit failures and
consent-token ownership. No test uses real Gmail credentials or public internet image hosts.

Browser acceptance ran against a production Vue bundle and the guarded Laravel test server on
127.0.0.1:8072, with synthetic MIME and PostgreSQL test data:

| Browser gate | Result |
|---|---|
| `m3-acceptance-smoke.py` | PASS: global All/Inbox/Unread, account All/Inbox/Unread, Sent/Archive, 50→67 row pagination, thread order/expand/selected marker, plain-text escaping, read/unread/counts, history, deep links/reload, pane persistence, 2560/1440 widths, attachment bytes, session/CID authorization, foreign/cross-message denial and immediate logout denial |
| `remote-image-smoke.py` | PASS: default zero upstream requests; Load once exactly one server-side request; navigation resets consent; allowlist/reload through same-origin proxy; revoke/logout denial; no browser requests to sender hosts; no cookie/auth/referrer/client-IP forwarding |
| `html-render-smoke.py` | PASS: sandboxed PNG/JPEG CID decode, hostile content/CSP bypass tripwire, zero external requests, no scripts/navigation |
| `html-fidelity-smoke.py` | PASS: 11 source/sanitized layout metrics match, CID placement works, narrow overflow contained, zero external requests |

Observed desktop row height: 57 px; individual reader header: 153.19 px. Sidebar keyboard resize
persisted at 244 px after reload. Synthetic screenshot inspected at 2560×1600. No redesign or
placeholder M4 navigation was introduced. Conversation membership now loads before its initial
selected content, preventing duplicate authorized image fetches on initial mount.

Commands for this isolated browser stack are in development.md; use `tests/browser/m3-fixture.php`
in place of the smaller session fixture, then run the four Python scripts above. Stop the isolated
browser container before any database-resetting backend suite. The fixture and router both require
the pre-boot test guard, and never run against `mailcenter`.

### Final requirement matrix

| M3 requirement | Final classification | Acceptance evidence |
|---|---|---|
| Three-pane message list/reader, scope routes, history, selection and resize | COMPLETE | 43 frontend tests; integrated browser flow |
| All Mail / Inbox / Unread / account views and exact counts | COMPLETE | shared filters, All Mail combined invariant and count tests, browser read/count updates |
| Reading-oriented Sent/Archive | COMPLETE | global/account routes, shared predicates/counts, backend and browser coverage |
| Conversation assignment and reading | COMPLETE | late parents, merges, duplicate split, cycles/self-reference, malformed IDs, account isolation, bounded repair and chronological cursor tests; shared-reader browser expansion |
| Signed keyset pagination | COMPLETE | list/account/view cursor boundaries, second-page benchmark, conversation cursor boundaries |
| Durable freshness | COMPLETE | transactional PostgreSQL statement invalidation; real separate-connection commit-order/rollback tests; idle-poll frontend test and five-client benchmark |
| Local read/unread and explicit opt-in Seen | COMPLETE | 241-test suite includes commit-before-dispatch, UIDVALIDITY, active copies, auth failures, shared locks, unresolved intent, lease recovery and rapid read→unread→read during STORE |
| HTML/CSS, plain fallback, attachment and CID security | COMPLETE | hostile suites, actual browser resource bytes/decode, foreign/cross-message/logout denial |
| Privacy-safe remote images | COMPLETE | hostile SSRF/token tests and controlled Chromium/upstream trace; default no fetch, consent-only proxy, allowlist/revoke |
| Durable versioned re-sanitization | COMPLETE | checkpoint/lease/sweeper run recovery, parser/raw identity guard, safe stale output |
| 100k list and idle-poll performance gate | COMPLETE | ten accounts, 100k rows, 100 iterations; five-client idle poll; raw results/plans recorded |
| Integrated browser/security gate | COMPLETE | four passing Chromium scripts above |
| Deployment/limitations documentation | COMPLETE | this record, architecture delta, development guide and explicit operator commands |
| M4 organization editing, bulk, Flagged; M5 search; M6 sending; AI | intentionally deferred | not implemented or exposed by this task |

### Final checks (2026-09-26)

- Full guarded backend suite: **241 passed, 2,022 assertions**, 108.35 seconds, including the 100k gate.
- Pint: **124 files pass**. PHPStan: **0 errors**.
- Composer audit: **no security vulnerability advisories** (unwritable Composer cache warning only).
- Frontend: **43 passed, 8 files**. vue-tsc, ESLint, Prettier and production build: **pass**.
- npm audit: **0 vulnerabilities**.
- Integrated browser, remote-image network, hostile HTML/CSS/CSP and fidelity: **4 scripts pass**.
- `git diff --check`: **pass**.

**M3 exit criteria are satisfied in the isolated acceptance environment.** Deployment migrations and
optional existing-mail backfills remain explicit operator actions, not failed acceptance gates.
M4 was not started. No Git write operations were performed. `graphify-out/` was untouched.
Persistent `mailcenter` was not migrated, reset, wiped or reprocessed; `postgres_data` remains persistent.
