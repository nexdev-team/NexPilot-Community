# Contributing

Contributions must preserve the established MCP contracts, WordPress plugin identity, permission checks, approval workflow, and connection storage behavior. Describe any proposed behavior change explicitly and include tests that demonstrate compatibility with existing clients.

Run `php tests/run.php`, `node --test tests/bridge.test.mjs`, and `python3 build.py` before proposing changes. The PHP suite uses test doubles. Changes involving WordPress, WooCommerce, OAuth, MySQL/MariaDB, or maintenance also need staging integration evidence before release.

Keep credentials, private URLs, database exports, logs, and third-party assets out of contributions. Use synthetic test data and report vulnerabilities through [SECURITY.md](SECURITY.md). Contributions must be original or carry compatible license notices and are distributed under GPL-2.0-or-later.
