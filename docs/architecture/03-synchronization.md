# 03 — Mail Synchronization

Scope: IMAP receive/sync (M2), flag write-back (M3/M4). SMTP sending is covered in M6 notes at
the end. Terms: *folder* in this document means a **remote IMAP mailbox** (`remote_folders`),
never an internal application folder.

## 0. Invariants (every implementation must preserve these; each has a test)

- **I1 — Read-only by default.** Sync opens mailboxes with `EXAMINE` (read-only) and fetches with
  `BODY.PEEK[...]`, so syncing never changes `\Seen` or anything else on the server.
- **I2 — Forbidden remote commands.** The application never issues `EXPUNGE`, `UID EXPUNGE`,
  `CLOSE` (it expunges implicitly), `MOVE`, `COPY`, `DELETE`, `RENAME`, `CREATE`, or `STORE` of
  `\Deleted`. The `ImapClient` interface does not even expose them. The only remote writes are
  `UID STORE ±FLAGS.SILENT (\Seen|\Flagged)` (write-back) and, in M6, `APPEND` to Sent.
- **I3 — No duplicate locations:** unique `(remote_folder_id, uidvalidity, uid)`.
- **I4 — No duplicate logical messages:** unique `(mail_account_id, dedupe_key)`.
- **I5 — Checkpoints advance only after the data they cover is committed.**
- **I6 — Re-running any sync step with the same remote state creates no duplicate domain data
  or repeated local action.** Operational timestamps/run statistics may still advance.
- **I7 — Sync never writes existing local-organization columns** except opt-in mirrored-flag
  reconciliation through `OrganizationService`. Initial placement happens only at insertion.
- **I8 — Nothing local is ever hard-deleted by sync.** Remote disappearance only changes
  `remote_status`.

## 1. Scheduling and job model

```
scheduler (every minute)
  └─ sync:dispatch-due
       selects accounts: enabled AND sync_enabled AND deleted_at IS NULL
                         AND sync_status <> 'auth_failed' AND next_sync_at <= now()
       dispatches SyncAccountJob(accountId)  [ShouldBeUnique per account]

SyncAccountJob (queue "sync", timeout 600 s, tries 1)
  middleware: shared WithoutOverlapping("imap-account:{id}")->expireAfter(900)
  1. create sync_runs row, set sync_status = syncing
  2. connect + authenticate (CredentialProvider)
  3. start or resume durable cycle; discover folders only at cycle start (§2)
  4. for each enabled folder, in priority order inbox, sent, others:
       examine + UIDVALIDITY check             (§3.1, §6)
       fetch new messages above sync_high_uid  (§3.2)
       expunge detection                       (§3.4)
       flag scan if due                        (§3.5)
  5. retry quarantined UIDs (sync_failures)    (§7)
  6. backfill round-robin across folders until time budget (default 240 s) is used  (§3.3)
  7. reconciliation if eligible                (§5)
  8. finish sync_run; set next_sync_at:
       work remaining → persist next_sync_at = now() (scheduler dispatches continuation)
       otherwise          → now() + sync_interval_seconds
```

Why one job per account (not per folder): IMAP servers limit concurrent connections per user
(often 10–20, sometimes fewer), steps share one authenticated connection, and a single job per
account makes locking and reconciliation trivial. Parallelism comes from syncing many accounts
concurrently. Why `tries = 1`: the job is checkpointed and idempotent; the scheduler is the retry
mechanism with proper backoff (§7), so Laravel-level retries would only add uncontrolled hammering.

Redis uniqueness has a finite 900 s TTL and is only an optimization. All account sync and
write-back jobs also acquire the same **session-level PostgreSQL advisory lock** on a dedicated
direct connection before opening IMAP; hold it through network work, release in `finally`.
No transaction remains open during network IO. If the DB session/lock is lost, close IMAP and
abort immediately. No PgBouncer transaction pooling on this connection. This prevents a Redis
restart from admitting two live account writers. Before each network batch recheck account
enabled/deleted state and the relevant sync/mirroring setting. Local flag mutations can continue;
outbox generations (§8) resolve those races. Acquiring locks must be non-blocking with rescheduling.

Use a 240 s soft work budget, 30 s socket operation timeout, and 600 s hard job timeout; stop
starting batches before the soft budget is exhausted. `mail_accounts.sync_state` persists a
versioned cycle ID, phase, folder cursor, numeric UID scan bounds, and last completed chunks.
Cycles span jobs: discovery → new UID windows → expunge/flags → fair backfill → reconciliation.
Each continuation resumes its phase; it does not restart at Inbox. Folder snapshots and completed
coverage belong to the cycle. Periodic incremental windows get priority but cannot starve a
persisted scan/backfill cursor. Reserve at least half each continuation's work budget for its
unfinished phase; newly due incremental windows consume the remainder in round-robin folder order.
A folder toggle, UIDVALIDITY reset or changed discovery inventory invalidates affected cycle
coverage; no old cycle completion can prove absence in the new configuration.
No unbounded `SEARCH ALL` array is required: search bounded numeric
UID windows (initial 10,000 UID span, adapt within budget), and discard out-of-window results.

**Stale-run detection:** `sync:dispatch-due` also treats an account whose status is `syncing` with
`last_sync_started_at` older than 20 minutes as crashed: closes the open `sync_runs` row as
`failed` and makes it due again.

**Manual sync:** `POST /api/accounts/{id}/sync` sets `next_sync_at = now()` and dispatches; the
uniqueness guard makes repeated clicks harmless.

Manual requests respect authentication/configuration backoff and rate limits. Replacing credentials
clears `auth_failed`, resets failures and schedules a connection attempt. Connection tests are
queued with short-lived encrypted inputs, never plaintext credentials in Redis or request logs.
Lost dispatches are recovered from due timestamps; do not dispatch a unique continuation from
inside the still-unique job and assume it will be accepted.

## 2. Folder discovery

Each cycle: `LIST "" "*" RETURN (SPECIAL-USE)` (or plain `LIST` + name heuristics when SPECIAL-USE is
unsupported). Upsert `remote_folders` by `(mail_account_id, raw_name)`; set `removed_at` on rows no
longer listed (and mark their active locations removed with reason `folder_removed`); clear
`removed_at` if a folder reappears (UIDVALIDITY check will handle the rest).

Only a fully successful tagged LIST result proves absence. A timeout, denied EXAMINE, or partial
response never proves folder deletion. Renames appear as old/new names; content identity links
copies after discovery. A reappearing or re-enabled folder resets coverage for a full UID inventory,
even when UIDVALIDITY is unchanged, so old locations can become active again.

Default `sync_enabled` for newly discovered folders:

| Role | Default | Reason |
|---|---|---|
| `inbox`, `sent`, `archive`, `other` | on | They contain the user's mail; All Mail must be complete. |
| `trash`, `junk` | off | Deleted/spam mail should not populate All Mail. |
| `drafts` | off | Drafts are managed locally (M6). |
| `all` (Gmail "All Mail") | on | May contain archived mail reachable through no other folder. |
| `flagged`, `important` | off | Optional duplicate views; account settings disclose exclusions. |
| `\Noselect` | off, not selectable | |

The user can change per-folder sync in account settings. Disabling a folder marks its active
locations removed with reason `folder_unsynced` (local data untouched).

All Mail promises all **synchronized** messages, not unread/unfetched remote mail. Account status
shows excluded folders, date horizon, incomplete backfill and quarantined UIDs. Disabling a folder
or changing the horizon never deletes already synchronized messages. Widening the horizon resets
backfill coverage; narrowing only limits future historical fetches. Remote `\Deleted` is recorded
as a flag, not treated as local deletion or expunge; present remote Trash/Junk copies remain visible
if those folders are explicitly synchronized.

## 3. Per-folder algorithm

### 3.1 Examine

`EXAMINE <raw_name>` → `UIDVALIDITY`, `UIDNEXT`, `EXISTS`, (`HIGHESTMODSEQ` if CONDSTORE).
- `uidvalidity` null (first time): store it and capture `initial_high = UIDNEXT - 1`.
  Set `sync_high_uid = initial_high`, `backfill_low_uid = initial_high + 1` atomically (no UIDs
  covered yet; backfill owns the older range). Empty mailbox uses high 0 / low 1. UID gaps are
  normal. Backfill coverage includes committed messages, durable failures, and confirmed gaps.
- `uidvalidity` differs from stored: UIDVALIDITY reset (§6).

### 3.2 Incremental: new messages

Capture `upper = UIDNEXT - 1`; search bounded UID windows between `sync_high_uid + 1` and upper.
Do nothing if lower > upper; do not send a reversed range or UID 0. Process ascending (§3.6).
After each committed batch or successfully searched empty window, advance the covered high-water
mark. Never advance across a failed SEARCH. Mail arriving above upper belongs to the next window.
Discard out-of-window UIDs defensively (`n:*` has surprising behavior on rev1 servers).

### 3.3 Backfill: older messages, newest first

Search bounded windows below `backfill_low_uid`, newest first, adding `SINCE` if configured
(IMAP INTERNALDATE calendar-date semantics, not the sender Date header). After the window's
UIDs are committed/quarantined, move `backfill_low_uid` to its lower bound, including empty gaps.
At low 1, finish without issuing `1:0`. Set `backfill_completed_at` only when the whole configured
range has been covered. Checkpoints and failure records commit before coverage advances.

Newest-first means the unified view becomes useful within minutes even for 100k-message
mailboxes, while backfill continues in continuation jobs. Round-robin across folders prevents one
huge folder from starving others.

### 3.4 Expunge detection (each completed cycle)

Never infer unchanged membership from equal `EXISTS` counts: additions can balance removals.
For each bounded covered UID window, perform a successful UID SEARCH and compare it to active
locations in the same folder/UIDVALIDITY/window. Mark missing locations `expunged` only after the
command completes successfully. Persist the scan cursor; finalize `last_reconciled_at` and
`reconcile_cycle_id` after every window. Any failed window leaves coverage incomplete.
Affected messages become `unknown` when no active location remains until reconciliation (§5).

(M7 optimization: with QRESYNC, `VANISHED` responses replace the diff.)

### 3.5 Flag scan

- Without CONDSTORE (baseline, M2): every `flag_scan_interval` (default 15 min) run
  `UID FETCH <low>:<high> (UID FLAGS)` in chunks of 5 000 UIDs and update `message_locations.flags`
  where changed. Fetch explicit known UID sets and commit a durable chunk cursor; only mark
  `last_flag_scan_at` when all chunks complete. Disappearance is handled by UID membership scans.
- With CONDSTORE (M7 optimization): `UID FETCH 1:* (UID FLAGS) (CHANGEDSINCE <highest_modseq>)`
  every run, then store the new `highest_modseq`.

For each message whose locations' flags changed: recompute `remote_seen` / `remote_flagged`
(OR over active locations) and, if the **remote aggregate value changed compared to its previous
value**, hand it to `OrganizationService::applyRemoteFlagChange()` (see §8 and
[04](04-organization-semantics.md) §3). Comparing remote-old vs remote-new (not remote vs local)
ensures that a local state which intentionally differs (write-back disabled) is not overwritten
on every scan.

### 3.6 Batch processing (used by 3.2, 3.3, and §6)

Batch size: up to 100 UIDs or ~25 MB by `RFC822.SIZE`, whichever is smaller.

1. **Envelope fetch:** `UID FETCH <set> (UID FLAGS INTERNALDATE RFC822.SIZE
   BODY.PEEK[HEADER.FIELDS (MESSAGE-ID FROM DATE SUBJECT)])`.
2. **Known location** by folder + UIDVALIDITY + UID: update flags and reactivate it if removed;
   its existing message link is authoritative. No body download is needed.
3. **Unknown location:** headers are hints only, not identity. It must be fetched and hashed
   before linking to a logical message (§4), unless a future provider supplies a verified stable ID.
4. `UID FETCH <uid> (BODY.PEEK[])`, streamed to a temp file (never fully in
   memory for large messages). The selected M2 pure-PHP adapter currently materializes one literal
   before handing it to the blob store, so M2 temporarily caps it at 10 MB after checking
   `RFC822.SIZE`; larger messages are visible `too_large` quarantines. Streaming fetch and the
   original 100 MB target remain an adapter release gate, not a hidden memory-risk acceptance.
   Messages above `max_message_bytes` are recorded in
   `sync_failures` with reason `too_large` and surfaced in account status.
5. **Ingest** (§4.2) in one DB transaction per message.
6. Per-message failure (fetch or parse crash) → upsert `sync_failures`, continue with the next UID.
   A parse *error* that still yields headers is not a failure: the message is stored with
   `parse_status = partial|failed` and the raw blob, so it is visible and re-parseable later.
7. After all UIDs of the batch are committed or durably quarantined → advance the checkpoint (I5).
   A UID expunged between SEARCH and FETCH is confirmed by a successful UID existence search;
   it is a covered absence, not an endless parse retry. Failure to persist quarantine aborts the
   batch. The parent worker persists an active UID/attempt in `sync_state` before starting a
   bounded parser subprocess. On parser OOM/timeout with complete raw bytes retained, commit a
   minimal `parse_status=failed` message. On parent crash, recovery records an interrupted attempt
   in `sync_failures`; after five attempts quarantine it for manual recovery and advance coverage
   only after that record commits. One poisoned UID must not crash every future run.

A crash anywhere in a batch leaves the checkpoint at the previous value; the next run re-processes
the batch; I3/I4 + content-addressed blobs make that a no-op for already committed messages.

## 4. Ingestion and duplicate prevention

### 4.1 Dedupe key

```
dedupe_key = "raw-v1:" + sha256(original_rfc822_bytes)
```

Message-ID, From, Date, Subject, and size can all match while bodies differ. Header fingerprints
must never suppress a download or merge messages. The safe baseline merges byte-identical copies
within one account. Stable location uniqueness prevents duplicates on retries; full-content
identity preserves local organization on byte-preserving copies, moves, and UIDVALIDITY resets.
If a server rewrites MIME, v1 retains separate messages instead of guessing and losing content.
This is an explicit limitation, not a claim of universal semantic deduplication; OD-13 tracks it.

Rules:
- The key is computed from a complete raw stream; interrupted downloads never become blobs.
- `raw-v1` semantics are frozen. Future provider identity or a proven content canonicalization
  needs a versioned alias/migration design preserving all messages and local metadata.
- Scope is per account. The same email received by two connected accounts is two messages — the
  UI must show which account received it.
- Byte-identical Sent and Inbox copies within an account merge; delivery headers can make
  self-sent copies different. Both are retained in that case. A byte-identical intentional resend
  is one logical message with multiple locations; locations preserve the individual deliveries.

### 4.2 Ingestion pipeline (provider-independent)

Input: raw MIME stream + `RemoteLocationRef` (folder, uidvalidity, uid/provider_ref, flags,
internal date).

1. Hash the stream (sha256) and publish the complete bytes through a temp file on the same
   filesystem and atomic rename to `blobs/ab/cd/<sha>`; verify size/hash on an existing object.
   Coordinate digest locks with GC; `INSERT INTO blobs … ON CONFLICT DO NOTHING`. Idempotent.
2. Parse with `mail-mime-parser`: headers, participants, text/plain, text/html, attachments
   (each attachment stored as its own blob, same procedure), inline parts with Content-ID.
3. Sanitize HTML ([05](05-security.md) §4) → `html_sanitized`, `remote_content_count`.
4. DB transaction:
   - `INSERT INTO messages … ON CONFLICT (mail_account_id, dedupe_key) DO NOTHING RETURNING id`.
     If nothing returned → link/reactivate location on the existing message, preserve local state.
   - Insert `message_bodies`, `message_participants`, `attachments`, `message_locations`.
   - Initial local values: `folder_id` from location role ([04](04-organization-semantics.md) §2.3),
     `is_read = \Seen present`, `is_starred = \Flagged present`.
   - Thread assignment (§4.3), summaries, and user change-version increment in the same transaction.
   - (From M5) upsert search document.
   - Dispatch `MessageIngested` domain event **after commit** (future rules/classification seam;
     no listeners initially).

### 4.3 Thread assignment

Within the same account, under a thread-specific advisory lock (`pg_advisory_xact_lock`, distinct
namespace from the network account lock, acquired after the user's change-version row):
1. Candidate parents: messages whose `message_id_header` ∈ (`in_reply_to` ∪ last 20 `references`).
2. Candidate children: messages whose `references` or `in_reply_to` contain this message's
   Message-ID (they arrived first, common during newest-first backfill).
3. No candidates → new thread. One thread → join it. Several threads → merge into the oldest
   thread (re-point `thread_id`, delete emptied threads, recompute counters).

No subject-only threading in v1 (false merges are worse than split threads). Threads never span
accounts.

Only syntactically valid reference IDs participate. If an ID identifies multiple distinct messages,
do not merge threads through that ambiguous ID; show separate threads. Ignore self-reference,
cap traversal at 100 related messages per synchronous resolution, and queue bounded repair for
overflow. Thread merges preserve message IDs, folders, tags and notes. Late parent ingestion
repairs child threads; parsing again must be idempotent with the same attachment part keys.

## 5. Reconciliation of remote disappearance

Runs at the end of a durable account cycle only when **every enabled folder has completed
backfill and passed expunge detection in that cycle**. The cycle may span many jobs; failed LIST,
failed windows or an unresolved UID quarantine prevent claiming complete account coverage.
Already linked messages can be present during incomplete coverage; absence stays `unknown`.

- Message `unknown`/`present` with zero active locations → `missing`, `remote_missing_since = now()`.
- Message `missing`/`removed` with ≥1 active location → `present`, timestamps cleared.
- Message `missing` for longer than `remote_removal_grace` (default 24 h) **and** still without
  locations in a later complete cycle → `removed`, `remote_removed_at = now()`.

The grace period absorbs remote moves where the destination folder is examined in a later run,
and transient server glitches. Candidates are the message ids collected during §3.4/§2/§6 plus a
nightly full check. Nothing is deleted; visibility rules for `removed` are in
[04](04-organization-semantics.md) §4.

A message moved remotely into a folder that is not synced (e.g. Trash) is, from our perspective,
removed — this is intended.

## 6. UIDVALIDITY reset

When `EXAMINE` returns a different `UIDVALIDITY`:
1. In one transaction: set `removed_at` (reason `uidvalidity_reset`) on all active locations of
   the folder; store the new `uidvalidity`; reset `sync_high_uid`, `backfill_low_uid`,
   `backfill_completed_at`, `highest_modseq` to the first-time state (§3.1); write
   `audit_events` `sync.uidvalidity_reset`. Mark affected messages with no remaining location
   `unknown`, clear old absence timers and invalidate folder/cycle reconciliation coverage.
2. The folder re-syncs via normal backfill. Unknown UIDs require raw downloads and hash comparison;
   identical content re-links to existing messages with all local organization preserved. Old
   failure records become `obsolete`; no old UID may be used to address the new generation.
3. Reconciliation is automatically suspended until backfill completes (§5 precondition).

## 7. Failure handling and recovery

| Failure | Classification | Behavior |
|---|---|---|
| Authentication rejected | `auth_failed` | Stop scheduling (`next_sync_at = null`); UI asks for new credentials. No retries (avoid provider lockouts). |
| DNS/connect/TLS handshake/timeout | transient | `backing_off`; `next_sync_at = now + backoff(consecutive_failures)`: 1, 2, 5, 10, 30, 60 min (cap), ±10 % jitter. |
| TLS certificate invalid | configuration | `error`; retry hourly; clear message in UI. |
| Server throttling (`[UNAVAILABLE]`, `[LIMIT]`, too many connections) | transient | backoff as above. |
| Folder-level (`NONEXISTENT`, `NO` on EXAMINE) | folder | skip and report partial coverage; only a successful full LIST proves folder absence. |
| Single message fetch/parse crash | message | `sync_failures` quarantine; retried in later runs with backoff up to 5 attempts, then left for manual action; shown in account status. |
| Database error / deadlock | run | transaction rolled back, checkpoint unchanged, run ends `failed`, next run resumes. |
| Unexpected exception (bug) | run | same as above + logged with context (never credentials) + Horizon failed job. |
| Worker killed / OOM / deploy | run | lock expires (900 s), stale-run detection (§1) re-dispatches; checkpoints guarantee resume. |

A fully successful cycle resets `consecutive_failures` and sets `sync_status = idle`; partial
folder failures retain error/backoff state and do not falsely report success. Every run is
recorded in `sync_runs` with per-folder statistics so problems are diagnosable from the UI.

## 8. Remote flag write-back (M3 for `\Seen`, M4 for `\Flagged`)

Durable outbox (D14):
1. `OrganizationService` changes `is_read`/`is_starred` locally, and — if the account has the
   corresponding `write_back_*` enabled — inserts a `remote_flag_changes` row (superseding any
   pending row for the same message+flag) and dispatches `PushRemoteFlagChangesJob(account)`
   after the same DB transaction commits (queue `writeback`, unique per account, same shared
   `imap-account:{id}` Redis and PostgreSQL account locks as sync).
2. The job loads pending rows, groups them by remote folder and desired state, `SELECT`s the folder
   (the only place `SELECT` is used), verifies UIDVALIDITY still equals each targeted location,
   checks writable flags, and issues `UID STORE <uids> +FLAGS.SILENT (\Seen)` / `-FLAGS…`
   for active locations. On mismatch abort that folder and schedule resync, never STORE stale UIDs.
   Leave with `UNSELECT` or disconnect — **never `CLOSE`**, including library destructors.
   Re-fetch flags after STORE before acknowledging; partial success records completed location IDs.
3. Failures: `attempts++`, exponential backoff; after 10 attempts → `failed`, visible in account
   status. No active locations → keep pending while coverage is unknown; confirmed removed →
   mark superseded/no target with a visible explanation. Never claim an unperformed write succeeded.
4. A sweeper (`writeback:sweep`, every 5 min) re-dispatches accounts with pending rows older than
   2 minutes or expired processing leases (covers lost Redis jobs and crashed workers).

Each mutation locks the message and assigns a monotonic per-message/flag intent generation;
store the account's generation for that flag too. Claim processing rows with a bounded lease in a short
transaction; no DB transaction spans IMAP IO. A worker only acknowledges its own generation.
New intent supersedes older pending rows; an in-flight older command may finish, but newer intent
is replayed afterward and remains protected from remote reconciliation. Recheck current intent
and account settings before each batch. Reapplying a +/- flag operation is idempotent. Retryable
failure persists `next_attempt_at`; terminal failure retains local state and offers retry or
explicit acceptance of remote state. Failed intent is not silently discarded on the next scan.

**Conflict rule:** during a flag scan, if a `pending`/`processing` outbox row exists for
(message, flag), or a failed unresolved intent exists, the remote value is not applied locally.
Otherwise a changed remote aggregate is applied locally only when mirroring is enabled. Do not
derive an unread/unstarred transition from removal of the last location: its flags are unknown.
When the message's initialized generation differs from the account's current enabled generation,
adopt the first complete remote flag observation even if the aggregate did not change; then stamp
that generation. New local intent in the generation takes precedence and stamps it immediately.
Successful write-back stamps the same generation without overwriting newer local intent. All
mode changes and initialization happen through OrganizationService, not direct sync column writes.

## 9. Queues (Horizon)

| Queue | Jobs | Processes (initial) | Timeout |
|---|---|---|---|
| `sync` | `SyncAccountJob` | 4 (tune to CPU/accounts) | 600 s |
| `writeback` | `PushRemoteFlagChangesJob` | 2 | 120 s |
| `default` | UI-triggered async work (large bulk actions by filter) | 2 | 300 s |
| `send` (M6) | `SendOutgoingMessageJob` | 2 | 120 s |
| `maintenance` | reparse, reindex, blob GC | 1 | 3600 s |

Use separate Redis queue connections with `retry_after` values 660/180/360/180/3660 s for the
five rows respectively, or one shared connection at 3660 s with slower crash redelivery. Worker
timeout < retry_after, Redis lock TTL > worker hard timeout, and container stop grace > timeout.
Queue priorities must not starve sync behind maintenance; dedicate supervisors and cap per-account
dispatch. Keep queue Redis on `noeviction`; cache uses a separate instance. Workers are recycled with `--max-jobs` /
`--max-time` to contain memory growth from MIME parsing.

All durable-work sweepers run each minute (write-back may use its five-minute interval above),
select due/unleased rows, and redispatch by ID. Include bulk operations, connection tests,
maintenance runs and outgoing messages when those milestones introduce them. A failed jobs row
is diagnostic; it is not the only recovery mechanism. Auth failures pause flag writes as well as
sync; credential replacement wakes both. Revoked/disabled accounts cannot start new remote work.

### M2 implementation notes

- The Horizon connection is named `mail_sync` (queue `sync`, `retry_after` 660 s) so that it does
  not shadow Laravel's built-in `sync` queue driver.
- M2 synchronizes **INBOX only** by default: other folders are discovered and listed, but
  `sync_enabled` starts false for them (`PATCH /api/remote-folders/{id}` opts in). Sent/other-folder
  behaviour above is implemented but not enabled by default because the M2 brief scopes to INBOX.
- A run makes repeated passes over the enabled folders (one UID window per folder per pass)
  until the 240 s budget ends; checkpoints (`sync_high_uid`, `backfill_low_uid`, membership
  cursor) are written after each completed window, or after the last completed message when the
  budget ends mid-window. Quarantined UIDs are retried at the start of each folder pass.
- `SyncRunner` classifies failures (`ImapFailure` categories) into the statuses of §7 and never
  logs exception text or traces for unexpected errors, because library traces can carry
  connection arguments. Unexpected errors are recorded on the account and retried with backoff
  rather than rethrown, so they do not reach Horizon's failed-jobs list.
- The advisory lock is verified against `pg_locks` before every batch, not just by pinging the
  connection, because a silent reconnect would keep querying without owning the lock.
- Connection tests use the default `redis`/`default` queue; the job carries only the row id.

## 10. Future providers (compatibility notes only)

Gmail API / Microsoft Graph drivers implement `AccountSyncDriver`, store their incremental cursor
(history id / delta link) in a new `mail_accounts.provider_state jsonb`, map labels/folders to
`remote_folders`, use `message_locations.provider_ref` instead of UIDs, fetch raw MIME, and call the
same ingestion pipeline. Local organization and UI contracts remain unchanged; provider drivers
supply their own complete-coverage/removal proofs and stable identity mapping. Future schemas
must allow provider locations without IMAP UIDVALIDITY and preserve provider IDs across moves;
adding provider_ref alone does not make a connector correct.
OAuth XOAUTH2 over IMAP/SMTP only requires a new `CredentialProvider` kind.

## 11. Sending (M6, summary)

Durable state machine in `outgoing_messages`: `draft → queued → sending → sent | failed`. The
`Message-ID` is generated when the draft is created. If a worker dies while `sending`, the row
becomes `unknown` and the user decides whether to resend — SMTP is not idempotent, so the system
never resends automatically. After success the composed MIME is ingested as an outbound message in
the local Sent folder. A later byte-identical remote copy links by hash; rewritten transport copies
require the M6 sent-copy correlation design and must not be merged on Message-ID alone.
An ambiguous disconnect after SMTP DATA is `unknown` too, not an automatically retried failure.
Persist the composed MIME before sending; Sent APPEND has independent durable status and cannot
cause SMTP resend. Whether to `APPEND` to remote Sent is a per-account setting (some providers,
e.g. Gmail, save sent mail automatically) — see OD-12.

## Protocol references

UID identity, EXAMINE, PEEK, mailbox selection and flag commands follow
[IMAP4rev2, RFC 9051](https://www.rfc-editor.org/rfc/rfc9051.html); generic connectivity must
also negotiate IMAP4rev1 capabilities rather than assume rev2. Incremental MODSEQ/VANISHED
optimizations require [CONDSTORE/QRESYNC, RFC 7162](https://www.rfc-editor.org/rfc/rfc7162.html).
They are optional; baseline correctness must not depend on either extension.
