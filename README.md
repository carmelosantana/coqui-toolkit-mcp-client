# Coqui MCP Toolkit

**Optional** MCP (Model Context Protocol) management UX for [Coqui](https://github.com/AgentCoqui/coqui). It gives Coqui a first-class interactive MCP management surface: the `mcp` agent tool, the `/mcp` REPL command, and browser-based OAuth for servers that require it.

The MCP engine itself — the client, stdio transport, JSON-RPC handling, `.workspace/mcp.json` config management, per-server tool exposure, and the HTTP API under `/api/v1/mcp` — ships in **Coqui core** by default (`CoquiBot\Coqui\Mcp\*`). MCP servers work out of the box without this package: you can add, remove, update, enable/disable, connect, test, and inspect servers entirely through the HTTP API or by editing `.workspace/mcp.json` directly. This package adds nothing to that engine — it only adds the tool and REPL surfaces on top of it, plus the one capability core doesn't ship: OAuth.

## Requirements

- A Coqui core that ships the MCP engine + `McpRuntime` (exposes `registerOAuth()` and `managementService()`, and passes a runtime instance through discovery context as `mcp_runtime`)
- PHP 8.4+
- A browser, for the OAuth flow (only needed if you use `auth`)

## Installation

```bash
composer require coquibot/coqui-toolkit-mcp-client
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

At boot, the toolkit reads Coqui's `mcp_runtime` from the discovery context, registers its OAuth handler into it (`McpRuntime::registerOAuth()`), and adopts its shared `McpManagementService` (`McpRuntime::managementService()`). Every management action is delegated to that core service — the toolkit holds no MCP state of its own. If no runtime is present (an older or misconfigured core), the toolkit no-ops cleanly: `tools()` and `commandHandlers()` both return an empty array, so no `mcp` tool or `/mcp` command is registered.

## What This Package Adds

| Surface | Description |
| --- | --- |
| `mcp` tool | LLM-facing tool for listing, adding, updating, removing, enabling/disabling, promoting/demoting/auto loading mode, connecting/disconnecting/refreshing, testing, searching, and authorizing MCP servers |
| `/mcp` REPL command | Same actions as the `mcp` tool, with standardized help text and tab-completion, for interactive operator use |
| OAuth | Browser-based OAuth 2.1 with PKCE for MCP servers that require authenticated access (implements `CoquiBot\Coqui\Contract\McpOAuthInterface`) |

What this package does **not** provide (because core already does): the MCP client/transport, `.workspace/mcp.json` config management, per-server tool exposure to the agent, or the HTTP API. Per-server MCP tools are exposed by core as ordinary candidate toolkits and participate in Coqui's normal budget-gated loading model — deferred by default, promotable to eager per server — with or without this package installed.

## Tools Provided

### `mcp` (Management Tool)

Configure and manage MCP servers.

| Parameter | Type | Required | Description |
| --------- | ---- | -------- | ----------- |
| `action` | enum | Yes | `list`, `add`, `update`, `remove`, `set_env`, `enable`, `disable`, `promote`, `demote`, `auto`, `connect`, `disconnect`, `refresh`, `status`, `tools`, `search`, `test`, `auth` |
| `server` | string | No | Server name (required for all actions except `list`) |
| `command` | string | No | Executable command for `add` or `update` (for example `npx`, `uvx`, `docker`), or client ID for `auth` |
| `args` | string | No | Space-separated arguments for `add`, `update`, `search` server filter, or OAuth scopes for `auth` |
| `key` | string | No | Env var name for `set_env`, auth URL for `auth`, or search query for `search` |
| `value` | string | No | Credential value for `set_env` or token URL for `auth` |

### `/mcp` (REPL Command)

When the toolkit is installed into Coqui, the REPL exposes `/mcp` with help text and autocomplete for the same shared management actions, including `promote`, `demote`, `auto`, `search`, `test`, and `auth`. Run `/mcp` or `/mcp help` for the full command reference.

### Proxied MCP Tools

Every tool discovered from a connected MCP server is exposed by **Coqui core** as a native agent tool namespaced `mcp_{servername}_{toolname}` (for example, a GitHub server's `create_issue` tool becomes `mcp_github_create_issue`). This works whether or not this toolkit is installed — see core's MCP documentation for details.

### HTTP API

MCP server management is available over HTTP under `/api/v1/mcp` as a **core** feature and works without this package installed, using the same shared `McpManagementService` as the `mcp` tool and `/mcp` REPL command. The only exception is `POST /api/v1/mcp/servers/{name}/auth`, which requires this package: without it, that endpoint returns an error stating that OAuth requires the management toolkit.

## Configuration

MCP servers are stored in `.workspace/mcp.json` (managed by core, unchanged by this package):

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

Environment variables use `${VAR_NAME}` placeholders that resolve via `getenv()` at connect time. This works with Coqui's credential system — secrets are stored in `.env` via the `credentials` tool, not in `mcp.json`.

## Coqui Policy Integration

Core enforces an optional MCP stdio command allow/deny policy from `openclaw.json`, independent of whether this toolkit is installed:

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

These entries are exact command tuples. They are enforced consistently by the shared `McpManagementService` across the LLM-facing `mcp` tool, the `/mcp` REPL command, and Coqui's MCP HTTP API.

## Credential Management

MCP servers often require API keys. The workflow integrates with Coqui's credential system:

1. Agent adds a server: `mcp(action: "add", server: "github", command: "npx", args: "-y @modelcontextprotocol/server-github")`
2. Agent sets the credential: `mcp(action: "set_env", server: "github", key: "GITHUB_TOKEN", value: "ghp_xxx")`
3. Agent persists for restarts: `credentials(action: "set", key: "GITHUB_TOKEN", value: "ghp_xxx")`
4. Agent can verify connectivity immediately: `mcp(action: "test", server: "github")`
5. Tools become eligible for future turns without a full Coqui restart, and you can force an immediate runtime refresh with `mcp(action: "connect", ...)`, `/mcp connect`, `/mcp refresh`, or `/restart`.

The `set_env` action uses `putenv()` for immediate availability and stores a `${GITHUB_TOKEN}` reference in `mcp.json` (never the raw secret).

## OAuth Authentication

For MCP servers that require browser-based OAuth (like Canva, Linear, etc.), this package is what makes the `auth` action work — core has no OAuth implementation of its own:

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

Without this package installed, `mcp(action: "auth", ...)` and `POST /api/v1/mcp/servers/{name}/auth` both fail with an error explaining that OAuth requires the management toolkit.

## Agent Workflow

1. User asks to "connect to the GitHub MCP server"
2. Agent adds the server: `mcp(action: "add", server: "github", command: "npx", args: "-y @modelcontextprotocol/server-github")`
3. Agent sets credentials: `mcp(action: "set_env", server: "github", key: "GITHUB_TOKEN", value: "...")`
4. Agent validates connectivity: `mcp(action: "test", server: "github")`
5. Agent optionally pins eager loading: `mcp(action: "promote", server: "github")`
6. On future turns, GitHub tools are available as `mcp_github_*` (exposed by Coqui core)
7. Agent uses tools directly: `mcp_github_create_issue(owner: "org", repo: "repo", title: "Bug fix")`

## Architecture

| File | Purpose |
| ---- | ------- |
| `src/McpToolkit.php` | Root toolkit entry point — adopts core's `McpRuntime` via `fromCoquiContext()`, registers the OAuth handler, exposes the `mcp` tool and REPL command handler, no-ops when no runtime is present |
| `src/Command/McpCommandHandler.php` | `/mcp` REPL command: subcommands, help text, and tab-completion, delegating to core's `McpManagementService` |
| `src/Auth/OAuthHandler.php` | Implements core's `CoquiBot\Coqui\Contract\McpOAuthInterface` — OAuth 2.1 browser flow with PKCE |
| `src/Auth/OAuthException.php` | OAuth error types |
| `src/Support/McpManagementFormatter.php` | Formats server lists, status snapshots, discovered tools, and search results for tool/REPL output |
| `src/Support/ArgumentTokenizer.php` | Splits shell-like argument strings for `add`/`update`/`search` inputs |

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
