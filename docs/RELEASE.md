# Release checks

This branch prepares a full-functionality NexPilot distribution. The existing `v0.1.0-beta.1` Community release contains the earlier reduced plugin and must not be treated as an update to this candidate.

Before publishing a full installer or changing repository visibility:

1. Compare every distributed PHP and JavaScript runtime file with the approved `0.2.1-beta.1` source snapshot. Document any intentional difference.
2. Run the source PHP suite, bridge suite, and reproducible plugin-only build from a clean clone. Keep the test-double limitation explicit.
3. Install the built ZIP in an isolated WordPress site and verify activation, connection lifecycle, MCP tool discovery, OAuth registration/consent, and approved changes. Test WooCommerce and maintenance against synthetic data.
4. Confirm upgrade behavior for an existing installation with the same `nexdev-site-connector` identity and `nsc_` storage namespace. Back up the staging database before the test.
5. Scan the current tree, release ZIP, and complete fresh Git history for credentials, personal data, private endpoints, test results, and third-party licensing.
6. Review the unrestricted maintenance surface and the current WordPress/PHP/WooCommerce compatibility matrix. Do not claim WordPress.org directory approval or independent security certification.
7. Replace the old one-click link only after the new tagged release and its checksum-verified assets are available. Obtain owner approval before changing GitHub visibility.

The `Package approved release` GitHub Actions workflow is manual. It requires an existing reviewed version tag, reruns the local suites, builds the original plugin ZIP and bridge, checks the archive layout, and attaches checksummed assets to a prerelease. Run it only after the staging and security gates above are recorded and the owner authorizes distribution.
