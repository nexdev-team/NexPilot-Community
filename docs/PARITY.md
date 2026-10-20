# Implementation parity

This candidate uses the original NexPilot `0.2.1-beta.1` runtime implementation as its source. The earlier Community `0.1.0-beta.1` implementation had a smaller tool set and different plugin identity; it is not an upgrade path for this candidate.

## Source comparison

The comparison covered all 25 files under `plugin/` except `plugin/readme.txt`, plus `bridge/nexdev-mcp.mjs`. The 26 corresponding files are byte-for-byte identical to the original source edition. The only changed file within the plugin package is `plugin/readme.txt`, rewritten to remove internal and client-specific wording while retaining the beta, privacy, retention, and maintenance disclosures.

Consequently, the plugin slug, entry point, REST routes, tool names and handlers, permission checks, storage keys, OAuth behavior, approval and locking workflow, maintenance operations, and bridge protocol are the original implementation. This is source parity; it is not a claim that every feature has been integration-tested on every WordPress or WooCommerce environment.

## Local verification

- `php tests/run.php`: 48 behavior checks passed using WordPress/MySQL doubles.
- `node --test tests/bridge.test.mjs`: 5 bridge checks passed.
- `python3 build.py`: produced `dist/nexdev-site-connector-0.2.1-beta.1.zip` with the original plugin directory layout.
- All executable PHP and JavaScript files in the ZIP and bridge were compared byte-for-byte with the original source edition.
- The ZIP was checked for private runtime files and the previously identified client-specific strings.

These checks do not certify real WordPress, WooCommerce, MySQL/MariaDB, OAuth client, or production integration. An isolated staging installation and release security review remain required before distribution or a change to repository visibility.
