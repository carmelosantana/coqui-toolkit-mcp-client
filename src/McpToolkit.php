<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Contract\ReplCommandProvider;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Toolkits\Mcp\Command\McpCommandHandler;
use CoquiBot\Toolkits\Mcp\Auth\OAuthHandler;
use CoquiBot\Toolkits\Mcp\Config\McpConfig;
use CoquiBot\Toolkits\Mcp\Support\McpManagementFormatter;

/**
 * MCP (Model Context Protocol) toolkit for Coqui.
 *
 * Consumes MCP servers as agent tools via stdio transport. Each configured
 * MCP server's tools are discovered at boot, namespaced as
 * mcp_{servername}_{toolname}, and registered with the orchestrator.
 *
 * Provides a management tool `mcp` for the LLM to add, remove, configure,
 * and connect MCP servers.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class McpToolkit implements ToolkitInterface, ReplCommandProvider
{
    private readonly McpConfig $config;
    private readonly McpServerManager $manager;
    private readonly OAuthHandler $oauthHandler;
    private readonly McpManagementService $service;
    private readonly McpManagementFormatter $formatter;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->config = new McpConfig($this->workspacePath);
        $this->manager = new McpServerManager($this->config);
        $this->oauthHandler = new OAuthHandler($this->workspacePath);
        $this->service = new McpManagementService($this->config, $this->manager, $this->oauthHandler);
        $this->formatter = new McpManagementFormatter();
        $this->boot();
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspace = getenv('COQUI_WORKSPACE');

        if ($workspace === false || $workspace === '') {
            // Fallback: CWD + .workspace
            $cwd = getcwd() ?: '.';
            $workspace = $cwd . '/.workspace';
        }

        return new self($workspace);
    }

    /**
     * @return ToolInterface[]
     */
    #[\Override]
    public function tools(): array
    {
        return [
            $this->mcpManagementTool(),
            ...$this->service->tools(),
        ];
    }

    /**
     * @return list<ToolkitCommandHandler>
     */
    public function commandHandlers(): array
    {
        return [new McpCommandHandler($this->service, $this->formatter)];
    }

    #[\Override]
    public function guidelines(): string
    {
        $lines = [];
        $lines[] = '<MCP-GUIDELINES>';
        $lines[] = 'MCP (Model Context Protocol) tools are proxied from external MCP servers.';
        $lines[] = '';

        // Connected servers and their tools
        $allStatus = $this->service->listServers();

        if ($allStatus !== []) {
            $lines[] = '## Connected MCP Servers';
            $lines[] = '';

            foreach ($allStatus as $name => $status) {
                $state = $status['disabled'] ? 'DISABLED' : ($status['connected'] ? 'CONNECTED' : 'DISCONNECTED');
                $serverLabel = $status['serverName'] ?? $name;
                $lines[] = sprintf('- **%s** [%s] — %d tools', $serverLabel, $state, $status['toolCount']);

                if ($status['error'] !== null) {
                    $lines[] = sprintf('  Error: %s', $status['error']);
                }
            }

            $lines[] = '';
        }

        // Server instructions
        $instructions = $this->service->serverInstructions();

        if ($instructions !== []) {
            $lines[] = '## Server Instructions';
            $lines[] = '';

            foreach ($instructions as $name => $text) {
                $lines[] = sprintf('### %s', $name);
                $lines[] = $text;
                $lines[] = '';
            }
        }

        // Usage guidance
        $lines[] = '## Usage';
        $lines[] = '';
        $lines[] = '- MCP tools are named `mcp_{servername}_{toolname}` — call them directly like any other tool.';
        $lines[] = '- Use `mcp(action: "list")` or `/mcp list` to see all configured servers and their status.';
        $lines[] = '- Use `mcp(action: "add", ...)` or `/mcp add ...` to add new MCP servers.';
        $lines[] = '- Use `mcp(action: "set_env", ...)` to configure credentials for a server.';
        $lines[] = '- Use `mcp(action: "auth", server: "...", key: "AUTH_URL", value: "TOKEN_URL")` or `/mcp auth ...` for OAuth browser-based auth.';
        $lines[] = '- Server config and connectivity changes apply to new agent turns without a full Coqui restart.';
        $lines[] = '</MCP-GUIDELINES>';

        return implode("\n", $lines);
    }

    /**
     * Connect to all enabled servers at boot.
     */
    private function boot(): void
    {
        $this->config->load();
        $enabledServers = $this->config->listEnabledServers();

        if ($enabledServers !== []) {
            $this->manager->connectAll();
        }
    }

    /**
     * The LLM-facing `mcp` management tool.
     */
    private function mcpManagementTool(): ToolInterface
    {
        return new Tool(
            name: 'mcp',
            description: 'Manage MCP (Model Context Protocol) servers — add, remove, configure, connect, and check status of MCP servers.',
            parameters: [
                new EnumParameter(
                    name: 'action',
                    description: 'The management action to perform.',
                    values: ['list', 'add', 'update', 'remove', 'set_env', 'enable', 'disable', 'connect', 'disconnect', 'refresh', 'status', 'tools', 'search', 'test', 'auth'],
                    required: true,
                ),
                new StringParameter(
                    name: 'server',
                    description: 'Server name (required for all actions except "list").',
                    required: false,
                ),
                new StringParameter(
                    name: 'command',
                    description: 'The executable command to launch the MCP server (for "add" action). e.g., "npx", "uvx", "docker".',
                    required: false,
                ),
                new StringParameter(
                    name: 'args',
                    description: 'Space-separated arguments for the server command (for "add" action). e.g., "-y @modelcontextprotocol/server-github".',
                    required: false,
                ),
                new StringParameter(
                    name: 'key',
                    description: 'Environment variable name (for "set_env" action). e.g., "GITHUB_TOKEN".',
                    required: false,
                ),
                new StringParameter(
                    name: 'value',
                    description: 'Credential value (for "set_env" action). The actual secret value to store.',
                    required: false,
                ),
                new StringParameter(
                    name: 'query',
                    description: 'Search query for MCP tool discovery (for "search" action).',
                    required: false,
                ),
            ],
            callback: fn(array $input): ToolResult => $this->executeMcpAction($input),
        );
    }

    /** @param array<string, mixed> $input */
    private function executeMcpAction(array $input): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));
        $server = trim((string) ($input['server'] ?? ''));

        return match ($action) {
            'list' => $this->actionList(),
            'add' => $this->actionAdd($server, $input),
            'update' => $this->actionUpdate($server, $input),
            'remove' => $this->actionRemove($server),
            'set_env' => $this->actionSetEnv($server, $input),
            'enable' => $this->actionEnable($server),
            'disable' => $this->actionDisable($server),
            'connect' => $this->actionConnect($server),
            'disconnect' => $this->actionDisconnect($server),
            'refresh' => $this->actionRefresh($server),
            'status' => $this->actionStatus($server),
            'tools' => $this->actionTools($server),
            'search' => $this->actionSearch($input),
            'test' => $this->actionTest($server),
            'auth' => $this->actionAuth($server, $input),
            default => ToolResult::error(sprintf('Unknown MCP action: "%s"', $action)),
        };
    }

    private function actionList(): ToolResult
    {
        return ToolResult::success($this->formatter->formatServerList($this->service->listServers()));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionAdd(string $server, array $input): ToolResult
    {
        try {
            $command = trim((string) ($input['command'] ?? ''));
            $argsRaw = trim((string) ($input['args'] ?? ''));
            $args = $argsRaw !== '' ? $this->service->parseArgs($argsRaw) : [];
            $result = $this->service->addServer($server, $command, $args);

            return ToolResult::success(sprintf(
                "MCP server \"%s\" added successfully.\n\n"
                . "Command: %s %s\n"
                . "Applied: %s\n\n"
                . "If this server needs credentials, set them with mcp(action: \"set_env\", server: \"%s\", key: \"API_KEY_NAME\", value: \"your-key\").",
                $result['name'],
                $result['command'],
                implode(' ', $result['args']),
                $result['applied'],
                $result['name'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionUpdate(string $server, array $input): ToolResult
    {
        try {
            $command = isset($input['command']) ? trim((string) $input['command']) : null;
            $argsRaw = isset($input['args']) ? trim((string) $input['args']) : null;
            $args = $argsRaw !== null && $argsRaw !== '' ? $this->service->parseArgs($argsRaw) : null;
            $result = $this->service->updateServer($server, $command, $args);

            return ToolResult::success(sprintf(
                'MCP server "%s" updated successfully. Applied: %s.',
                $result['name'],
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionRemove(string $server): ToolResult
    {
        try {
            $result = $this->service->removeServer($server);

            return ToolResult::success(sprintf(
                'MCP server "%s" removed. Applied: %s.',
                $result['name'],
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionSetEnv(string $server, array $input): ToolResult
    {
        try {
            $key = trim((string) ($input['key'] ?? ''));
            $value = (string) ($input['value'] ?? '');
            $result = $this->service->setServerSecret($server, $key, $value);

            return ToolResult::success(sprintf(
                "Environment variable \"%s\" linked for server \"%s\".\n\n"
                . "Applied: %s\n"
                . "If this value must survive process restarts, also store it in Coqui credentials.",
                $result['key'],
                $result['name'],
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionEnable(string $server): ToolResult
    {
        try {
            $result = $this->service->enableServer($server);

            return ToolResult::success(sprintf(
                'MCP server "%s" enabled. Applied: %s.',
                $result['name'],
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionDisable(string $server): ToolResult
    {
        try {
            $result = $this->service->disableServer($server);

            return ToolResult::success(sprintf(
                'MCP server "%s" disabled. Applied: %s.',
                $result['name'],
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionConnect(string $server): ToolResult
    {
        try {
            $result = $this->service->connectServer($server);

            return ToolResult::success(sprintf(
                "MCP server \"%s\" connected successfully in %d ms.\n\n%s",
                $result['name'],
                $result['duration_ms'],
                $this->formatter->formatServerStatus($result['snapshot']),
            ));
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Failed to connect to MCP server "%s": %s', $server, $e->getMessage()));
        }
    }

    private function actionDisconnect(string $server): ToolResult
    {
        try {
            $result = $this->service->disconnectServer($server);

            return ToolResult::success(sprintf('MCP server "%s" disconnected. Applied: %s.', $result['name'], $result['applied']));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionRefresh(string $server): ToolResult
    {
        try {
            $result = $this->service->refreshServer($server);

            return ToolResult::success(sprintf(
                "MCP server \"%s\" refreshed in %d ms.\n\n%s",
                $result['name'],
                $result['duration_ms'],
                $this->formatter->formatServerStatus($result['snapshot']),
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionStatus(string $server): ToolResult
    {
        try {
            return ToolResult::success($this->formatter->formatServerStatus($this->service->getServerSnapshot($server)));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionTools(string $server): ToolResult
    {
        try {
            return ToolResult::success($this->formatter->formatServerTools($server, $this->service->getServerTools($server)));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionSearch(array $input): ToolResult
    {
        try {
            $query = trim((string) ($input['query'] ?? ''));
            $server = trim((string) ($input['server'] ?? ''));

            return ToolResult::success($this->formatter->formatSearchResults($query, $this->service->searchTools($query, $server !== '' ? $server : null)));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function actionTest(string $server): ToolResult
    {
        try {
            $result = $this->service->testServer($server);

            return ToolResult::success(sprintf(
                "MCP server \"%s\" connectivity test succeeded in %d ms.\n\n%s",
                $result['name'],
                $result['duration_ms'],
                $this->formatter->formatServerStatus($result['snapshot']),
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * Perform OAuth browser-based authentication for a server.
     *
     * Uses the `key` parameter for authUrl and `value` for tokenUrl.
     * Opens the user's browser for authorization, then stores tokens.
     *
     * @param array<string, mixed> $input
     */
    private function actionAuth(string $server, array $input): ToolResult
    {
        try {
            $authUrl = trim((string) ($input['key'] ?? ''));
            $tokenUrl = trim((string) ($input['value'] ?? ''));
            $clientId = trim((string) ($input['command'] ?? ''));
            $scopesRaw = trim((string) ($input['args'] ?? ''));
            $scopes = $scopesRaw !== '' ? $this->service->parseArgs($scopesRaw) : [];
            $result = $this->service->authorizeServer($server, $authUrl, $tokenUrl, $clientId, $scopes);

            $expiresInfo = $result['expires_at'] !== null
                ? sprintf(' Expires: %s.', gmdate('Y-m-d H:i:s', $result['expires_at']))
                : '';

            return ToolResult::success(sprintf(
                "OAuth authentication successful for server \"%s\".\n\n"
                . "Access token stored as env var \"%s\".%s\n"
                . "Applied: %s",
                $result['server'],
                $result['env_key'],
                $expiresInfo,
                $result['applied'],
            ));
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('OAuth authentication failed for server "%s": %s', $server, $e->getMessage()));
        }
    }
}
