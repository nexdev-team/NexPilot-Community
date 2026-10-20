<?php
namespace Nexdev\SiteConnector;
// Only these explicitly authored messages are safe to expose over MCP.
class ConnectorError extends \RuntimeException {}
class ValidationError extends \InvalidArgumentException {}

class RateLimitError extends ConnectorError {}
