=== NexPilot Community ===
Contributors: nexdev
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only MCP access to published WordPress posts, pages and visible WooCommerce catalog products.

== Description ==

NexPilot Community is developed and maintained by Nexdev. It creates separate, expiring client credentials for MCP access to published WordPress posts and pages. When WooCommerce is active, the connector also reads visible, published catalog products. Administrators can revoke connections from the WordPress dashboard.

== Installation ==

Download the plugin ZIP from the project's GitHub release. In WordPress, open Plugins > Add New > Upload Plugin, select the ZIP, install it, and activate it. In Tools > NexPilot Community, create a connection for each local MCP client. Store each credential in an owner-only file. Download the companion Node.js bridge from the same release for stdio clients.

HTTPS is required for the MCP endpoint. HTTP is allowed only when an administrator explicitly defines NEXPILOT_COMMUNITY_ALLOW_LOCAL_HTTP in a WordPress local environment.

== Privacy ==

The plugin does not contact an AI provider. A configured MCP client receives published content and can share that content with its provider under that client's settings and terms. No fixed telemetry endpoint is included. The plugin stores client labels, credential hashes, and creation and expiry times in a WordPress option. Revoke clients from the WordPress admin page when no longer needed.

Uninstall removes the connector's stored client records. Deactivation leaves them intact so existing connections can be restored if the plugin is reactivated.

== Changelog ==

= 0.1.0-beta.1 =
Initial WordPress MCP connector with published content and WooCommerce catalog access.
