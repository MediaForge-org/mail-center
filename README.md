# MailCenter

A standalone, open-source multi-account email manager planned for Laravel, Vue 3,
TypeScript, PostgreSQL, Redis, Laravel Queues, Vite, and Docker.

Current phase: **M1 project foundation**. Laravel, Vue, PostgreSQL, Redis and the Docker
development stack are in place. Start with the [developer setup](docs/development.md).
The [M0 architecture specification](docs/architecture/README.md) remains the design contract.
The default local URL is <http://localhost:8061>.

All Mail includes every synchronized, locally non-deleted message from enabled accounts.
Application folders, tags, important/done state, and notes are local organization;
moving mail between application folders never moves it on the IMAP server.

`graphify-out/` is ignored, disposable local analysis output. It is not source, a build
input, or a runtime dependency. Environment files and secrets must stay outside Git.
The user manages Git; coding agents may inspect Git state but must not change it.

Licensed under [AGPL-3.0-only](LICENSE).
