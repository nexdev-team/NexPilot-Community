# Release criteria

This repository is private while the community edition is prepared for public release. A successful source test is one input to a release decision, not a release approval.

Before changing repository visibility or publishing an artifact:

1. Extend the passing disposable WordPress/MariaDB check to administrator UI issuance/revocation and WooCommerce catalog visibility, then repeat across the claimed WordPress/PHP version range.
2. Review the complete tree and fresh Git history for credentials, personal data, client-specific endpoints, licensed third-party files, and unwanted generated artifacts.
3. Review the plugin and bridge for security and compatibility. Record findings and fixes without importing private customer evidence.
4. Verify license and attribution for every distributed file. Build only from this clean repository and inspect the ZIP members.
5. Confirm the README, plugin metadata, version, changelog, and support scope match the tested artifact.
6. Obtain the owner's explicit approval before changing GitHub visibility or publishing a release.

No WordPress.org directory acceptance, independent security certification, or full NexPilot feature parity is claimed.
