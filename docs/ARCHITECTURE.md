# Architecture

```text
Local MCP client ── stdio ── Node.js bridge ── HTTPS + bearer token ── WordPress REST route
                                                                     │
                                                                     ├─ published posts/pages
                                                                     └─ visible published products
```

The bridge starts one process per client. It reads an owner-only credential file and never places the credential in a URL. It rejects redirects, remote HTTP, oversized responses, and tool calls outside the five read tools.

The WordPress plugin independently authenticates each request against a stored credential hash. The REST route advertises only read tools. Content and product status/visibility are checked again before returning records; lists are paginated and result text is bounded. WooCommerce is optional.

The plugin uses its own `nexpilot-community` identifier and `nexpilot_community_` option namespace. It does not read or modify data belonging to the full private NexPilot edition. There is no upgrade path between the editions.

## Design limits

- Read-only refers to this connector's tool surface; other WordPress plugins, users, and clients retain their own permissions.
- Only published content is exposed, but published content may still contain information the site owner considers sensitive. Review the site before connecting a model provider.
- The PHP test harness uses WordPress doubles. Real WordPress/WooCommerce and hosting behavior remain release gates.
