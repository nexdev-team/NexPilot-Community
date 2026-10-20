# NexPilot Beta

**WordPress and WooCommerce operations through independent MCP connections, with human review for changes.**

NexPilot is developed by [Nexdev](https://nexdev.gr). This repository carries the full `0.2.1-beta.1` plugin implementation under its original WordPress plugin identity, `nexdev-site-connector`. The MCP endpoint, tool names, connection format, storage keys, and Node.js bridge settings follow that implementation.

The previously published `v0.1.0-beta.1` Community ZIP is a separate, reduced implementation. It is not an update for this plugin. A new download will be published only after the full build and release checks are complete.

[Implementation parity and verification](docs/PARITY.md) records the comparison with the original source edition. No plugin behavior was rewritten for this candidate.

## Capabilities

- Separate, expiring connections with read, content, commerce, and maintenance permissions; administrator rotation, revocation, and emergency disable.
- MCP tools for WordPress content, WooCommerce resources, Gutenberg blocks, libraries, design records, and supported extension adapters.
- Exact change proposals, WordPress administrator approval, resource revision checks, audit records, and execution locking.
- Native OAuth with administrator consent and a local stdio bridge for MCP clients.
- Temporary full maintenance, including PHP, SQL, WP-CLI, files, and administrator access. **Full maintenance is unrestricted access, not a sandbox.** It requires an explicit connection permission and a human-opened maintenance window.

The plugin is beta software. It requires PHP Sodium and a supported database advisory lock configuration. Validate the target WordPress, WooCommerce, PHP, database, and hosting configuration in staging before production use. The installer does not include an automatic updater.

## Install and connect

1. Build the plugin ZIP with `python3 build.py`. The output is `dist/nexdev-site-connector-0.2.1-beta.1.zip`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, install the ZIP, and activate it.
3. Open **Tools → NexPilot**. Create a separate connection for each MCP client and keep its one-time credential in a private file.
4. Configure the Node.js 20+ stdio bridge when the MCP client uses stdio:

```json
{
  "mcpServers": {
    "nexdev-site": {
      "command": "node",
      "args": ["/absolute/path/to/bridge/nexdev-mcp.mjs"],
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

The PHP suite uses WordPress/MySQL doubles and does not certify a production installation. The release process requires separate WordPress, WooCommerce, security, and packaging verification. See [release checks](docs/RELEASE.md).

## Project information

- [Architecture](docs/ARCHITECTURE.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- [GPL-2.0-or-later license](LICENSE)
