# 11 — PostgreSQL Search

Search is part of the shared `MessageFilter` contract, not a separate mailbox store. M5 introduces
it; M1–M4 need no search server. PostgreSQL remains the only search engine throughout M1–M7.

## Query semantics

`GET /api/messages` accepts the validated filter in [04](04-organization-semantics.md), including
`text` (maximum 512 characters), and returns the same message rows, cursor and account badges as
ordinary views. The default is newest-first, not relevance order. All filters intersect with
ownership, account availability and local deletion rules **before** ordering/limiting.
No query may retrieve a globally limited ID list and then apply authorization.

Text uses `websearch_to_tsquery('simple', normalized_input)`: unquoted terms are AND, quoted text
is a phrase, OR and minus provide alternatives/exclusion. Normalize accents consistently with
`unaccent` in both document and query. Structured `from`, `to`, date, folder, tag and flag inputs
are separate fields; M5 does not invent Gmail-style `from:` parsing. Empty text means no text
filter; non-empty input producing no searchable terms returns 422 with an explanation.

The `simple` configuration is language-neutral without stemming. A token prefix or arbitrary
substring is not implied by free text. `from`/`to` use explicit case-insensitive literal substring
matching on participant names/addresses; a future subject-only substring input uses `pg_trgm`.
Escape `%`, `_` and the escape character for literal ILIKE inputs. Require three characters for
substring filters; limit result/page size and set a statement timeout. `to` covers to/cc/bcc
recipient roles, not sender or reply-to. Date bounds refer to frozen `sort_date`; the API converts
the user's date/timezone bounds to an inclusive start and exclusive end in UTC. Never concatenate
user input into SQL/tsquery. A timeout returns a retryable search error, never incomplete success.

PostgreSQL's [text-search query constructors and weights](https://www.postgresql.org/docs/current/textsearch-controls.html)
define the underlying token/query behavior; the API validation, limits and authorization above
are MailCenter's contract. Include representative non-English fixtures in M5 acceptance.

## Search document and indexes

`message_search_documents` has one row per message and a GIN-indexed tsvector. Weights: subject A,
participant names/addresses B, attachment filenames C, derived plain text D. Weighting leaves a
future relevance-sort seam; it does not change v1 newest-first ordering. Decode MIME and strip
HTML before indexing; never index raw HTML, tracking URLs, credentials or remote image content.
Index at most the first 64 KiB of body text, truncated at a UTF-8 boundary, and expose
`body_truncated` in detail/search help. Header/participant/filename fields also have ingestion
length/part caps. Search may miss terms beyond those limits; the product must say so.

Attachment **names** are searchable; attachment contents, OCR and notes are excluded from v1
text search. Remote-only/unfetched/quarantined mail is not searchable. Locally trashed mail remains
indexed but appears only in Trash filters. Account disable and local move/tag changes do not
require reindexing because those filters join live SQL state. Physically purged messages remove
their document in the same transaction.

Use existing composite B-tree message-list indexes, GIN document index, and trigram GIN indexes
on participant names/addresses and subject/attachment filename where substring inputs exist.
Do not add indexes solely for hypothetical filters; verify plans on the target corpus. The engine
receives an authorized `MessageFilter` and signed cursor, and returns `SearchPage` containing
ordered IDs, continuation cursor and explicit indexing coverage metadata. Hydration preserves
this order and rechecks owner scope. Structured and text predicates execute in one SQL query.

## Index updates and recovery

After M5, ingestion/reparse builds and upserts the document in the same PostgreSQL transaction
as parsed content. Index failure rolls back that ingestion transaction and retries normally;
there is no after-commit-only job that can silently lose an indexing request. Initial rollout
and version upgrades use checkpointed `ReindexMessagesJob` chunks on maintenance, storing
version/last scanned ID in a durable maintenance-run record. Scan in ID order; lock/re-read a
message before indexing so a rebuild cannot overwrite a newer parse with old text.

Show index coverage (indexed/eligible/version) while backfill is incomplete. Live ingestion uses
the current version. A complete rebuild is idempotent and resumes after queue loss. For changes
to normalization/configuration, build a new document table/index generation, dual-update it during
the rebuild, validate counts/samples and switch reads atomically; retain the old generation until
rollback is no longer needed. Do not mix incompatible query/document normalizations silently.

Search interfaces isolate document building and query execution, allowing a separately approved
future engine. Any future engine must preserve authorization, local view semantics, pagination,
failure visibility and deletion behavior. None is required to deliver these milestones.
