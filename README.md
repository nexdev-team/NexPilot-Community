# NexPilot Beta

**WordPress and WooCommerce operations through independent MCP connections, with human review for changes.**

NexPilot is developed by [Nexdev](https://nexdev.gr). This repository carries the full `0.2.1-beta.1` plugin implementation under its original WordPress plugin identity, `nexdev-site-connector`. The MCP endpoint, tool names, connection format, storage keys, and Node.js bridge settings follow that implementation.

[![Download the WordPress plugin ZIP](assets/download-plugin.svg)](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.2.1-beta.1/nexdev-site-connector-0.2.1-beta.1.zip)

[MCP bridge](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.2.1-beta.1/nexdev-mcp.mjs) · [Release notes and checksums](https://github.com/nexdev-team/NexPilot-Community/releases/tag/v0.2.1-beta.1)

While this repository is private, GitHub access is required to download release assets.

[Implementation parity and verification](docs/PARITY.md) records the comparison with the original source edition. No plugin behavior was rewritten for this candidate.

## Capabilities

- Separate, expiring connections with read, content, commerce, and maintenance permissions; administrator rotation, revocation, and emergency disable.
- MCP tools for WordPress content, WooCommerce resources, Gutenberg blocks, libraries, design records, and supported extension adapters.
- Exact change proposals, WordPress administrator approval, resource revision checks, audit records, and execution locking.
- Native OAuth with administrator consent and a local stdio bridge for MCP clients.
- Temporary full maintenance, including PHP, SQL, WP-CLI, files, and administrator access. **Full maintenance is unrestricted access, not a sandbox.** It requires an explicit connection permission and a human-opened maintenance window.

The plugin is beta software. It requires PHP Sodium and a supported database advisory lock configuration. Validate the target WordPress, WooCommerce, PHP, database, and hosting configuration in staging before production use. The installer does not include an automatic updater.

## Install and connect

1. Download the [ready-to-install plugin ZIP](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.2.1-beta.1/nexdev-site-connector-0.2.1-beta.1.zip). Keep it zipped.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, install the ZIP, and activate it.
3. Open **Tools → NexPilot**. Create a separate connection for each MCP client and keep its one-time credential in a private file.
4. Download the [Node.js 20+ bridge](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.2.1-beta.1/nexdev-mcp.mjs) and configure it when the MCP client uses stdio:

```json
{
  "mcpServers": {
    "nexdev-site": {
      "command": "node",
      "args": ["/absolute/path/to/nexdev-mcp.mjs"],
      "env": {
        "NSC_ENDPOINT": "https://your-site.example/wp-json/nexdev-connector/v1/mcp",
        "NSC_TOKEN_FILE": "/private/path/client.token"
      }
    }
  }
}
```

The site must serve the MCP endpoint over HTTPS. Keep credentials out of URLs, logs, and source control. The exact client configuration format depends on the application.

## Verify

```sh
php tests/run.php
node --test tests/bridge.test.mjs
python3 build.py
```

The PHP suite uses WordPress/MySQL doubles. The installer was also checked on an isolated WordPress/WooCommerce/MariaDB installation; see [release verification](docs/RELEASE-EVIDENCE.md) and [release checks](docs/RELEASE.md). These checks do not certify every hosting environment.

## Project information

- [Architecture](docs/ARCHITECTURE.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- [GPL-2.0-or-later license](LICENSE)
