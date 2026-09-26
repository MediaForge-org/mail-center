# 09 — Milestone Boundaries

Milestones are sequential acceptance gates, not authorization to start the next one. The
[M3 acceptance record](../m3-acceptance.md) records the implemented reading milestone and its gates. An implementation change that contradicts the specification must update the relevant
contract and acceptance tests together.

| Milestone | In scope | Exit criteria | Explicitly excluded |
|---|---|---|---|
| **M0 — Architecture** | Documents 01–11, root guide, ignore safety | Consistent semantics, identity/recovery/security contracts, test/deployment plans and named pre-M1 gates; inherited work preserved | Framework install, migrations, application code, running services, Git writes |
| **M1 — Foundation** | Resolve G1–G3 in 10; pin Laravel/PHP and frontend dependencies; Docker local stack; schema for users, auth, seed folders and safe demo data; SPA shell/design tokens; CI | Clean documented startup, login/logout/CSRF, disabled registration, scoped authorization baseline, migration/build/static checks pass | Real mailbox credentials, IMAP/SMTP transport, ingestion, live mail UI |
| **M2 — Accounts and reliable ingestion** | Credential vault; account setup and queued connection tests; choose IMAP adapter; tables for accounts, content, blobs, locations, checkpoints, failures, audit and thread assignment; bounded background sync | Synthetic server integration proves I1–I8, recovery and ownership; coverage/error status visible; credentials encrypted and redacted; no remote writes | Application 2FA (deferred by current M2 brief), message organization UI, read/star write-back, external search, sending |
| **M3 — Readable unified mailbox** | Three-pane list/reader, All Mail and account/folder views, counts, keyset pagination, durable invalidation, safe HTML/attachments/remote images; read actions and opt-in Seen outbox | All Mail contract passes; hostile HTML/network and cross-user tests pass; remote read writes require explicit opt-in; 100k list benchmark recorded | Folder/tag/note editing, star write-back, sending |
| **M4 — Organization** | Folder CRUD/reorder, drag/keyboard move, tags, notes, local delete/restore, star/important/done, Completed view, optional Flagged mirroring, durable bulk selection/operations | Local actions never issue remote structural changes; Done folder differs clearly from Completed view; partial bulk recovery and mirrored-flag races pass | Remote delete/move, SMTP, automatic rules |
| **M5 — Search and productivity** | PostgreSQL FTS/trigram filters, saved views, keyboard polish, conflict-aware undo | Search contract and owner scope pass; index rebuild works; query plans and latency documented; bulk undo cannot overwrite later edits | External search engine, attachment body extraction/OCR, rules/AI |
| **M6 — Sending** | Finalize outbound state machine/schema, drafts, generic SMTP, reply/reply-all/forward, upload constraints, independent optional Sent APPEND, sent-copy correlation | Stable composed MIME, duplicate submit guard, unknown-send handling, attachment auth and provider-safe Sent behavior tested | Gmail API/Graph connectors, remote folder management, automatic resend after ambiguous acceptance |
| **M7 — Production hardening** | Production Compose/images, restore drill, monitoring, purge/tombstones/GC, operational docs; optional measured CONDSTORE/QRESYNC optimization | Security review, restore and fault suites, dependency/license checks and operating limits pass; operator can recover without data loss | OAuth/API provider implementation, team workspaces, external search, AI classification |

Schema arrives with its first user: search tables in M5, outbox Seen in M3/Flagged in M4,
outgoing tables in M6. Shared primitives such as `user_change_versions` may be introduced in M2
for transactional writers, even though the polling endpoint arrives in M3. M1 must not attempt
to build the entire future schema. Documents describe later interfaces without implementing them.

Security follows the feature that creates the risk: encrypted credentials in M2; the original
2FA gate is deferred by the current M2 brief (see 05), while sanitization/SSRF defenses precede
any mail HTML/images in M3. M7 verifies and
hardens these controls; it does not defer foundational security until deployment.

Future, separately scoped milestones may add Gmail API or Microsoft Graph/OAuth, IMAP IDLE,
shared workspaces, rules and mobile layouts. Each requires a written delta and contract tests;
none is implicit in M1–M7. Generic IMAP and SMTP are the initial connectivity targets even when
a particular provider requires OAuth before allowing a connection (see OD-2).
