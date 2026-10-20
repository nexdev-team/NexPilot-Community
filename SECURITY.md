# Security policy

NexPilot Community handles connection credentials and publishes a read-only MCP endpoint. Please report a suspected vulnerability privately to the repository maintainers through GitHub's vulnerability reporting feature when it is available. If that feature is unavailable, contact Nexdev through its [official website](https://nexdev.gr) and request a private reporting channel. Do not post credentials or exploit details in a public issue.

## Supported version

Security fixes are prioritized for the current release and `main` branch. Release notes identify each supported version.

## Trust boundaries

- A WordPress administrator issues and revokes each bearer credential. Only a SHA-256 hash is stored by the plugin; the credential is displayed once.
- The MCP route requires HTTPS except for an explicitly enabled WordPress local environment. Browser Origin headers are rejected.
- Tools return only published posts/pages and visible published products. No write or administrator tool is registered.
- The local bridge reads its credential from an owner-only file, rejects redirects, and filters the advertised tool list.
- A credential still allows its holder to retrieve the published data exposed by this connector. Protect the client machine and revoke lost credentials.

Test updates on a staging copy of the intended WordPress/WooCommerce installation before deploying them to production.
