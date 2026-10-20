=== NexPilot Beta ===
Contributors: nexdev
Requires at least: 6.6
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.2.1-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent MCP connections for WordPress and WooCommerce, reviewable changes, and temporary maintenance access.

== Description ==

NexPilot provides separate, expiring connections for AI clients. Administrators choose read, content, commerce, and maintenance permissions for each connection. The plugin exposes WordPress and WooCommerce resources through MCP and supports native OAuth or a local Node.js stdio bridge.

Changes are proposed with an exact resource revision, reviewed by a human administrator in WordPress, and rechecked before execution. Full maintenance is deliberately unrestricted WordPress and server-account access. An administrator must grant the maintenance permission and open a temporary maintenance window before it can be used. It is not a sandbox.

This version is beta. It requires PHP Sodium. MySQL/MariaDB advisory locks are the default; single-host local SQLite requires explicit lock configuration. Validate the intended WordPress, WooCommerce, PHP, database, and hosting configuration in staging before production installation. The installer does not include an automatic updater.

== Installation ==

Upload the plugin ZIP through Plugins > Add New > Upload Plugin, install, and activate it. Open Tools > NexPilot to create a separate connection for each client. Read permission is included by default. Store credentials in private local files and revoke or rotate connections from the administrator page.

The MCP endpoint requires HTTPS. A local-only HTTP exception is available for isolated development. Node.js 20 or newer is required for the optional stdio bridge.

== Privacy and external services ==

The plugin does not contact a model provider automatically. A configured client can retrieve data within its granted permissions and share that data with its chosen model provider. Review the provider's terms and privacy settings before connecting.

Optional external audit delivery is disabled unless an administrator configures NSC_AUDIT_URL and NSC_AUDIT_KEY. It sends the site URL, timestamp, connection identifier, event type, and reference identifier to the configured HTTPS receiver. No fixed telemetry destination is included.

== Data retention ==

Deactivation keeps connection records, audit events, and encrypted proposals. An administrator can opt into connector-data removal on uninstall. Site content, uploads, and user sandbox files are retained. Multisite cleanup is not implemented.

== Distribution ==

This edition includes administrator-approved arbitrary code and file maintenance. It is not eligible for submission under the current WordPress.org new-plugin FAQ. No automatic update endpoint is configured.

== Changelog ==

= 0.2.1-beta.1 =
Beta designation in plugin name, version, and administrator notice. Runtime functionality matches the source edition of this version.
