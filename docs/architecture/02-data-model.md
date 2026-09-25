# 02 — Data Model (PostgreSQL)

This is the logical model. Migrations are written in the milestone that first needs a table
(see [09](09-milestones.md)); column names here are normative, types are PostgreSQL types.

Conventions:
- Primary keys: `bigint` identity (`id`). No UUIDs internally; public URLs use numeric ids behind
  authorization policies. (Opaque ids can be added later if needed.)
- Timestamps: `timestamptz`, stored in UTC.
- Every row has an owner directly or through its mandatory parent. Queries scope by the
  authenticated user. v1 supports private users, not shared workspaces (OD-1).
- Enums are `text` columns with a `CHECK` constraint (easier to evolve than PG enum types) and a
  PHP backed enum on the model.
- Required extensions: `citext`, `pg_trgm`, `unaccent`.

## Column ownership (the most important rule in this document)

Every mutable column on `messages` belongs to exactly one writer:

| Group | Writer | May change after insert? |
|---|---|---|
| **Content/identity** | Ingestion pipeline | Only by explicit re-parse (`parser_version` bump) |
| **Remote summary** | Sync engine | Yes, every sync |
| **Local organization** | `OrganizationService` only | Yes, by user actions (and mirrored-flag reconciliation, see [04](04-organization-semantics.md) §3) |

The ingestion pipeline sets *initial* values of local columns at insert time. After that, sync code
must never write them directly; mirrored flags require the account's explicit opt-in. This is enforced by code structure (sync code updates via explicit
column lists) and by tests that run a sync over organized messages and assert nothing changed.

---

## Entity overview

```
users 1─* mail_accounts 1─* remote_folders 1─* message_locations *─1 messages
                     │ 1─* mail_account_credentials                  │ │ │ │
                     └──────────────────────────────── 1─* messages ─┘ │ │ │
users 1─* folders 1─* messages (folder_id)                               │ │ │
users 1─* tags *─* messages (message_tag)                                │ │ │
messages *─1 threads             messages 1─1 message_bodies ────────────┘ │ │
messages 1─* message_participants    messages 1─* attachments *─1 blobs ───┘ │
messages 1─* notes                   messages 1─1 message_search_documents ──┘
messages *─1 blobs (raw_blob_sha256)
users 1─* saved_views, audit_events;  mail_accounts 1─* sync_runs, remote_flag_changes
```

---

## Identity & accounts

### `users`
`id`, `name`, `email citext unique`, `password`, Fortify 2FA columns
(`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`),
`remember_token`, `created_at`, `updated_at`.

### `mail_accounts`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `user_id` | bigint FK users | |
| `provider` | text | `imap` now; `gmail`, `microsoft` later |
| `display_name` | text | e.g. "Support DE" |
| `email_address` | citext | primary address; used for direction detection |
| `aliases` | citext[] | additional own addresses (direction detection, reply-from later) |
| `color` | text | hex; deterministic default, user-editable |
| `short_label` | text | 1–3 chars badge shown on every list row |
| `position` | int | sidebar order |
| `incoming` | jsonb | non-secret: `{host, port, security: "tls"\|"starttls", username, verify_tls: true}` |
| `outgoing` | jsonb null | same for SMTP (used from M6) |
| `enabled` | bool default true | includes synchronized mail in unified views; false suspends all remote jobs |
| `sync_enabled` | bool default true | polling enabled; pausing retains All Mail visibility |
| `sync_interval_seconds` | int default 180 | |
| `sync_since` | date null | optional backfill horizon for huge mailboxes |
| `write_back_seen` | bool default false | opt-in bidirectional read mirroring (from M3) |
| `write_back_flagged` | bool default false | opt-in bidirectional star mirroring (from M4) |
| `seen_mirror_generation`, `flagged_mirror_generation` | bigint default 0 | independent counters incremented on the respective mode change; reject stale queued intent |
| `sync_status` | text | `never_synced`, `idle`, `syncing`, `backing_off`, `auth_failed`, `error` |
| `next_sync_at` | timestamptz null | scheduler uses this; null for auth failure or disabled polling |
| `sync_state` | jsonb | versioned durable cycle ID, phase, folder/chunk cursors and coverage (§03) |
| `last_sync_started_at`, `last_sync_finished_at`, `last_successful_sync_at` | timestamptz | |
| `consecutive_failures` | int default 0 | drives backoff |
| `last_error_code`, `last_error_message`, `last_error_at` | | message is sanitized (never contains credentials) |
| `capabilities` | text[] | cached IMAP CAPABILITY |
| `created_at`, `updated_at`, `deleted_at` | | soft delete; purge is a separate audited action |

Indexes: `(user_id, position)`, `(sync_enabled, next_sync_at) WHERE deleted_at IS NULL`.

### `mail_account_credentials`
Secrets are stored apart from `mail_accounts` so that loading/serializing an account can never
include them.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `mail_account_id` | bigint FK | |
| `purpose` | text | `incoming` \| `outgoing` |
| `kind` | text | `password` now; `oauth2` later |
| `ciphertext` | text | encrypted JSON payload (`{password}` or `{access_token, refresh_token, expires_at}`) |
| `key_id` | text | which credential key encrypted it (rotation) |
| `created_at`, `updated_at` | | |

Unique `(mail_account_id, purpose)`. The model has no public accessors for plaintext; only
`CredentialVault` decrypts.

---

## Remote mailbox state (sync-owned)

### `remote_folders`
One row per IMAP mailbox discovered on the server.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `mail_account_id` | bigint FK | |
| `raw_name` | text | exact mailbox identity; adapter handles negotiated UTF-8 or modified UTF-7, preserving round-trip |
| `name` | text | decoded UTF-8 display path |
| `delimiter` | text null | |
| `role` | text | `inbox`, `sent`, `drafts`, `trash`, `junk`, `archive`, `all`, `flagged`, `important`, `other` (from SPECIAL-USE / name heuristics) |
| `attributes` | text[] | raw LIST attributes |
| `selectable` | bool | false for `\Noselect` |
| `sync_enabled` | bool | default per role, see [03](03-synchronization.md) §2 |
| `uidvalidity` | bigint null | null = never examined |
| `sync_high_uid` | bigint default 0 | high-water UID; coverage plus backfill boundary, including durable quarantines |
| `backfill_low_uid` | bigint null | lowest processed UID; backfill walks downward |
| `backfill_completed_at` | timestamptz null | |
| `highest_modseq` | numeric(20) null | CONDSTORE checkpoint (optimization, M7) |
| `last_flag_scan_at`, `last_reconciled_at` | timestamptz null | completed flag and location scans respectively |
| `reconcile_cycle_id` | bigint null | durable account cycle that fully checked this folder |
| `last_exists` | int null | message count at last examine |
| `last_synced_at` | timestamptz null | |
| `removed_at` | timestamptz null | folder no longer on server |

Unique `(mail_account_id, raw_name)`.

### `message_locations`
Where a logical message currently (or formerly) exists on the server.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `message_id` | bigint FK messages | |
| `mail_account_id` | bigint FK | denormalized |
| `remote_folder_id` | bigint FK | |
| `uidvalidity` | bigint | |
| `uid` | bigint null | IMAP UID |
| `provider_ref` | text null | future: Gmail/Graph message id |
| `flags` | text[] | remote flags as last seen (`\Seen`, `\Flagged`, `\Answered`, keywords…) |
| `modseq` | numeric(20) null | |
| `first_seen_at`, `last_seen_at` | timestamptz | |
| `removed_at` | timestamptz null | set when the location disappears |
| `removed_reason` | text null | `expunged`, `folder_removed`, `folder_unsynced`, `uidvalidity_reset` |

Constraints/indexes:
- Unique `(remote_folder_id, uidvalidity, uid)` — **the primary duplicate barrier for IMAP**.
- Unique `(remote_folder_id, provider_ref) WHERE provider_ref IS NOT NULL` (future providers).
- `(message_id) WHERE removed_at IS NULL`.
- `(remote_folder_id, uidvalidity, uid) WHERE removed_at IS NULL` (expunge diff).

### `sync_runs`
Observability of each account sync execution: `id`, `mail_account_id`, `trigger`
(`schedule`/`manual`/`continuation`), `started_at`, `finished_at`, `status`
(`running`/`succeeded`/`partial`/`failed`), `stats jsonb` (per-folder counts: new, linked, flag
updates, removed, bytes, duration), `error_code`, `error_message`. Pruned after 30 days.

### `sync_failures`
Per-message quarantine so one poisoned message never blocks a folder: `id`, `remote_folder_id`,
`uidvalidity`, `uid`, `attempts`, `last_error`, `first_failed_at`, `last_failed_at`,
`next_attempt_at`, `error_code`, `status` (`retryable`, `manual`, `resolved`, `obsolete`),
`resolved_at`. Unique `(remote_folder_id, uidvalidity, uid)`. Durable quarantine counts as
processed for checkpoint movement but not as a successfully synchronized message; UI exposes gaps.

---

## Messages

### `messages`

**Content / identity** (ingestion-owned)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `user_id` | bigint FK | denormalized owner |
| `mail_account_id` | bigint FK | |
| `thread_id` | bigint FK threads | reassigned only by thread merge |
| `dedupe_key` | text | `raw-v1:` + full raw SHA-256; unique per account, immutable |
| `message_id_header` | text null | normalized, without angle brackets |
| `in_reply_to` | text null | |
| `references` | text[] | normalized ids, capped at 50 |
| `subject` | text | decoded |
| `from_name` | text | |
| `from_address` | citext | |
| `to`, `cc`, `bcc`, `reply_to` | jsonb | `[{name, address}]` for display |
| `date_header` | timestamptz null | as claimed by sender |
| `received_at` | timestamptz null | IMAP INTERNALDATE of first location |
| `sort_date` | timestamptz not null | `received_at` → `date_header` → `created_at`, frozen on insertion |
| `direction` | text | `inbound` \| `outbound` |
| `snippet` | text | ≤ 280 chars of plain text |
| `size_bytes` | int | |
| `has_attachments` | bool | non-inline attachments present |
| `raw_blob_sha256` | char(64) FK blobs | |
| `parse_status` | text | `ok`, `partial`, `failed` (raw kept regardless) |
| `parser_version` | smallint | re-parse when increased |
| `created_at` | timestamptz | first ingestion |

**Remote summary** (sync-owned)

| Column | Type | Notes |
|---|---|---|
| `remote_status` | text | `present`, `unknown` (coverage incomplete), `missing` (confirmed absent within grace), `removed` |
| `remote_missing_since` | timestamptz null | |
| `remote_removed_at` | timestamptz null | |
| `remote_seen` | bool | OR of `\Seen` over active locations |
| `remote_flagged` | bool | OR of `\Flagged` over active locations |

**Local organization** (OrganizationService-owned)

| Column | Type | Notes |
|---|---|---|
| `folder_id` | bigint FK folders not null after M4 | M2 temporarily leaves this nullable because the current M2 brief excludes internal folders; initial placement is applied when folder functionality arrives. |
| `is_read` | bool | initial = `remote_seen` |
| `is_starred` | bool | initial = `remote_flagged` |
| `seen_mirror_generation`, `flagged_mirror_generation` | bigint default 0 | last initialized account mirror generation for each local flag |
| `is_important` | bool | local only |
| `is_done` | bool default false | |
| `done_at` | timestamptz null | |
| `deleted_at` | timestamptz null | local trash; never deletes remotely |
| `local_updated_at` | timestamptz null | last local organization change |
| `local_revision` | bigint default 0 | increment for every organization mutation, including mirrored flags |
| `updated_at` | timestamptz | any change; not a safe changes-feed cursor |

New flags are added as new boolean columns + partial index + entry in the flag registry
(`App\Organization\Flag` enum). Columns rather than an EAV table because list queries filter and
sort on them and need index support.

Indexes (all partial `WHERE deleted_at IS NULL` unless noted):
- Unique `(mail_account_id, dedupe_key)` (not partial) — **duplicate barrier for logical messages**.
- `(user_id, sort_date DESC, id DESC)` — All Mail.
- `(folder_id, sort_date DESC, id DESC)` — folder views, Inbox.
- `(mail_account_id, sort_date DESC, id DESC)` — single-account views.
- `(user_id, sort_date DESC, id DESC) WHERE is_read = false` — Unread.
- Same pattern for `is_starred`, `is_important`, `is_done`.
- `(thread_id, sort_date)`.
- `(mail_account_id, message_id_header)`; GIN `(references)` — threading.
- `(user_id, updated_at)` — diagnostics; freshness uses `user_change_versions` below.
- Trigram GIN on `subject` — substring search.

### `threads`
`id`, `user_id`, `mail_account_id`, `subject_normalized`, `first_message_at`, `last_message_at`,
`message_count`, `created_at`, `updated_at`. Counters maintained in the ingestion transaction.
Threads are scoped per account (see [04](04-organization-semantics.md) §6).

### `message_bodies`
1:1, kept out of `messages` so list queries stay narrow.
`message_id PK`, `text_plain text` (from text part, or derived from HTML), `html_sanitized text
null`, `sanitizer_version smallint`, `remote_content_count int` (blocked remote resources),
`created_at`, `updated_at`. Raw HTML is always re-derivable from the raw blob; when the sanitizer
version increases, reading schedules re-sanitization; escaped plain text is served until current
output exists. MIME parsing and sanitization run in a bounded worker process, never inline on read.

### `message_participants`
`id`, `message_id`, `role` (`from`, `sender`, `to`, `cc`, `bcc`, `reply_to`), `address citext`,
`name text`, `position smallint`. Indexes: `(address)`, `(message_id)`, trigram on `address` and
`name`. Serves recipient/sender search and (M6) address autocomplete. Message-ID normalization
removes syntax whitespace/angle brackets but preserves case; malformed/ambiguous IDs cannot prove
identity or thread membership. Header arrays and parsed display strings have bounded lengths.

### `attachments`
`id`, `message_id`, `blob_sha256` FK blobs, `filename`, `content_type`, `size_bytes`, `disposition`
(`attachment` \| `inline`), `content_id` null (for `cid:`), `mime_part_path` (e.g. `1.2`),
`created_at`. Unique `(message_id, mime_part_path)`; index `(message_id)`, trigram on `filename`.
Content-ID is not unique; ambiguous inline references are blocked rather than resolved arbitrarily.

### `blobs`
Content-addressed files. `sha256 char(64) PK`, `size_bytes`, `disk`, `path`
(`blobs/ab/cd/abcdef…`), `encoding` (`identity` initially), `created_at`. SHA-256 always hashes
original bytes, shared across raw messages and attachments; one blob may serve both roles.
No compression in v1, avoiding format/path ambiguity for the same digest. Files are published
atomically before referencing transactions commit. Unreferenced files/rows are safe orphans;
missing referenced blobs are integrity failures. GC and ingestion serialize by digest using a
PostgreSQL advisory lock held through publication and reference commit, rechecking all references
before removal. Acquire multiple digest locks in sorted order; GC takes no user/domain locks.
Use distinct advisory-lock namespaces for blob, thread and account locks. GC requires a 7-day orphan
grace and never runs during backups or restore. Storage permissions prevent public serving.

---

## Local organization

### `folders`
`id`, `user_id`, `name`, `system_role` null (`inbox`, `sent`, `archive`), `position int`, `color`
null, `created_at`, `updated_at`.
Unique `(user_id, lower(name))`; unique `(user_id, system_role) WHERE system_role IS NOT NULL`.
System folders cannot be deleted or have their role changed; they can be renamed/reordered.

### `tags` / `message_tag`
`tags`: `id`, `user_id`, `name`, `color`, `position`, timestamps; unique `(user_id, lower(name))`.
`message_tag`: `message_id`, `tag_id`, `user_id`, `created_at`; PK `(message_id, tag_id)`, index
`(tag_id, message_id)`.

### `notes`
`id`, `message_id`, `user_id` (author), `body text`, `created_at`, `updated_at`, `deleted_at`.

### `saved_views`
`id`, `user_id`, `name`, `icon` null, `position`, `filter jsonb` (a serialized `MessageFilter`
with `version` field; validated on write), timestamps.

### `remote_content_allowlist` (from M3)
`id`, `user_id`, `address citext`, `created_at`; unique `(user_id, address)`. Senders whose remote
images load automatically (through the image proxy).

### `remote_flag_changes` (write-back outbox, from M3)
`id`, `mail_account_id`, `message_id`, `flag` (`\Seen` \| `\Flagged`), `desired` bool, `status`
(`pending`, `processing`, `done`, `failed`, `superseded`), `attempts`, `last_error`, `created_at`,
`processed_at`, `generation bigint`, `mirror_generation bigint`, `next_attempt_at`,
`lease_expires_at`, `completed_location_ids jsonb`. At most one `pending` row per
`(message_id, flag)` (partial unique index). A newer pending change supersedes the older pending
row; processing rows retain their generation and cannot acknowledge newer intent. See [03](03-synchronization.md) §8.

### Durable UI freshness and organization operations (M3/M4)
`user_change_versions`: `user_id PK/FK`, `version bigint NOT NULL default 0`.
Every transaction affecting API-visible state locks and increments this user's row before its
domain writes. No independent sequence or timestamp is used as a commit cursor. Read version and
related responses in a consistent snapshot. `GET /changes?since=` returns current version and
`invalidate: true|false`; the client refreshes lists, counts, detail, and lookup queries on change.
It is an invalidation protocol, not a row-event log; deletes and account/folder changes are covered.
Lock order for DB mutations is user version row → thread advisory lock if needed → account,
folder, message and operation rows in a fixed table order and ascending ID order within a table.
The network account lock is separate and always acquired before mutation transactions; HTTP
organization writes do not acquire it. Transactions stay short; parsing/network IO precede them.

`organization_operations`: `id`, `user_id`, `idempotency_key`, `action`, `params jsonb`,
`filter jsonb null`, `status` (`preparing`, `ready`, `running`, `partial`, `done`, `failed`,
`expired`), `total`, `completed`, `expires_at`, timestamps; unique `(user_id, idempotency_key)`.
`organization_operation_items`: `(operation_id, message_id) PK`, `status`, `before jsonb`,
`after jsonb`, `applied_local_revision`, `last_error`. Durable selection is prepared before
confirmation; per-item state supports retries and conflict-aware undo without oversized audit rows.

---

## Search

### `message_search_documents`
`message_id PK`, `user_id`, `document tsvector`, `indexed_at`, `index_version smallint`.
GIN index on `document`. Built from subject (weight A), participants names/addresses (B),
attachment filenames (C), body text truncated to 64 KB (D), using the `simple` text search
configuration + `unaccent` (language-neutral; see OD-10). Owned by the `PostgresSearchEngine`
only. Add `body_truncated bool` and GIN trigram indexes for substring fields as specified in
[11](11-search.md). Search documents are rebuilt from retained parsed content, not treated as truth.

---

## Audit

### `audit_events` (append-only)
`id`, `user_id` null, `actor_type` (`user`, `system`, `sync`, `rule`), `action` (e.g.
`folder.deleted`, `messages.moved`, `messages.deleted`, `account.credentials_changed`,
`account.deleted`, `sync.uidvalidity_reset`), `subject_type`, `subject_id`, `context jsonb`
(before/after values, affected message ids — capped, with total count), `ip`, `user_agent`,
`created_at`. The application never updates or deletes rows (enforced in model + optionally via DB
grants). Index `(user_id, created_at DESC)`, `(subject_type, subject_id)`.

---

## Sending (M6, sketch only — finalize in M6)

`outgoing_messages`: `id`, `user_id`, `mail_account_id`, `mode` (`new`, `reply`, `reply_all`,
`forward`), `source_message_id` null, `message_id_header` (generated at creation, stable),
recipients jsonb, `subject`, `body_html`, `body_text`, `status` (`draft`, `queued`, `sending`,
`sent`, `failed`, `unknown`), `attempts`, `last_error`, `sent_at`, `sent_message_id` FK messages
null, timestamps. `outgoing_attachments`: `outgoing_message_id`, `blob_sha256`, `filename`,
`content_type`, `size_bytes`, `source_attachment_id` null.

## Laravel infrastructure tables
Sessions, cache and advisory Redis locks use Redis (no SQL tables). `failed_jobs` and
`job_batches` use PostgreSQL. Framework migrations must match the chosen drivers.

### Durable operational work (introduced when used)
`connection_tests` (M2): `id`, `user_id`, `encrypted_settings`, `key_id`, `status`
(`pending`, `running`, `succeeded`, `failed`, `expired`), `expires_at`, `lease_expires_at`,
`result_code`, `created_at`, `finished_at`. Jobs carry only the ID; clear encrypted settings on
completion/expiry and sweep expired leases. This temporary secret uses the credential keyring.
`maintenance_runs` (M3 re-sanitization; M5 reindex): `id`, `kind`, `scope jsonb`, `target_version`,
`cursor jsonb`, `status`, `lease_expires_at`, `next_attempt_at`, `stats jsonb`, timestamps.
One active run per kind/scope/version; sweeper redelivers abandoned work. Sanitizer jobs also
compare the content/parser version before replacing derived output. Bulk operation jobs have
their own durable operation/item records, not solely Redis progress.

## Relational integrity and deletion rules

Use composite uniqueness and FKs to prevent cross-owner/account links: `mail_accounts(id,user_id)`,
`folders(id,user_id)`, `threads(id,mail_account_id,user_id)`, `messages(id,mail_account_id,user_id)`,
and `remote_folders(id,mail_account_id)`. A message's folder, account and thread must match its
owner; location folder and message must match its account. Tag pivots carry `user_id` with
composite FKs to tag and message; notes' author must own the message in v1. Authorization remains
mandatory even with DB constraints. Shared blobs are reachable only through authorized messages.

IMAP locations require non-null positive UID/UIDVALIDITY (unsigned 32-bit range represented as
bigint). Future provider locations use a distinct identity discriminator and constraints; nullable
provider placeholders alone are not a complete implementation. Mandatory scalars are NOT NULL;
optional fields are explicitly nullable; counters default zero and cannot be negative.

Account deletion is soft deletion: disable jobs, retain all owned rows and hide from mail views.
Folder and blob FKs restrict deletion. Delete a custom folder only after reassignment. Message
children cascade only in the explicit purge service (M7); sync never hard-deletes messages.
Done consistency: `is_done = (done_at IS NOT NULL)`. Boolean organization flags default false; initial
read/star values come from the first location. Notes are escaped text, never executable markup.

Malformed headers must not prevent storing safely fetched raw mail: display subject/from fields
default to empty strings with UI placeholders, date and reference fields may be null/empty, and
`parse_status` exposes the failure. `direction` is a display heuristic (Sent role first, then
own-address match); it never authorizes sending, credential access, or sender image trust.
