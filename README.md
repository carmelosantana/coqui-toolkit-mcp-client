# Coqui MCP Toolkit

MCP (Model Context Protocol) toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Enables agents to consume tools from any MCP-compatible server via stdio transport. Supports automatic tool discovery, namespaced tool registration, credential management, and OAuth browser-based authentication.

## Requirements

- PHP 8.4+
- Node.js 18+ (most MCP servers run via `npx`)
- Python 3.10+ (for Python-based MCP servers via `uvx`)

## Installation

```bash
composer require coquibot/coqui-toolkit-mcp-client
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` -- no manual registration needed.

## How It Works

1. MCP servers are configured in `.workspace/mcp.json` using Claude Desktop format
2. At boot, the toolkit connects to all enabled servers via stdio transport
3. Each server's tools are discovered via `tools/list` and namespaced as `mcp_{servername}_{toolname}`
4. Tool calls are proxied transparently -- the agent calls `mcp_github_create_issue(...)` and the toolkit routes it to the correct MCP server

## Tools Provided

### `mcp` (Management Tool)

Configure and manage MCP servers.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `list`, `add`, `remove`, `set_env`, `enable`, `disable`, `connect`, `disconnect`, `status`, `tools`, `auth` |
| `server` | string | No | Server name (required for all actions except `list`) |
| `command` | string | No | Executable command for `add` (e.g., `npx`, `uvx`, `docker`) |
| `args` | string | No | Space-separated arguments for `add` (e.g., `-y @modelcontextprotocol/server-github`) |
| `key` | string | No | Env var name for `set_env`; auth URL for `auth` |
| `value` | string | No | Credential value for `set_env`; token URL for `auth` |

### `mcp_{servername}_{toolname}` (Proxied Tools)

Every tool from connected MCP servers is registered as a native Coqui tool. Parameters are converted from MCP JSON Schema to Coqui Parameter types automatically.

Example: A GitHub MCP server's `create_issue` tool becomes `mcp_github_create_issue`.

## Configuration

MCP servers are stored in `.workspace/mcp.json`:

```json
{
    "mcpServers": {
        "github": {
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-github"],
            "env": {
                "GITHUB_TOKEN": "${GITHUB_TOKEN}"
            }
        },
        "filesystem": {
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-filesystem", "/path/to/dir"]
        },
        "brave-search": {
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-brave-search"],
            "env": {
                "BRAVE_API_KEY": "${BRAVE_API_KEY}"
            },
            "disabled": true
        }
    }
}
```

Environment variables use `${VAR_NAME}` placeholders that resolve via `getenv()` at connect time. This works with Coqui's credential system -- secrets are stored in `.env` via the `credentials` tool, not in `mcp.json`.

## Credential Management

MCP servers often require API keys. The workflow integrates with Coqui's credential system:

1. Agent adds a server: `mcp(action: "add", server: "github", command: "npx", args: "-y @modelcontextprotocol/server-github")`
2. Agent sets the credential: `mcp(action: "set_env", server: "github", key: "GITHUB_TOKEN", value: "ghp_xxx")`
3. Agent persists for restarts: `credentials(action: "set", key: "GITHUB_TOKEN", value: "ghp_xxx")`
4. Agent restarts to register tools: `restart_coqui(reason: "Activate MCP server github")`

The `set_env` action uses `putenv()` for immediate availability and stores a `${GITHUB_TOKEN}` reference in `mcp.json` (never the raw secret).

## OAuth Authentication

For MCP servers that require browser-based OAuth (like Canva, Linear, etc.):

```
mcp(action: "auth", server: "canva", key: "https://auth.canva.com/authorize", value: "https://auth.canva.com/token")
```

The `auth` action:
1. Generates a PKCE code verifier and challenge
2. Starts a temporary local HTTP server for the callback
3. Opens the authorization URL in the user's browser
4. Waits for the callback with the authorization code
5. Exchanges the code for access/refresh tokens
6. Stores tokens in `.workspace/.mcp-tokens/{servername}.json`
7. Sets the access token as an environment variable

Optional parameters via `command` (client ID) and `args` (space-separated scopes).

## Agent Workflow

1. User asks to "connect to the GitHub MCP server"
2. Agent adds the server: `mcp(action: "add", server: "github", command: "npx", args: "-y @modelcontextprotocol/server-github")`
3. Agent sets credentials: `mcp(action: "set_env", server: "github", key: "GITHUB_TOKEN", value: "...")`
4. Agent restarts: `restart_coqui(reason: "Register GitHub MCP tools")`
5. After restart, GitHub tools are available as `mcp_github_*`
6. Agent uses tools directly: `mcp_github_create_issue(owner: "org", repo: "repo", title: "Bug fix")`

## Standalone Usage

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Mcp\McpToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = new McpToolkit('/path/to/.workspace');

foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . PHP_EOL;
}
```

## Architecture

| File | Purpose |
|------|---------|
| `src/McpToolkit.php` | ToolkitInterface entry point -- management tool + proxied tools |
| `src/McpServerManager.php` | Multi-server orchestration, tool namespacing, routing |
| `src/McpClient.php` | MCP protocol lifecycle (initialize, tools/list, tools/call) |
| `src/JsonRpc/Message.php` | JSON-RPC 2.0 message builder and parser |
| `src/JsonRpc/JsonRpcError.php` | Error value object |
| `src/JsonRpc/IdGenerator.php` | Request ID counter |
| `src/Transport/TransportInterface.php` | Transport abstraction |
| `src/Transport/StdioTransport.php` | Persistent subprocess via `proc_open()` |
| `src/Config/McpConfig.php` | CRUD for `.workspace/mcp.json` |
| `src/Config/EnvResolver.php` | `${VAR}` placeholder resolution |
| `src/Schema/SchemaConverter.php` | MCP JSON Schema to Coqui Parameter types |
| `src/Auth/OAuthHandler.php` | OAuth 2.1 browser flow with PKCE |
| `src/Auth/OAuthException.php` | OAuth error types |
| `src/Exception/McpConnectionException.php` | Connection errors |
| `src/Exception/McpProtocolException.php` | Protocol errors |
| `src/Exception/McpToolCallException.php` | Tool call errors |

## Supported MCP Servers

Any MCP server that supports stdio transport works out of the box. Popular examples:

| Server | Command |
|--------|---------|
| GitHub | `npx -y @modelcontextprotocol/server-github` |
| Filesystem | `npx -y @modelcontextprotocol/server-filesystem /path` |
| Brave Search | `npx -y @modelcontextprotocol/server-brave-search` |
| PostgreSQL | `npx -y @modelcontextprotocol/server-postgres postgresql://...` |
| Puppeteer | `npx -y @modelcontextprotocol/server-puppeteer` |
| SQLite | `uvx mcp-server-sqlite --db-path /path/to/db.sqlite` |

## Development

```bash
git clone https://github.com/AgentCoqui/coqui-toolkit-mcp-client.git
cd coqui-toolkit-mcp-client
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
