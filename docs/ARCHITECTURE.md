# Architecture

```text
MCP client ── stdio bridge or OAuth HTTP ── WordPress REST routes
                                              │
                                              ├─ permissions and connection lifecycle
                                              ├─ WordPress/WooCommerce resources
                                              ├─ proposals and administrator approval
                                              └─ audit, jobs, files, and maintenance
```

The WordPress entry point is `plugin/nexdev-site-connector.php`. It registers the MCP endpoint at `nexdev-connector/v1/mcp`, OAuth routes, transfer routes, administrator controls, and the original `nsc_` storage namespace. The local stdio bridge is `bridge/nexdev-mcp.mjs` and uses the `NSC_` environment variables.

Each connection has its own permissions and expiry. Writes are proposed against a resource revision, reviewed by a WordPress administrator, and rechecked under the storage lock before execution. Maintenance operations additionally require a temporary administrator-opened window. Full maintenance executes with unrestricted WordPress/server-account power; its approval workflow is not an isolation boundary.

The source files under `plugin/` and `bridge/` are maintained as an unchanged runtime snapshot of the corresponding `0.2.1-beta.1` implementation while parity is verified. Publication documents and packaging live outside that runtime surface.
