# 0.2.1-beta.1 release verification

The release ZIP was built from this repository and installed through WordPress plugin installation in a disposable local environment. The environment used WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.1.34, and MariaDB 11.4.13. Site records and administrator credentials were synthetic; outbound WordPress HTTP and mail were blocked. No live site was contacted.

## Results

- The ZIP installed and activated under the original `nexdev-site-connector` plugin identity.
- 49 integration checks passed, including connection authentication, MCP tool discovery, database locking, WordPress and WooCommerce writes through the proposal and approval path, file and media operations, file recovery, stale revision rejection, permission boundaries, credential rotation, and rate limiting.
- A separate disposable installation was upgraded from the original `0.2.0` ZIP to the release ZIP. The existing connection record, read permission, and tool surface remained available.
- The repository suites passed: 48 PHP behavior checks and 5 Node.js bridge checks. The PHP suite uses doubles; the integration checks above used actual WordPress, WooCommerce, and MariaDB.
- The ZIP contains 26 files under one `nexdev-site-connector/` directory. Its executable runtime files match the original `0.2.1-beta.1` source byte-for-byte. The package has no test, runtime, or internal instruction files.
- The Community history and the built ZIP were scanned for the identified client names and paths, private-key headers, GitHub token prefixes, and common cloud-key formats. No matches were found. The Community history shares no commit with the original private repository.

## Limits

This verification covers the stated local matrix and synthetic workflows. It does not certify every hosting configuration, third-party extension, native OAuth client, or production installation. The PHP suite's doubles alone would not establish WordPress or MySQL compatibility. Full maintenance remains unrestricted and requires explicit administrator control.
