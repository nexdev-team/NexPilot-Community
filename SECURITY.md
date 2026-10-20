# Security policy

Report suspected vulnerabilities privately through GitHub's **Report a vulnerability** feature for this repository. If that option is unavailable, contact [Nexdev](https://nexdev.gr) and request a private security reporting channel. Do not put exploit details or credentials in public issues.

## Security model

- WordPress administrators grant and revoke individual connections. Content, commerce, and maintenance permissions are separate.
- Changes require an exact proposal and a separate administrator approval; the plugin rechecks identity, expiry, authorization, and resource revision before execution.
- Full maintenance is deliberately unrestricted access to the WordPress/server account. A temporary maintenance window limits when new connector actions can begin; it does not sandbox PHP, SQL, WP-CLI, files, or external effects.
- Keep the MCP endpoint on HTTPS. Store connection credentials in owner-only files, never in URLs or version control.
- An MCP client's model provider may receive data returned to that client under its configuration. Review that configuration before granting access.

The `0.2.1-beta.1` build remains a beta. Local tests and this policy are not an independent security certification.
