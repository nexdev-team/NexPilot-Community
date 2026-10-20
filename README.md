# NexPilot Community

**Connect published WordPress content to MCP clients through a secure, read-only interface.**

NexPilot Community is developed and maintained by [Nexdev](https://nexdev.gr). The WordPress plugin provides a managed MCP endpoint for published posts and pages, with WooCommerce catalog support when WooCommerce is active. Administrators can issue separate, expiring connections for their clients and revoke them at any time.

[![Download WordPress plugin ZIP](assets/download-plugin.svg)](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.1.0-beta.1/nexpilot-community.zip)

The download is a ready-to-install WordPress ZIP. The repository is currently private, so GitHub access is required until publication.

## Install

1. Download **[nexpilot-community.zip](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.1.0-beta.1/nexpilot-community.zip)**. Do not extract it.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP, then select **Install Now** and **Activate Plugin**.
3. Open **Tools → NexPilot Community**, enter a name for your MCP client, and select **Create connection**.
4. Copy the credential displayed once and save it in a private file on the computer running the MCP client.
5. Download the **[MCP bridge](https://github.com/nexdev-team/NexPilot-Community/releases/download/v0.1.0-beta.1/nexpilot-community-bridge.mjs)** and configure your client as shown below.

For an existing installation, upload the ZIP again and use WordPress's **Replace current with uploaded** option. Existing connections are retained during an update. Back up the site before changing plugins on a production installation.

## Connect an MCP client

The bridge requires Node.js 20 or newer. Save the credential in a file readable only by your account (`chmod 600` on macOS/Linux). Use absolute paths in the client configuration:

```json
{
  "mcpServers": {
    "nexpilot-community": {
      "command": "node",
      "args": ["/absolute/path/to/nexpilot-community-bridge.mjs"],
      "env": {
        "NEXPILOT_ENDPOINT": "https://your-site.example/wp-json/nexpilot-community/v1/mcp",
        "NEXPILOT_TOKEN_FILE": "/private/path/client.token"
      }
    }
  }
}
```

Client configuration formats vary. The WordPress site must use HTTPS. Credentials belong in a private file, never in a URL or a committed configuration file. Revoke a connection from **Tools → NexPilot Community** if its credential is lost.

## Capabilities

The connector offers five MCP tools: `site_info`, `content_list`, `content_get`, `products_list`, and `product_get`. It returns published posts and pages and visible, published WooCommerce products. Lists are paginated and text responses are size limited. The WordPress endpoint checks each request independently of the bridge.

## Requirements

| Component | Minimum version |
| --- | --- |
| WordPress | 6.6 |
| PHP | 8.1 |
| Node.js for the MCP bridge | 20 |

WooCommerce is needed for product tools. The WordPress site must serve the MCP endpoint over HTTPS.

## Development

```sh
php tests/protocol.php
node --test tests/bridge.test.mjs
python3 tests/integration-docker.py
python3 build.py
```

`build.py` creates the installable plugin ZIP, bridge file, and SHA-256 checksums in `dist/`. The integration check uses a disposable WordPress/MariaDB site with synthetic content. Release preparation and verification are documented in [docs/RELEASE.md](docs/RELEASE.md).

## Project information

- [Architecture](docs/ARCHITECTURE.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- [GPL-2.0-or-later license](LICENSE)
