# 04 — Remote State vs. Local Organization

## 1. The boundary

| Concept | Remote (server truth, sync-owned) | Local (application truth, user-owned) |
|---|---|---|
| Where a message lives | `message_locations` → `remote_folders` | `messages.folder_id` → `folders` |
| Existence | `remote_status` (`present`/`missing`/`removed`) | `deleted_at` (local trash) |
| Read | `\Seen` in `message_locations.flags`, `remote_seen` | `is_read` (mirrored, §3) |
| Star | `\Flagged`, `remote_flagged` | `is_starred` (mirrored, §3) |
| Important, done, tags, notes | — | local only |
| Content | raw blob + parsed rows (immutable) | — |

Rules:
1. **Remote folders and internal folders are unrelated.** Remote folders are an input for sync
   and for the one-time initial placement (§2.3). Moving a message between internal folders never
   touches the server, and a remote move never changes the internal folder.
2. The only remote side effects of user actions are the optional `\Seen`/`\Flagged` write-backs
   ([03](03-synchronization.md) §8) and, in M6, sending/`APPEND`. Everything else is local.
3. Server-side IMAP folder synchronization (mapping internal folders to remote folders) is a
   future feature. If added, it will be an explicit, per-account opt-in with its own outbox — not a
   change to these semantics.

## 2. Internal folders

### 2.1 Kinds
- **System folders** (`system_role`): `inbox`, `sent`, `archive`. Created for each user at signup.
  Can be renamed and reordered, cannot be deleted.
- **Custom folders**: created by the user. Seeded on signup (configurable seed list):
  `Reloads`, `Support`, `Withdrawals`, `Verification`, `Done`. The Done folder and done state
  are distinct (§3.2); the flag-based view is labelled **Completed** to avoid duplicate labels.

Every message has exactly one `folder_id` (NOT NULL). Folders are flat in v1 (no nesting; add
`parent_id` later if needed).

### 2.2 Operations
- **Move** (single, multi-select, drag & drop, bulk by filter): sets `folder_id`,
  `local_updated_at`; one `audit_events` row per operation (`messages.moved`, with previous
  folder per message id stored separately in operation items for undo).
- **Create / rename / reorder**: `position` is an integer; reorder sends the full ordered id list
  (`PUT /api/folders/order`), applied in one transaction.
- **Delete custom folder**: in one transaction, move all its messages to the system Inbox folder,
  then delete the folder, and write `folder.deleted` audit event with count and bounded ID sample.
  **Emails are never deleted by deleting a folder.** The UI confirmation states how many messages
  will return to Inbox.

### 2.3 Initial placement (set once at ingestion)

| First remote location role | Initial internal folder |
|---|---|
| `inbox` | Inbox |
| `sent` | Sent |
| anything else (`archive`, `other`, …) | Archive |

The first committed location determines placement; discovery processes Inbox before Sent before
other roles. Linking later copies or discovering remote moves never changes `folder_id`. An
archived copy first discovered before an Inbox copy may therefore remain in Archive; the user can
move it locally. This explicit behavior avoids silently reorganizing existing messages.

New mail in an existing thread lands according to this table (normally Inbox), regardless of
where earlier messages of the thread were filed — see OD-5.

## 3. Flags

### 3.1 Registry
`App\Organization\Flag` enum lists all boolean flags with metadata: column name, whether it is
mirrored to a remote flag, and whether it has a system view. Initial set:

| Flag | Column | Remote mirror | Notes |
|---|---|---|---|
| Read | `is_read` | `\Seen` (if `write_back_seen`) | |
| Starred | `is_starred` | `\Flagged` (if `write_back_flagged`) | |
| Important | `is_important` | — | Local; unrelated to Gmail "Important". |
| Done | `is_done` + `done_at` | — | See §3.2. |

Adding a flag later = migration (column + partial index) + enum case + API/UI exposure. No generic
EAV machinery.

### 3.2 Done
Done is a **state**, orthogonal to folders: marking a message done sets `is_done = true`,
`done_at = now()`; it stays in its folder. Effects: disappears from the Inbox view, appears in the
Completed view, and is shown muted in folder views (with a per-view "hide done" toggle).
Undoing done clears `is_done` and `done_at` without changing the folder. Moving into the custom
Done folder does not set the flag; moving out does not clear it. Neither action affects All Mail.

### 3.3 Mirrored flags (read, starred)
- Local value is what the UI shows and filters on.
- A local change enqueues a write-back when enabled ([03](03-synchronization.md) §8).
- Mirroring is opt-in per flag and off by default. While off, remote values initialize a new
  message only; subsequent scans update the remote summary but leave the local flag unchanged.
- When mirroring is enabled, a remote *change* (aggregate different from its previous value) is applied
  locally via `OrganizationService::applyRemoteFlagChange()` unless a local write-back for that
  message+flag is pending, processing, or failed with unresolved local intent.
- Enabling mirroring adopts the next successfully observed remote value for existing messages;
  it does not bulk-push stored local flags. Local changes made after enabling take precedence.
  Disabling cancels unstarted outbox work; an already issued network command may still finish.
  Per-flag account/message generation counters make first-observation initialization explicit;
  unrelated flags' pending work is not invalidated by changing a different mirror setting.

## 4. Views

All message lists are a `MessageFilter` (§8) executed by the same query builder. System views are
predefined filters. Common base predicate for every view:
`messages.deleted_at IS NULL AND mail_accounts.user_id = authenticated_user
AND mail_accounts.enabled = true AND mail_accounts.deleted_at IS NULL`.
Pausing polling (`sync_enabled = false`) does not disable an account or hide its synchronized mail.
There is no separate hidden-account exclusion from All Mail. A user can disable an account
explicitly; its retained messages remain accessible in that account's view, labelled disabled.

| View | Predicate (in addition to base) |
|---|---|
| **All Mail** | none — every message regardless of folder, done, read, star |
| Inbox | `folder = system inbox AND is_done = false AND remote_status <> 'removed'` |
| Unread | `is_read = false AND remote_status <> 'removed'` |
| Starred | `is_starred = true` |
| Important | `is_important = true` |
| Completed (done state) | `is_done = true` |
| Folder X | `folder_id = X` (done shown muted unless "hide done") |
| Account A | `mail_account_id = A` (allows disabled, but not deleted accounts) |
| Tag T | `EXISTS message_tag(tag T)` |
| Removed from server | `remote_status = 'removed'` |
| Trash (local) | `deleted_at IS NOT NULL` (replaces the base predicate) |

**All Mail guarantee:** a message leaves All Mail only if (a) the user deletes it locally
(`deleted_at`), or (b) its account is explicitly disabled or deleted. Moving, tagging,
marking done, or remote removal never removes it from All Mail. Remote-removed messages stay in All
Mail and in their folder with a "removed from server" badge; they are excluded only from Inbox and
Unread (see OD-3).

Default sort: `sort_date DESC, id DESC`. Pagination: signed opaque cursor containing version,
user, normalized filter hash, `sort_date,id`; validate bounds and scope on every request.
`sort_date` freezes at first ingestion. Newly arriving rows require refreshing the list head;
pagination is not a frozen snapshot across concurrent organization changes.

Counts use exactly the same authorized predicates as their corresponding list, plus `is_read=false`
for unread badges. They count messages, not locations or threads. All Mail with user-added filters
is labelled as a filtered result with visible chips; the unfiltered All Mail entry always clears
those filters. A local folder, tag or done action cannot change account eligibility.

## 5. Local deletion
- "Delete" in the app sets `deleted_at` (local trash) and writes an audit event. Nothing happens on
  the server (see OD-7).
- Restore clears `deleted_at`.
- Permanent local purge (M7, explicit action, audited): deletes rows and releases blobs for GC. If
  the message still exists remotely it would be re-ingested by the next backfill/UIDVALIDITY
  reset, so purge keeps a tombstone (`dedupe_key` in a `purged_messages` table checked by
  ingestion). Designed in M7; not required earlier.

## 6. Threads and the unit of organization
- The list shows **one row per message** (D16); the reader pane shows the thread across folders.
  It excludes locally trashed messages unless explicitly opened in Trash; all account and user
  authorization rules still apply. Remote-removed messages retain their badge.
- Actions apply to the selected messages. In M4, the reader may offer explicit thread actions ("Move
  conversation", "Mark conversation done") that expand to the thread's message ids on the server.
- Threads are per account; the same conversation received by two accounts forms two threads.
- A "group by conversation" list mode is a possible M5+ enhancement; it does not change storage.

## 7. Tags and notes
- Tags: many-to-many, user-defined, colored, orderable. Adding/removing tags is idempotent
  (`INSERT … ON CONFLICT DO NOTHING` / `DELETE`). Deleting a tag removes the associations (audited
  with count), never messages.
- Notes: free text attached to a message; multiple notes per message, each with author and
  timestamps; soft-deleted. Shown in the reader, searchable later (M5+, optional).

## 8. `MessageFilter` (shared by views, saved views, search, bulk actions)

```ts
type MessageFilter = {
  version: 1
  view?: 'all' | 'inbox' | 'unread' | 'starred' | 'important' | 'done' | 'removed' | 'trash'
  folderIds?: number[]
  accountIds?: number[]          // account view may explicitly include a disabled account
  tagIds?: number[]              // all-of
  isRead?: boolean
  isStarred?: boolean
  isImportant?: boolean
  isDone?: boolean
  hasAttachments?: boolean
  from?: string                  // address/name substring
  to?: string                    // any recipient role
  dateFrom?: string              // ISO date, inclusive
  dateTo?: string                // ISO date, exclusive; user's timezone converted to UTC
  text?: string                  // free text → SearchEngine (M5)
}
```

Validated server-side (`MessageFilterRequest` → `MessageFilter` DTO). Saved views store this JSON.
Bulk actions accept either explicit `messageIds` (≤ 1 000, synchronous) or a server-issued
preview token for a materialized, expiring selection (`organization_operations` + items).
The preview fixes exact IDs and count under a database snapshot, is scoped to user/filter,
and requires confirmation before execution; subsequently arriving mail is not included.
IDs are reauthorized at execution. All filter components intersect; values within folder/account
arrays are OR, tags are all-of; unknown fields or incompatible combinations return 422.

## 9. Bulk operations, undo, audit
- All organization writes go through `OrganizationService`, which takes an `Actor`
  (`user`, `sync`, `system`, later `rule`/`ai`) — the seam for future rules and classification.
- Audit events contain bounded summaries, never an unbounded undo payload. Operation items
  retain per-message before/after state and resulting `local_revision` for seven days (M5).
  Undo is a new operation, only when the affected state still matches that revision; conflicts
  are skipped and reported. It never rewinds intervening user changes or remote mailbox state.
- Operations are single transactions; bulk-by-filter jobs process in chunks of 1 000, each chunk
  its own transaction, and persist item completion in the same transaction. Retries skip completed
  items; progress distinguishes completed, conflicted, and failed items. Folder deletion remains
  an atomic reassignment plus delete under a folder lock; large deletes may run on the queue.


### M3 finalization reading surface

Read-only Sent and Archive select the corresponding local system folder through MessageFilter;
they do not exclude Done or remotely removed mail. Global and explicit account scopes retain the
base visibility rules above. The conversation endpoint returns chronological member metadata in
50-row signed cursor pages. Each expanded member uses the shared secure individual reader.
All editing, thread-wide organization and bulk operations remain M4.
