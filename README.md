# Coqui MCP Toolkit

MCP (Model Context Protocol) toolkit for [Coqui](https://github.com/AgentCoqui/coqui). It gives Coqui a first-class MCP management surface across the `mcp` tool, the `/mcp` REPL command, and the HTTP API, while exposing connected server tools as namespaced runtime child toolkits.

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

1. MCP servers are configured in `.workspace/mcp.json` using Claude Desktop-style stdio definitions plus optional operator metadata such as `description`.
2. The root toolkit exposes one management tool, `mcp`, plus the `/mcp` REPL command.
3. Connected servers expose their discovered tools through server-scoped child toolkits, with namespaced tool names like `mcp_github_create_issue`.
4. Server add, rename, update, enable, disable, env-link, and connectivity changes are re-read on future agent turns. Use `mcp(action: "connect", ...)`, `/mcp connect`, `/mcp refresh`, or a full restart when an already-running process needs an immediate runtime rebuild.
5. Per-server loading mode can be set to eager, deferred, or auto so MCP tool loading participates in Coqui's existing toolkit deferment model.

## Major Capabilities

- Shared MCP management service reused by the tool, REPL, and API so CRUD, search, auth, testing, and loading-mode controls stay consistent.
- Server-scoped deferment support through Coqui child toolkits and toolkit loading keys.
- Live operator inspection for configured servers, discovered tools, connectivity tests, and current loading mode.
- Browser-based OAuth and env-link credential management.
- Optional exact stdio command allow/deny policy when the toolkit is running inside Coqui.

## Tools Provided

### `mcp` (Management Tool)

Configure and manage MCP servers.

| Parameter | Type | Required | Description |
| --------- | ---- | -------- | ----------- |
| `action` | enum | Yes | `list`, `add`, `update`, `remove`, `set_env`, `enable`, `disable`, `promote`, `demote`, `auto`, `connect`, `disconnect`, `refresh`, `status`, `tools`, `search`, `test`, `auth` |
| `server` | string | No | Server name (required for all actions except `list`) |
| `command` | string | No | Executable command for `add` or `update` (for example `npx`, `uvx`, `docker`) |
| `args` | string | No | Space-separated arguments for `add`, `update`, `search` server filter, or OAuth scopes |
| `key` | string | No | Env var name for `set_env`, auth URL for `auth`, or search query for `search` |
| `value` | string | No | Credential value for `set_env` or token URL for `auth` |

### `mcp_{servername}_{toolname}` (Proxied Tools)

Every tool from connected MCP servers is exposed as a native Coqui tool through a server child toolkit. Parameters are converted from MCP JSON Schema to Coqui parameter types automatically.

Example: A GitHub MCP server's `create_issue` tool becomes `mcp_github_create_issue`.

### `/mcp` (REPL Command)

When the toolkit is installed into Coqui, the REPL exposes `/mcp` with help text and autocomplete for the same shared management actions, including `promote`, `demote`, `auto`, `search`, `test`, and `auth`.

### HTTP API

When the toolkit is installed into Coqui core, MCP server management is also available over HTTP under `/api/v1/mcp`. The API uses the same shared service layer as the `mcp` tool and `/mcp` REPL command for CRUD, connectivity tests, discovered-tool inspection, search, env-linking, auth, and loading-mode updates.

## Configuration

MCP servers are stored in `.workspace/mcp.json`:

```json
{
    "mcpServers": {
        "github": {
            "description": "Primary GitHub tools",
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

## Coqui Policy Integration

When the toolkit is loaded by Coqui through `McpToolkit::fromCoquiContext()`, it also inherits Coqui's MCP stdio command policy from `openclaw.json`.

```json
{
    "agents": {
        "defaults": {
            "mcp": {
                "allowedStdioCommands": [
                    ["npx", "-y", "@modelcontextprotocol/server-github"]
                ],
                "deniedStdioCommands": [
                    ["uvx", "mcp-server-fetch"]
                ]
            }
        }
    }
}
```

These entries are exact command tuples. They are enforced consistently by the shared MCP management service across the LLM-facing `mcp` tool, the `/mcp` REPL command, and Coqui's MCP HTTP API.

## Credential Management

MCP servers often require API keys. The workflow integrates with Coqui's credential system:

1. Agent adds a server: `mcp(action: "add", server: "github", command: "npx", args: "-y @modelcontextprotocol/server-github")`
2. Agent sets the credential: `mcp(action: "set_env", server: "github", key: "GITHUB_TOKEN", value: "ghp_xxx")`
3. Agent persists for restarts: `credentials(action: "set", key: "GITHUB_TOKEN", value: "ghp_xxx")`
4. Agent can verify connectivity immediately: `mcp(action: "test", server: "github")`
5. Tools become eligible for future turns without a full Coqui restart, and you can force an immediate runtime refresh with `mcp(action: "connect", ...)`, `/mcp connect`, `/mcp refresh`, or `/restart`.

The `set_env` action uses `putenv()` for immediate availability and stores a `${GITHUB_TOKEN}` reference in `mcp.json` (never the raw secret).

## OAuth Authentication

For MCP servers that require browser-based OAuth (like Canva, Linear, etc.):

```text
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
4. Agent validates connectivity: `mcp(action: "test", server: "github")`
5. Agent optionally pins eager loading: `mcp(action: "promote", server: "github")`
6. On future turns, GitHub tools are available as `mcp_github_*`
7. Agent uses tools directly: `mcp_github_create_issue(owner: "org", repo: "repo", title: "Bug fix")`

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
| ---- | ------- |
| `src/McpToolkit.php` | Root toolkit entry point -- management tool, REPL provider, and composite child-toolkit provider |
| `src/McpServerToolkit.php` | Server-scoped child toolkit for one connected MCP server |
| `src/McpManagementService.php` | Shared MCP business logic reused by tool, REPL, and API adapters |
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
| `src/Support/ServerLoadingModeStore.php` | Persists server-level eager or deferred loading overrides |
| `src/Support/McpServerPolicy.php` | Optional exact stdio allow/deny policy enforcement |
| `src/Auth/OAuthHandler.php` | OAuth 2.1 browser flow with PKCE |
| `src/Auth/OAuthException.php` | OAuth error types |
| `src/Exception/McpConnectionException.php` | Connection errors |
| `src/Exception/McpProtocolException.php` | Protocol errors |
| `src/Exception/McpToolCallException.php` | Tool call errors |

## Supported MCP Servers

Any MCP server that supports stdio transport works out of the box. Popular examples:

| Server | Command |
| ------ | ------- |
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
