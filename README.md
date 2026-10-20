# NexPilot Community

**A focused, read-only MCP connector for published WordPress content.**

NexPilot Community is developed by [Nexdev](https://nexdev.gr). It gives local MCP clients a bounded way to read published posts, pages, and visible WooCommerce catalog products. Every client receives its own expiring credential. The server enforces the read-only boundary; the bridge also rejects unsupported tools before sending a request.

> **Beta preview:** This repository is being prepared for public release. It has not been presented as a stable product, independently audited, or approved for the WordPress.org Plugin Directory. Validate it on staging before use on a live site.

## Scope

| Included | Outside this edition |
| --- | --- |
| Published posts and pages | Drafts, private content, users, customers, orders, and form entries |
| Visible published WooCommerce products | Checkout, refunds, inventory changes, and private product data |
| Separate 30-day credentials, revocation, HTTPS transport | OAuth, file operations, PHP/SQL/WP-CLI, and administrator access |
| MCP stdio bridge for local clients | Hosted AI service, telemetry, and automatic updates |

The full NexPilot product is maintained separately. This community edition has its own plugin identifier and storage keys, so it is not an in-place update for existing full-edition installations.

## Requirements

- WordPress 6.6 or newer and PHP 8.1 or newer.
- Node.js 20 or newer for the optional stdio bridge.
- HTTPS on the WordPress site. A local-only HTTP switch exists for isolated development.
- WooCommerce only if the product tools are needed.

## Install and connect

1. Build the plugin with `python3 build.py`, or package only the `plugin/` directory as `nexpilot-community/`.
2. Install the ZIP in WordPress. Open **Tools → NexPilot Community** and create a separate connection for each client.
3. Copy the credential shown once into an owner-only file outside this repository (`chmod 600` on POSIX).
4. Configure a local MCP stdio client with the bridge:

```json
{
  "mcpServers": {
    "nexpilot-community": {
      "command": "node",
      "args": ["/absolute/path/to/nexpilot-community/bridge/nexpilot-mcp.mjs"],
      "env": {
        "NEXPILOT_ENDPOINT": "https://your-site.example/wp-json/nexpilot-community/v1/mcp",
        "NEXPILOT_TOKEN_FILE": "/private/path/client.token"
      }
    }
  }
}
```

The exact client configuration format can differ. Keep credentials out of URLs, screenshots, logs, and chat. Revoke a client from WordPress if its credential is no longer controlled.

## MCP tools

`site_info`, `content_list`, `content_get`, `products_list`, and `product_get`. Lists are paginated and bounded. Content tools require published posts or pages; product tools require visible published products. Returned text is size limited. No mutation tool is registered.

## Verification

```sh
php tests/protocol.php
node --test tests/bridge.test.mjs
python3 build.py
python3 tests/integration-docker.py # optional disposable WordPress + MariaDB check
```

The PHP tests use WordPress doubles and cover credential handling, transport checks, published-content boundaries, and the advertised tool set. The Docker check installs the plugin on a disposable real WordPress/MariaDB site with synthetic content, then verifies a published read, draft denial, revocation, and uninstall cleanup. The bridge tests cover concurrent clients, token-file permissions, redirects, and pre-network rejection of mutation calls. These checks do not establish complete WooCommerce, administrator UI, version-matrix, or hosting compatibility. See [release criteria](docs/RELEASE.md) for remaining work.

## Project practices

The repository contains reviewed source, reproducible build instructions, and synthetic tests. Client-specific scripts, production evidence, historical binaries, credentials, and private runtime data remain outside this project. See [architecture](docs/ARCHITECTURE.md), [security](SECURITY.md), and [contributing](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
