# Security policy

NexPilot Community handles client credentials and publishes a narrow read-only MCP endpoint. Please report a suspected vulnerability privately to the repository maintainers through GitHub's vulnerability reporting feature when it is available. If that feature is unavailable, contact Nexdev through its [official website](https://nexdev.gr) and request a private reporting channel. Do not post credentials, exploit details, or customer data in a public issue.

## Current support scope

The `main` branch is a beta preview. Security fixes are prioritized there. No historical version or production support window is promised yet.

## Trust boundaries

- A WordPress administrator issues and revokes each bearer credential. Only a SHA-256 hash is stored by the plugin; the credential is displayed once.
- The MCP route requires HTTPS except for an explicitly enabled WordPress local environment. Browser Origin headers are rejected.
- Tools return only published posts/pages and visible published products. No write or administrator tool is registered.
- The local bridge reads its credential from an owner-only file, rejects redirects, and filters the advertised tool list.
- A credential still allows its holder to retrieve the published data exposed by this connector. Protect the client machine and revoke lost credentials.

These boundaries require testing on the intended WordPress/WooCommerce versions before a production deployment. The included PHP doubles and bridge tests are not an independent audit.
