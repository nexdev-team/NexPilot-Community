# Release process

The WordPress installer and MCP bridge are distributed as GitHub release assets. A release may be prepared while the repository is private; only people with repository access can download its assets until visibility changes.

## Build and verify

1. Run the PHP, bridge, and disposable WordPress integration checks from the README.
2. Run `python3 build.py`. This creates `dist/nexpilot-community.zip`, `dist/nexpilot-community-bridge.mjs`, and their SHA-256 checksums.
3. Inspect the ZIP members. The archive must contain one top-level `nexpilot-community/` directory with the plugin header file, required includes, uninstall handler, readme, and license.
4. Verify that the plugin header, PHP `VERSION`, `plugin/readme.txt`, changelog, and Git tag identify the same version.
5. Review the source tree and release files for credentials, personal data, private endpoints, third-party licensing, and generated artifacts.
6. Push a Git tag from the verified commit. The release workflow repeats the checks, builds the four files from `dist/`, and publishes them as release assets. Version tags containing a suffix are marked as prereleases.
7. Open the README download links and confirm that both files download. Upload the ZIP through **Plugins → Add New → Upload Plugin** on a disposable WordPress site and verify activation.

## Before public visibility

Extend the integration checks to administrator connection issuance/revocation and WooCommerce product visibility. Repeat compatibility checks across the WordPress/PHP versions claimed in the plugin metadata. Review security and licensing, and obtain the repository owner's explicit approval before changing visibility.

The release is not a claim of WordPress.org directory approval or independent security certification.
