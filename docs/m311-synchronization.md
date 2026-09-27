# M3.11 — Immediate requests and IMAP IDLE

**Acceptance status:** implementation and functional checks are complete. The final re-run
(2026-09-27, after the MessageList refresh-coalescing fix) met p95 <= 3s in every manual case A–G
and both IDLE cases (see "Final re-run" below). Earlier runs that exceeded 3s on this
swap-saturated host are retained, not replaced; the gate is met on the final run, not on every
historical run.

## Incident evidence and baseline

Read-only inspection of operational timestamps (no message content or credentials) found:

- On 2026-09-26, two message failures were recorded at 17:48:32 UTC. Their first retry was due
  at 17:50:32. Manual runs at 17:49:07, 17:49:14 and 17:49:18 completed in 0–1 seconds but
  did not retry those not-yet-due quarantined UIDs. Scheduled sync resolved both at 17:53:04.
  This is a **confirmed 4m32s retry/scheduler delay**, not a measured multi-minute IMAP operation.
- The original exception was deliberately discarded by the old per-message catch block. Its
  underlying cause cannot be recovered from `message_error`. New diagnostics record the exception
  class and account/folder IDs, never exception text, raw mail or connection arguments.
- A deterministic pre-change test reproduced a 202 response with **no job dispatched** while a
  `ShouldBeUnique` lock existed. That lock had a 900-second TTL. The 30-second overlapping-job
  release combined with one allowed attempt was another unsafe delivery assumption.
- Empty-queue workers used polling; there was no reserved manual queue. Frontend freshness
  normally waited 30 seconds (5 seconds only after learning the account was syncing).
- The pre-change direct synthetic incremental engine benchmark, 30 samples, measured
  **p50 92.521 ms / p95 114.626 ms**. This excluded HTTP, Redis waiting and browser rendering.
  `SyncLatencyBaselineTest` preserves the historical reproduction and its explicit opt-in.

The old system did not record request acceptance or worker-start timestamps. No historical
queue/lock/commit-to-browser p95 can honestly be recovered from those logs. The recorded 5–10
minute user report is not relabelled as measured queue latency. The controlled measurements below
instrument each observable stage; they do not claim to be a live Gmail benchmark.

## Durable manual requests and priority

`SyncRequests` serializes each account's intent in PostgreSQL. `sync_requested_generation`,
`sync_started_generation` and `sync_completed_generation` distinguish queued, claimed and completed
work. Repeated queued triggers share a generation. A trigger during remote IO creates at most one
pending follow-up generation. Manual priority outranks IDLE, which outranks scheduled/continuation
work. A queued scheduled generation can be promoted without invalidating its original delivery.

Dispatch occurs explicitly **after the outer transaction commits**. Jobs contain account IDs and
safe trigger names, never credentials. Acknowledgement cannot consume a newer generation. A crash
leaves unacknowledged durable work; the minute dispatcher recovers old deliveries, and a manual
request can immediately recover a claimed generation when no live advisory-lock owner exists.
An old queued request can be redelivered on another explicit trigger without a new generation.

`SyncAccountJob` no longer uses `ShouldBeUnique` or the Redis `WithoutOverlapping` gate. Stale Redis
keys therefore cannot suppress synchronization. The existing PostgreSQL advisory account lock is
still the common writer fence for sync and Seen write-back, verified before remote batches.
Contention releases the delivery for one second, with unlimited transport attempts. Provider
failure/backoff and auth-stop policy remain persisted separately; duplicate deliveries cannot
bypass them. Seen's existing Redis middleware is still a write-back optimization, not the sync
writer authority. This deliberately supersedes the M2 Redis delivery assumptions.

Horizon reserves one `sync-high` worker for manual/IDLE work and separate normal `sync` workers
(local 2, production 4). They use the existing `mail_sync` connection, timeout 600s and
`retry_after` 660s. Redis blocking pop wakes workers on arrival; worker sleep is zero with a
one-second blocking timeout. Normal recovery has reserved capacity, so high-priority arrivals do
not starve it. There is no worker per account.

## Incremental work and visibility

Each pass detects and ingests new UIDs across **all enabled folders before historical scans or
membership reconciliation**. Every message commits through the existing ingestion transaction,
including its durable mailbox-version increment. The UI does not wait for the account run to end.

Historical work uses one-second slices with per-message budget checks and preserved checkpoints.
New high UIDs are checked on the next pass even while Sent history remains incomplete. Finished
folder maintenance is not repeated on every backfill pass. Manual/IDLE jobs hand remaining work
back to normal continuation delivery after one fair pass. Scheduled work retains the overall
240-second budget. In-flight provider calls cannot be preempted safely. Manual runs retain the existing flag-scan
cadence; IDLE events force flag reconciliation so remote Seen notifications are not delayed.

A manual run permits one bounded early retry of retryable message failures per folder (up to 50).
Terminal/manual and oversized-message quarantines remain excluded. Scheduled retries retain their
backoff; this is not an unbounded retry loop.

`SyncTelemetry` records safe worker, lock-acquired, connect-start/end, first fetch, first ingestion,
first committed message and run-finished epoch timestamps in `sync_runs.timings`. Runs also record
the request generation and acceptance/worker fields. Request responses include the accepted
generation, queued/syncing state and acceptance timestamp. No protocol data is logged.

## Browser freshness and UI

Accepted manual actions immediately show Queued/Syncing. Both account management and an account's
mailbox header offer Sync now. The cheap, durable `/api/changes` version remains authoritative.
Manual acceptance activates a **400ms bounded poll period**, ending when the accepted generation
completes or after 30 seconds. A visible realtime workspace polls every 500ms; a busy polling
account also polls once per second. Otherwise it returns to 30 seconds. Hidden tabs skip requests;
returning to a tab checks immediately. No client-side invented counts or versions are used.

Account summaries expose safe request state, names of folders still backfilling and live watcher
roles. The UI distinguishes Realtime / Watching Inbox + Sent from polling, paused, error and
queued states. Historical work is labelled separately. Refresh view remains a local API refresh.

## IDLE runtime

`sync:watch` is a fixed-size connection pool (default 32 connections per daemon, maximum option
128), not one process per account. It uses `stream_select`, the existing TLS/DNS-pinned IMAP
transport and the installed IMAP library parser. It opens **at most two watcher connections per
account**: one enabled/selectable Inbox and one enabled/selectable Sent. Other folders remain on
scheduled recovery. The ordinary sync/Seen writer may use one additional connection per account.

- A `sync_watchers` row per remote folder owns a 30-second PostgreSQL lease. The daemon checks
  settings and renews leases every two seconds. Another daemon can recover expired ownership;
  a stale owner checks its lease before dispatch and disconnects when ownership is lost.
- No transaction or PostgreSQL writer lock is held while waiting in IDLE.
- CAPABILITY must advertise IDLE. Unsupported servers fall back to polling; capability retry is
  hourly. Transient watcher failures use persisted 2–300 second backoff. Authentication failure
  pauses account synchronization. Credentials/settings changes invalidate the watcher fingerprint
  and reconnect; pause, disable or removal disconnects it.
- Notifications leave IDLE with DONE, safely re-enter it, and request the normal sync engine.
  Re-entering before dispatch closes a subscription gap; initial connection and 25-minute renewal
  also request a normal pass. Cleanup disconnects and never sends CLOSE.
- One handshake per reconcile tick bounds reconnect storms. Socket/handshake timeout for watchers
  is two seconds. Established connections are multiplexed; connection startup and DNS delays are
  distinct from steady-state notification latency.
- SIGTERM/SIGINT stop the daemon, close streams and release its owned leases (plain `pcntl` handlers;
  the framework `trap()` helper exited 143 and skipped release, see the smoke-test fixes below). Compose
  restarts a crashed daemon. Capacity overflow accounts continue polling; operators may scale fixed pools.

IDLE follows [RFC 2177](https://www.rfc-editor.org/rfc/rfc2177): capability negotiation, DONE before
new commands and periodic renewal. It does not create a second ingestion or organization path.

## Measurement method

Environment: Linux 7.2.5 / Fedora 44, Intel Core Ultra 7 256V (8 logical CPUs), approximately 15 GiB
RAM, Docker, PostgreSQL 18.6, PHP 8.5.10 / Laravel 13.33.0 / Horizon 5.50.0, Chromium. The database is exclusively
`postgres-test/mailcenter_test`. Redis fixture keys have the checked prefix
`mailcenter-m311-isolated-`; development Horizon cannot consume those deliveries.

The synthetic mailbox begins with 20 Inbox messages and an enabled Sent folder; subsequent
iterations add real MIME fixtures. The slow-history case injects 20 un-ingested historical Sent
messages with 100ms fetch delay each. The running-scheduled case injects a one-second connection
barrier. The queued case parks a real scheduled delivery to model unavailable normal worker
capacity, then promotes its durable generation. These conditions are deterministic, not provider
or Gmail credentials.

Browser measurements use real authenticated Chromium clicks, JSON API requests, Redis workers,
PostgreSQL ingestion/version commits, the production Vue workspace and a DOM/animation-frame
observer. IDLE uses the production IDLE client/parser over a loopback IMAP protocol fixture;
incremental payload fetching uses a deterministic remote-client fixture. The final harness can
also inject a 1200ms connection delay without contacting an external server.

Each case uses 30 samples; p50/p95 use nearest rank (15th/29th sorted observations). First-message
commit and run completion are different events; A has no message and uses completion. G may be
satisfied by the already-running pass, so its additional queue delay is zero. Values are full
application/browser latency, **not PostgreSQL query latency**. One-time startup is warmed before
sampling. Raw samples are in `docs/benchmarks/m311-*.json`.

Initial 500ms-poll measurements (before the final 400ms adjustment):

| Case | End-to-end p50 / p95, ms | Accepted→worker p95, ms | Worker→first commit p95, ms |
|---|---:|---:|---:|
| A: no new mail (completion) | 950 / 2162 | 37.79 | 102.20 |
| B: new Inbox | 926 / 1193 | 9.80 | 77.37 |
| C: new Sent | 869 / 1052 | 10.91 | 82.09 |
| D: Sent backfill + new mail | 763 / 1191 | 11.45 | 93.33 |
| E: repeated clicks | 854 / 1041 | 10.65 | 87.50 |
| F: scheduled delivery queued | 928 / 1137 | 6.59 | 97.02 |
| G: scheduled pass running | 958 / 1662 | 11.07 | 1089.01 |
| IDLE Inbox | 931.57 / 1137.02 | 14.72 | 93.90 |
| IDLE Sent | 911.98 / 2149.71 | 14.61 | 106.11 |

The initial commit→render p95 for B/F was 1000.74/1017.66ms, which motivated the small bounded
poll adjustment to 400ms. This was measured feedback, not a global aggressive polling policy.

Quiet watcher measurement: 30.0003 seconds, 8 CPU ticks at CLK_TCK=100 = 0.08 CPU seconds
(**0.267% of one core**), RSS 64,996 KiB (**63.47 MiB**), **two IMAP connections**. This measures
the daemon with established protocol-fixture connections, not the combined PostgreSQL/Horizon
stack. Provider TLS buffers and account count will affect memory.

## Deployment and operator smoke (not automatically executed)

Back up development data, stop writers during deployment, apply normal migrations, and restart
workers with the new code/configuration. These are operator commands, not actions performed by
the implementation run:

```bash
sh scripts/backup-dev-db.sh
docker compose stop horizon scheduler imap-watch
docker compose exec app php artisan migrate --force
docker compose up -d --force-recreate horizon scheduler imap-watch
```

No HTML/attachment reprocessing is needed. `postgres_data` stays persistent. Never use
`docker compose down -v` as an update or test procedure.

Operator-controlled Gmail smoke: with your own account already backfilled, confirm Realtime
and the enabled watched roles, deliver a uniquely identifiable test mail from a separate client,
and observe the row. Repeat with Sync now while recording the browser Network/Performance
panel and matching `sync_runs.request_generation` / `timings`. Repeat after enabling Sent and
while history remains incomplete. Inspect the queued/running distinction, not only the final
account timestamp. Check pause/resume and password replacement. Do not share HAR files containing
sessions or private messages; keep provider credentials out of fixtures, job payloads and reports.

The exact original fetch/ingest exception remains unknown. Real-provider DNS, authentication,
throttling, connection limits, remote calls already in progress and initial connection-pool
startup can exceed controlled timings; polling remains recovery. Hidden tabs are not promised
foreground rendering latency. No M4, search, sending, SMTP or AI work is included.

## Reproducing the isolated measurements

The fixture starts with 20 messages; successive samples append unique synthetic mail (D also adds
20 historical Sent messages per sample), so later cases exercise a larger mailbox as well.
The fixture has strict `tests/bootstrap.php` database checks and a separate Redis/Horizon namespace.
It uses synthetic credentials and a loopback IMAP protocol server. Every Horizon child re-enters
the same guarded bootstrap. Do not run the database test suite concurrently with this fixture.
Run performance samples without concurrent builds/test suites; CPU contention changes browser and
HTTP latency. An exploratory run that overlapped frontend checks recorded an Inbox p95 of 3158ms and a
later browser timeout; it is not used as the quiet controlled acceptance run.

```bash
docker compose run --rm --name mailcenter-m311-fixture \
  -p 127.0.0.1:8072:8072 -e DB_CONNECTION=pgsql -e DB_PORT=5432 \
  -e APP_URL=http://127.0.0.1:8072 -e SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8072 \
  -e SESSION_DRIVER=file -e APP_DEBUG=false \
  test sh tests/performance/run-sync-stack.sh
# Separate terminal; 30 samples per case, 1200ms synthetic connect delay:
python3 tests/browser/sync-latency.py manual 30 1200
docker exec -d mailcenter-m311-fixture sh -c \
  'php tests/performance/sync-worker.php watch > /tmp/m311-watch.log 2>&1'
python3 tests/browser/sync-latency.py idle 30 1200
docker stop mailcenter-m311-fixture
```

The final harness runs actual Horizon master/supervisors/workers with opcode caching on the HTTP server; the initial baseline browser
run used `queue:work`. Neither uses development queue names/keys. Browser samples include a real
rendered row, not merely API acceptance. Timestamp traces accompany the final sample files.
The fixture's authenticated `/__fixture` endpoints exist only in its test router, never production.

## Automated acceptance checks

Final isolated backend suite: **263 passed, 2,128 assertions, 1 intentional skip**, 119.58s.
The skipped test is the preserved pre-change uniqueness/quarantine baseline; the explicit
`M311_AFTER=1` direct-engine comparison ran 30 samples (p50 100.965ms / p95 115.772ms).
The pre-change equivalent was 92.521ms / 114.626ms. There is no claimed large raw-engine speedup.

Coverage includes stale/lost Redis delivery, scheduled/manual/IDLE coalescing, promotion,
advisory contention, crashed claims, after-outer-commit dispatch and rollback, committed mail
visible through an independent PostgreSQL session before remaining IO finishes, early quarantine
retry, Sent before historical work, UIDVALIDITY, lease fencing/recovery, duplicate notifications,
capability fallback, reconnect/backoff, credential rotation, pause/resume/removal, auth failure,
IDLE/DONE protocol handling and unexpected server completion, and Seen write-back coexistence.
Existing Seen generation/security and all earlier M3 backend regressions remain green.

Frontend: **47 tests passed in 9 files**. Vue typecheck, ESLint, Prettier and production build pass.
Pint passes (147 files); PHPStan reports no errors. No Composer/npm dependency change was needed.
The tests include accepted manual generation/state, realtime/backfill/error presentation, bounded
fast polling completion/timeout, and all existing workspace/list/reader regressions.

Two additive migrations are required on deployment: durable request/run telemetry fields and
`sync_watchers`. They were applied only to the isolated test database during this task. No
migration, reprocessing, watcher, real-provider smoke or destructive command was run against the
persistent development mailbox. No Git write operations were performed; M4 was not started.

Benchmark runtime correction: the CLI HTTP fixture initially had `opcache.enable_cli=0`, whereas
normal PHP-FPM has `opcache.enable=1`. The uncached run exhibited HTTP tails during repeated Laravel request
bootstraps (one Sent API response took 4.927s) while that run's IMAP worker
still committed in 1.306s. The fixture now explicitly enables CLI opcode caching to match FPM's
normal caching behavior. This changes only the benchmark server, not application semantics.
The uncached exploratory Sent run measured p50 1782ms / p95 4328ms; it is recorded as a failed
exploratory result, not hidden or described as passing acceptance.


Extended-tail check: a second 30-sample no-change batch was retained in full. Combined with the
original batch, A has 60 observations, p50 1900ms / p95 3555ms; this did **not** meet the 3s gate.
The additional batch alone was p95 5319ms. Opcode caching alone did not eliminate the tail.
Read-only host measurements found almost all 8GiB of swap occupied, active swapping, and roughly
200MiB free RAM (the isolated fixture and PostgreSQL together used about 540MiB). Test-only HTTP
headers separated browser-to-server delay (5–11ms in slow examples), bootstrap (234–428ms),
bootstrap-to-acceptance (83–489ms), and response completion (75–390ms). Worker execution remained
around 1.3s, including the injected 1.2s. This is consistent with host resource contention, not a
multi-minute IMAP/queue wait. The user closed some browser tabs before subsequent IDLE trials.
Raw extended and diagnostic samples are retained; they must not be silently discarded when
interpreting the acceptance gate.


IDLE cadence measurement: with a 1000ms active poll and 1200ms synthetic connect delay, Inbox
measured p50 2353.75ms / p95 2937.34ms; Sent measured 2297.21ms / 2701.09ms (30 each).
Commit-to-version-observation median was 821/806ms. The measured delay justified changing only
visible realtime workspaces to a 500ms cheap-version cadence; hidden tabs remain paused, manual
polling remains bounded at 400ms, ordinary idle polling remains 30s. The before samples are retained
as `m311-horizon-idle-1000ms-poll*.json`.

## Horizon manual phase measurements (30 samples per case)

All values below are milliseconds, p50 / p95 unless stated otherwise. These are the complete
A–G batch; the extended host-pressure A failures and post-memory-relief repeat are reported
separately rather than replacing observations. F/G may already be running when accepted,
so additional queue delay is zero. G injects a 1000ms running-connection barrier; other cases
inject 1200ms. Freshness itself commits atomically with the message; poll delay is client discovery.

| Case | End-to-end | Accepted → worker | Button → worker p95 | Commit → render p95 |
|---|---:|---:|---:|---:|
| A | 1728.00 / 2533.00 | 22.26 / 162.97 | 576.86 | 782.88 |
| B | 1870.00 / 2799.00 | 22.34 / 41.33 | 346.58 | 855.80 |
| C | 1637.00 / 2153.00 | 21.28 / 48.72 | 194.49 | 769.96 |
| D | 1670.00 / 2328.00 | 25.74 / 96.56 | 259.50 | 894.26 |
| E | 1893.00 / 2529.00 | 24.23 / 41.64 | 187.88 | 942.28 |
| F | 1589.00 / 2110.00 | 0.00 / 0.00 | 0.00 | 697.10 |
| G | 1359.00 / 1698.00 | 0.00 / 0.00 | 0.00 | 835.27 |

| Case | API response | Worker → commit | Commit → poll observed | Poll → render |
|---|---:|---:|---:|---:|
| A | 129.00 / 605.00 | 1270.59 / 1290.81 | 306.86 / 619.05 | 142.00 / 188.00 |
| B | 131.00 / 272.00 | 1265.46 / 1689.25 | 294.80 / 536.02 | 125.00 / 459.00 |
| C | 130.00 / 201.00 | 1272.69 / 1293.19 | 133.36 / 528.89 | 112.00 / 258.00 |
| D | 135.00 / 268.00 | 1281.21 / 1303.44 | 195.08 / 779.89 | 102.00 / 325.00 |
| E | 141.00 / 188.00 | 1284.35 / 1310.15 | 322.23 / 608.74 | 111.00 / 457.00 |
| F | 127.00 / 301.00 | 1286.42 / 1311.22 | 157.78 / 488.00 | 122.00 / 349.00 |
| G | 124.00 / 154.00 | 1082.09 / 1090.51 | 372.99 / 674.27 | 107.00 / 345.00 |

| Case | Advisory lock | IMAP connect | Ingestion → commit |
|---|---:|---:|---:|
| A | 2.79 / 5.66 | 1204.47 / 1208.24 | 0.00 / 0.00 |
| B | 2.66 / 7.04 | 1205.49 / 1207.80 | 32.86 / 293.34 |
| C | 3.01 / 5.83 | 1205.34 / 1207.35 | 32.33 / 40.79 |
| D | 2.83 / 5.17 | 1208.59 / 1215.71 | 32.48 / 47.68 |
| E | 2.81 / 4.91 | 1211.36 / 1214.88 | 34.96 / 49.58 |
| F | 2.95 / 5.57 | 1212.03 / 1215.69 | 35.36 / 52.42 |
| G | 2.94 / 4.21 | 1012.00 / 1013.86 | 33.72 / 39.57 |


## Post-memory-relief no-change repeat

After the user closed browser tabs, another 30-sample A run (1200ms connect) measured p50 1969ms /
p95 **4508ms**. API response p95 was 1586ms; accepted→worker 205.89ms; worker→completion 1337.81ms;
commit→poll 1254.26ms; poll→render 406ms. This repeat is retained in
`m311-post-memory-manual-a*.json`. More RAM was free, but swap remained almost full and active.
These samples prevent an unconditional pass claim. The outstanding bottleneck is the intermittent
HTTP bootstrap/request/response and frontend-observation tail, not the IMAP execution or durable
queue-intent mechanism. A repeatable acceptance run on a host without memory pressure is still
required; the current evidence does not prove memory pressure is the only possible contributor.

Final quiet watcher resource sample: **30.0002s, 0.06 CPU seconds (0.20% of one core), 65,148 KiB
RSS (63.62 MiB), two watched IMAP connections**. Reproduce with
`docker exec mailcenter-m311-fixture php tests/performance/idle-resource.php` while no benchmark
browser is running. This script also enforces the test database bootstrap guard.


## Final re-run (after refresh-coalescing fix)

The rapid IDLE commit burst previously aborted the in-flight list request and cleared rows, leaving
"Loading mailbox…". `MessageList` now keeps rows visible, lets the active request finish and runs
at most one follow-up first-page refresh; scope changes still abort and discard stale responses.
Files: `m311-final-manual-*.json`, `m311-final-idle-*.json` (30 samples/case, 1200ms connect delay,
same method as above; the host still had ~8GiB swap in use, so this is not a clean-host run).

Manual end-to-end p50 / p95 ms: A 1986/2277, B 1873/2346, C 1780/2058, D 1768/2502, E 1829/2968,
F 1554/2032, G 1394/1742. IDLE Inbox 1893/2176, IDLE Sent 1677/2193. E is close to the limit.
Quiet watcher: 0.07 CPU s over 30s (0.23% of one core), 65,340 KiB RSS, two connections.
Frontend: 49 tests / 9 files pass. Backend: 262 passed, 2 skipped (2127 assertions).


## Real-world smoke-test fixes (realtime latency, flicker, Sent count)

Evidence first. Read-only inspection of real `sync_runs.timings` showed IDLE runs starting within
milliseconds of the worker and committing the first message ~1.8s later, so the ~30s delay was not
the watcher, request, queue or commit. The browser harness (`tests/browser/realtime-acceptance.py`)
reproduced it: a page opened while no watcher lease existed believed the account was *Polling*, and
accounts were only re-read when a mailbox version changed, so that stale belief pinned the 30s
fallback poll indefinitely (before: event->commit 0.16s, commit->observed **23.7s**).

Fixes: `/api/changes` now also returns live watcher health (`realtime`) and whether a sync request
is pending (`active`). A page whose belief disagrees re-reads accounts at once. Accounts that expect
automatic sync but are not watched probe the one-row endpoint every 2s (never the full 30s);
a realtime tab polls at 500ms and at 200ms (bounded to 30s) only while a request is pending. Only the
cheap endpoint is polled; the list refetches only when the version advances.

Flicker causes (each proven): `refreshCounts` set the counts to `null` on every call (sidebar counts
vanished); `MessageReader` reset its iframe height on every version while the iframe did not reload;
`ConversationReader` showed a loading line each time; `MessageList` dropped already-loaded older
pages on refresh. Counts now keep their values and coalesce (one request in flight, one follow-up),
the list refreshes its newest page in place and keeps older pages/cursor, readers keep state and swap
data only when it changed, and manual *Refresh view* refreshes counts explicitly. Per-frame browser
sampling recorded zero frames with fewer rows, a loading placeholder, or missing counts.

Sent 27: the real database contains **27** distinct messages with an active Sent-folder location
(the Gmail Sent folder reports 27, all `From` the account, 2026-08-17 to 2026-09-26), so the count is
correct, not a mapping error. List and counts use the same `MessageView::apply` predicate; the sidebar
Sent badge reads `views.sent.total` while account rows show that account's own total (56 there).
`docs/diagnostics/sent-membership-readonly.sql` reproduces the check read-only.

Two defects found while measuring: (1) `IdleWatcher::state()` wrote `mail_accounts.updated_at`,
which raced the sync engine's `FOR UPDATE` and PostgreSQL detected a **deadlock** that failed an idle
run and put the account into backoff; the write is removed and a deadlock/serialization failure is now
an *aborted* run with a queued continuation, not a failure. (2) `sync:watch` exited 143 on SIGTERM
without releasing leases; a restarted daemon would have been blind for up to 30s. It now uses plain
`pcntl` handlers (regression test spawns the real command). The harness asserts leases are released.

Final acceptance (real Chromium, Horizon, PostgreSQL test DB, synthetic IDLE; 30 samples per warm
case after 3 recorded-but-excluded warm-up events; raw files `docs/benchmarks/m311-rt-*.json`):

| Case (1200ms connect) | event->sync start | sync->commit | commit->observed | observed->rendered | commit->rendered | event->rendered |
|---|---:|---:|---:|---:|---:|---:|
| Inbox, realtime page | 16/22 | 1262/1301 | 169/447 | 111/248 | 273/**571** | 1578/1852 |
| Sent, realtime page | 16/21 | 1273/1310 | 174/318 | 111/212 | 302/**428** | 1616/1741 |
| Inbox, page opened before watcher | 16/796 | 1269/1490 | 188/287 | 115/248 | 305/**446** | 1595/2387 |
| Sent, page opened before watcher | 14/16 | 1264/1524 | 212/311 | 116/130 | 329/**431** | 1625/1801 |
| Inbox, no injected delay | 16/21 | 59/82 | 329/536 | 112/133 | 428/**648** | 504/737 |
| Sent, no injected delay | 14/18 | 71/90 | 147/562 | 113/141 | 279/**702** | 371/899 |

(p50/p95 ms.) Sent counts were exact after every message (start N, N+1, ...) and the sent reply joined
its received conversation. Earlier runs are retained: with the stale-polling page **before** the fix
commit->rendered p95 was 24000ms; a no-warm-up warm run had two first-sample outliers (2.4s, 8.8s) and
another run measured p95 1.3s under host memory pressure. The host still swaps heavily.

Known limitation: the version counter is bumped by database triggers on `mail_accounts` too, so one
IDLE-triggered sync causes several coalesced (bounded) invalidations and 4-9 list/accounts/counts
refetches; they do not re-render identical data. Splitting list and account-status versions would
need a trigger migration and is left out.
