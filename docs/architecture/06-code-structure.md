# 06 — Code Structure

Principle: standard Laravel skeleton, with domain code grouped **by feature** under `app/`
(not DDD layers, no repositories-for-everything). Controllers stay thin; one service per feature
area holds the write logic; queries that are reused live in dedicated query classes.

## 1. Backend (Laravel)

```
app/
  Http/
    Controllers/Api/          # thin JSON controllers, one per resource
      AccountController.php          (index, store, update, destroy)
      AccountCredentialController.php (update — write-only)
      AccountConnectionTestController.php
      AccountSyncController.php      (trigger sync, status)
      RemoteFolderController.php     (list, toggle sync)
      MessageController.php          (index = list by filter, show)
      MessageActionController.php    (bulk: move, tag, untag, flag, done, delete, restore)
      MessageRenderController.php    (sanitized HTML document for iframe)
      AttachmentController.php       (download, inline cid)
      ThreadController.php           (show)
      FolderController.php, FolderOrderController.php
      TagController.php, NoteController.php, SavedViewController.php
      ChangesController.php          (changes since cursor)
      ImageProxyController.php
    Requests/                 # FormRequests incl. MessageFilterRequest, BulkActionRequest
    Resources/                # API resources (MessageListItem, MessageDetail, Account, …)
  Models/                     # Eloquent models, one per table; no business logic beyond casts/relations/scopes
  Enums/                      # RemoteFolderRole, RemoteStatus, SyncStatus, Direction, …

  Accounts/
    AccountService.php         # create/update/delete accounts, seeding colors/labels
    Credentials/
      CredentialVault.php      # the only class that encrypts/decrypts
      CredentialProvider.php   # interface: resolve auth for a connection (password now, OAuth later)
      PasswordCredentialProvider.php
      Secret.php               # redacting value object
    ConnectionTester.php

  Connectors/
    Imap/
      ImapClient.php           # OUR interface: connect, capabilities, list, examine, select,
                               #   unselect, uidSearch, uidFetchEnvelopes, uidFetchRaw (stream),
                               #   uidFetchFlags, uidStoreFlags — nothing destructive exists here
      LibraryImapClient.php    # adapter over the chosen library (spike in M2)
      ImapErrors.php           # typed exceptions: AuthFailed, ConnectionFailed, Throttled, FolderMissing…
    Smtp/                      # M6

  Sync/
    AccountSyncDriver.php      # interface: sync(MailAccount, SyncBudget): SyncResult
    Imap/
      ImapSyncDriver.php       # orchestrates §1 of 03-synchronization
      FolderDiscovery.php
      FolderSynchronizer.php   # examine, incremental, backfill, expunge, flag scan
      UidValidityHandler.php
    Jobs/
      SyncAccountJob.php
      PushRemoteFlagChangesJob.php
    Commands/
      DispatchDueSyncsCommand.php   # sync:dispatch-due
      SweepWriteBackCommand.php     # writeback:sweep
    RemoteReconciler.php       # §5 missing/removed
    RemoteFlagWriter.php       # outbox processing
    SyncFailureRecorder.php
    Backoff.php

  Ingestion/
    MessageIngestor.php        # raw stream + RemoteLocationRef → rows (provider-independent)
    RemoteLocationRef.php
    DedupeKey.php
    MimeParser.php             # wrapper around zbateson/mail-mime-parser → ParsedMessage DTO
    ParsedMessage.php
    ThreadResolver.php
    DirectionDetector.php
    Events/MessageIngested.php

  Rendering/
    HtmlSanitizer.php          # allowlist config, cid rewriting, remote-image blocking; VERSION const
    ImageProxy.php             # signing + SSRF-safe fetch
    Snippet.php

  Storage/
    BlobStore.php              # put(stream): sha256, get(sha256): stream, exists; content-addressed

  Organization/
    OrganizationService.php    # move, tag, untag, setFlag, markDone, delete, restore, bulk;
                               #   applyRemoteFlagChange (opt-in); takes Actor
    Actor.php
    Flag.php                   # flag registry enum
    FolderService.php          # create/rename/reorder/delete (reassign to Inbox)
    Jobs/BulkActionByFilterJob.php

  Query/
    MessageFilter.php          # validated DTO (see 04 §8)
    MessageListQuery.php       # filter → keyset-paginated SQL
    Cursor.php

  Search/                      # M5
    SearchEngine.php           # interface: index(Message), remove(id), search(MessageFilter, Cursor): SearchPage
    Postgres/PostgresSearchEngine.php
    Postgres/SearchDocumentBuilder.php

  Audit/
    AuditLogger.php

  Policies/
  Providers/
config/
  mailcenter.php               # sync intervals, batch sizes, limits, grace periods, seed folders
database/
  migrations/ factories/ seeders/  # DemoSeeder generates realistic fake accounts+messages (no real data)
routes/
  web.php                      # SPA catch-all → resources/views/app.blade.php; Fortify routes
  api.php                      # /api/* (auth:sanctum)
  console.php                  # schedule definitions
```

Rules for implementers:
- Controllers never touch IMAP, never decrypt credentials, never run sync inline.
- Only `Organization\*` writes local-organization columns; only `Sync\*`/`Ingestion\*` write
  remote/content columns (enforced by review + tests, see [08](08-testing-strategy.md)).
- Jobs carry ids, never models with secrets or large payloads.
- `ImapClient` exposes no destructive command (I2). A test asserts the interface's method list.

## 2. API surface (JSON, `/api`, session auth)

| Method & path | Purpose | Milestone |
|---|---|---|
| `GET /api/me` | current user, settings | M1 |
| `GET/POST /api/accounts`, `PATCH/DELETE /api/accounts/{id}` | accounts | M2 |
| `PUT /api/accounts/{id}/credentials` | write-only credentials | M2 |
| `POST /api/accounts/test-connection`, `GET /api/connection-tests/{id}` | queued test, sanitized status | M2 |
| `POST /api/accounts/{id}/sync`, `GET /api/accounts/{id}/sync-runs` | manual sync, history | M2 |
| `GET /api/accounts/{id}/remote-folders`, `PATCH /api/remote-folders/{id}` | per-folder sync toggle | M2 |
| `GET /api/messages?filter=…&cursor=…&limit=50` | list (keyset) | M3 |
| `GET /api/messages/{id}` | detail (headers, text, attachments, tags, notes) | M3 |
| `GET /api/messages/{id}/render?images=` | sanitized HTML document for iframe | M3 |
| `GET /api/threads/{id}` | thread messages | M3 |
| `GET /api/attachments/{id}/download`, `GET /api/messages/{id}/inline/{cid}` | files | M3 |
| `POST /api/messages/actions` | `{action, messageIds \| selectionToken, params}`, idempotency key | M3 (read), M4 (rest) |
| `POST /api/message-selections`, `GET /api/operations/{id}` | materialized filter preview, progress | M4 |
| `GET /api/changes?since=` | durable user version + cache invalidation hint (02) | M3 |
| `GET /api/counts` | unread counts per view/folder/account | M3 |
| `GET/POST/PATCH/DELETE /api/folders`, `PUT /api/folders/order` | folders | M4 |
| `GET/POST/PATCH/DELETE /api/tags` | tags | M4 |
| `POST/PATCH/DELETE /api/messages/{id}/notes…` | notes | M4 |
| `GET/POST/PATCH/DELETE /api/saved-views` | saved views | M5 |
| `GET /api/image-proxy` | signed image proxy | M3 |
| `/api/outgoing…` | drafts, send | M6 |

API conventions: all resource routes authorize the current user; route model binding is scoped.
Lists return `{data, nextCursor}`; async requests return 202 and an authorized status URL.
Mutations accept an idempotency key for repeatable operations; mismatched key/payload is 409.
Local edits include expected `local_revision`; stale updates return 409 and trigger a UI refresh.
Validation is 422 with field errors, unavailable resources 404, throttling 429; errors use stable
codes without transport exception text. Account DELETE is soft delete. Detail fetch does not
mark read; the reader sends an explicit read action after display. Cookie-auth API routes require
CSRF checks; `/api/*` never falls through to the SPA route. Search uses the same list endpoint.

List items are deliberately small (id, account id, thread id, folder id, from, subject, snippet,
sort_date, flags, tag ids, has_attachments, remote_status) — the client joins accounts/folders/tags
from its cached lookup lists.

## 3. Frontend (Vue 3 + TypeScript)

```
resources/
  views/app.blade.php            # SPA shell (only Blade file besides error pages)
  css/app.css                    # Tailwind entry, design tokens (CSS variables), dark mode
  js/
    main.ts                      # createApp, router, pinia, vue-query
    router/index.ts              # routes: /login, /mail/:view?, /mail/folder/:id, /mail/account/:id,
                                 #   /mail/tag/:id, /mail/saved/:id, /settings/*  (?m=<messageId>)
    api/
      http.ts                    # fetch wrapper: CSRF cookie, JSON, error normalization, 401 → login
      types.ts                   # API types mirroring Resources (hand-written; generator is optional later)
      messages.ts, accounts.ts, folders.ts, tags.ts, …   # typed endpoint functions
    queries/                     # TanStack Query hooks: useMessageList(filter), useMessage(id), useCounts()…
    stores/                      # Pinia: UI state only
      selection.ts               # selected ids, anchor, range select
      layout.ts                  # pane widths, collapsed sidebar
      preferences.ts
    layouts/
      AppLayout.vue              # three-pane shell
    features/
      sidebar/                   # SidebarNav, ViewList, FolderList (drop targets, reorder), AccountList, TagList
      message-list/              # MessageList (virtualized), MessageRow, ListToolbar, BulkBar, EmptyState
      reader/                    # ThreadView, MessageCard, HtmlFrame (sandboxed iframe), RemoteContentBanner,
                                 #   AttachmentList, NotesPanel
      accounts/                  # AccountForm, ConnectionTest, SyncStatus, RemoteFolderSettings
      search/                    # SearchBar, FilterChips (M5)
      compose/                   # M6
      settings/
    components/ui/               # Button, IconButton, Menu, Dialog, Tooltip, Kbd, Badge, AccountChip…
                                 #   (thin wrappers over Reka UI + Tailwind)
    composables/
      useKeyboardShortcuts.ts    # central registry (M5 exposes full set; j/k/arrow basics in M3)
      useDragMessages.ts         # native HTML5 DnD: drag selection → folder drop targets
      useOptimisticAction.ts
      usePolling.ts              # changes feed polling
    utils/
tests/
  frontend/ or co-located *.spec.ts (Vitest)
  e2e/                           # Playwright
```

Frontend rules:
- **Server state in TanStack Query, UI state in Pinia.** Mutations are optimistic (update cached
  list pages and counts), rolled back on error, followed by targeted invalidation.
- The message list is virtualized (`@tanstack/vue-virtual`) with infinite keyset loading; row
  height fixed (dense, ~36–44 px) for predictable virtualization.
- Selection model: click, ⌘/Ctrl-click toggle, Shift-click range, "select all loaded", and "select
  all N matching" (switches bulk action to filter mode).
- Drag & drop: native HTML5 DnD. Dragging a selected row drags the whole selection; custom drag
  image shows the count; folder rows are drop targets with hover highlight. Keyboard alternative
  ("Move to…" command, `v` shortcut) is mandatory for accessibility.
- Account visibility: every row shows the account chip (color + short label); the reader header
  shows full account name/address ("Received by …" / "Sent from …").
- The URL is the source of truth for the current view and open message (`?m=`) so reload/back
  work.
- No `v-html` for email content (lint error); HTML mail only in `HtmlFrame.vue`.
- Design: neutral, dense, keyboard-first; light and dark themes via CSS variables from M1.

Module contracts: transport adapters return typed remote observations and raw streams, never
Eloquent writes; the sync driver owns protocol checkpoints, ingestion owns content, and
OrganizationService owns local intent. `SyncResult` carries complete/partial coverage, durable
continuation state, counters and sanitized error codes. Unsupported provider capabilities are
explicit; no caller can assume UID STORE exists on every future connector. Add a separate
`OutboundTransport` seam for generic SMTP and future provider sending in M6. New providers must
pass common identity, ownership, retry and local-state-preservation contract tests.
