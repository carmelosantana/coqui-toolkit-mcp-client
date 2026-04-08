<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Mcp\Auth\OAuthHandler;
use CoquiBot\Toolkits\Mcp\Config\McpConfig;

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
final class McpToolkit implements ToolkitInterface
{
    private readonly McpConfig $config;
    private readonly McpServerManager $manager;
    private readonly OAuthHandler $oauthHandler;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->config = new McpConfig($this->workspacePath);
        $this->manager = new McpServerManager($this->config, $this->workspacePath);
        $this->oauthHandler = new OAuthHandler($this->workspacePath);
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
            ...$this->manager->getTools(),
        ];
    }

    #[\Override]
    public function guidelines(): string
    {
        $lines = [];
        $lines[] = '<MCP-GUIDELINES>';
        $lines[] = 'MCP (Model Context Protocol) tools are proxied from external MCP servers.';
        $lines[] = '';

        // Connected servers and their tools
        $allStatus = $this->manager->getAllStatus();

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
        $instructions = $this->manager->getServerInstructions();

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
        $lines[] = '- Use `mcp(action: "list")` to see all configured servers and their status.';
        $lines[] = '- Use `mcp(action: "add", ...)` to add new MCP servers, then `restart_coqui` to activate.';
        $lines[] = '- Use `mcp(action: "set_env", ...)` to configure credentials for a server.';
        $lines[] = '- Use `mcp(action: "auth", server: "...", key: "AUTH_URL", value: "TOKEN_URL")` for OAuth browser-based auth.';
        $lines[] = '- After adding or removing servers, call `restart_coqui` so new tools are registered.';
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
                    values: ['list', 'add', 'remove', 'set_env', 'enable', 'disable', 'connect', 'disconnect', 'status', 'tools', 'auth'],
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
            ],
            callback: fn(array $input): ToolResult => $this->executeMcpAction($input),
        );
    }

    private function executeMcpAction(array $input): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));
        $server = trim((string) ($input['server'] ?? ''));

        return match ($action) {
            'list' => $this->actionList(),
            'add' => $this->actionAdd($server, $input),
            'remove' => $this->actionRemove($server),
            'set_env' => $this->actionSetEnv($server, $input),
            'enable' => $this->actionEnable($server),
            'disable' => $this->actionDisable($server),
            'connect' => $this->actionConnect($server),
            'disconnect' => $this->actionDisconnect($server),
            'status' => $this->actionStatus($server),
            'tools' => $this->actionTools($server),
            'auth' => $this->actionAuth($server, $input),
            default => ToolResult::error(sprintf('Unknown MCP action: "%s"', $action)),
        };
    }

    private function actionList(): ToolResult
    {
        $allStatus = $this->manager->getAllStatus();

        if ($allStatus === []) {
            return ToolResult::success(
                "No MCP servers configured.\n\n"
                . "Add one with: mcp(action: \"add\", server: \"server-name\", command: \"npx\", args: \"-y @modelcontextprotocol/server-xxx\")",
            );
        }

        $lines = ['Configured MCP Servers:', ''];

        foreach ($allStatus as $name => $status) {
            $state = $status['disabled'] ? 'DISABLED' : ($status['connected'] ? 'CONNECTED' : 'DISCONNECTED');
            $label = $status['serverName'] ?? $name;
            $lines[] = sprintf('  %s [%s]', $label, $state);
            $lines[] = sprintf('    Name: %s', $name);
            $lines[] = sprintf('    Tools: %d', $status['toolCount']);

            if ($status['error'] !== null) {
                $lines[] = sprintf('    Error: %s', $status['error']);
            }

            $lines[] = '';
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionAdd(string $server, array $input): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required. Usage: mcp(action: "add", server: "name", command: "npx", args: "-y @package/name")');
        }

        $command = trim((string) ($input['command'] ?? ''));

        if ($command === '') {
            return ToolResult::error('Command is required. Usage: mcp(action: "add", server: "' . $server . '", command: "npx", args: "-y @package/name")');
        }

        $argsRaw = trim((string) ($input['args'] ?? ''));
        $args = $argsRaw !== '' ? $this->parseArgs($argsRaw) : [];

        $this->config->load();

        $existing = $this->config->getServer($server);

        if ($existing !== null) {
            return ToolResult::error(sprintf(
                'Server "%s" already exists. Remove it first with mcp(action: "remove", server: "%s") or use a different name.',
                $server,
                $server,
            ));
        }

        $serverConfig = [
            'command' => $command,
            'args' => $args,
        ];

        $this->config->addServer($server, $serverConfig);
        $this->config->save();

        return ToolResult::success(sprintf(
            "MCP server \"%s\" added successfully.\n\n"
            . "Command: %s %s\n\n"
            . "Next steps:\n"
            . "1. If this server needs credentials, set them: mcp(action: \"set_env\", server: \"%s\", key: \"API_KEY_NAME\", value: \"your-key\")\n"
            . "2. Call restart_coqui(reason: \"Activate MCP server %s\") to register the new tools.",
            $server,
            $command,
            implode(' ', $args),
            $server,
            $server,
        ));
    }

    private function actionRemove(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->config->load();

        if (!$this->config->removeServer($server)) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        // Disconnect if running
        $this->manager->disconnectServer($server);

        $this->config->save();

        return ToolResult::success(sprintf(
            "MCP server \"%s\" removed.\n\nCall restart_coqui(reason: \"Remove MCP server %s tools\") to unregister its tools.",
            $server,
            $server,
        ));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function actionSetEnv(string $server, array $input): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $key = trim((string) ($input['key'] ?? ''));
        $value = (string) ($input['value'] ?? '');

        if ($key === '') {
            return ToolResult::error('Key is required. Usage: mcp(action: "set_env", server: "name", key: "API_KEY", value: "your-key")');
        }

        $this->config->load();

        if ($this->config->getServer($server) === null) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        // Store the actual value in environment (available immediately via putenv)
        putenv($key . '=' . $value);

        // Store a ${KEY} reference in the config (never the raw secret)
        $this->config->setServerEnv($server, $key, '${' . $key . '}');
        $this->config->save();

        return ToolResult::success(sprintf(
            "Environment variable \"%s\" set for server \"%s\".\n\n"
            . "The value is available immediately. If the server is connected, use mcp(action: \"connect\", server: \"%s\") to reconnect with the new credentials.\n\n"
            . "IMPORTANT: To persist this credential across restarts, also call:\n"
            . "credentials(action: \"set\", key: \"%s\", value: \"...\")",
            $key,
            $server,
            $server,
            $key,
        ));
    }

    private function actionEnable(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->config->load();

        if (!$this->config->enableServer($server)) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        $this->config->save();

        return ToolResult::success(sprintf(
            "MCP server \"%s\" enabled.\n\nCall restart_coqui(reason: \"Enable MCP server %s\") to connect and register its tools.",
            $server,
            $server,
        ));
    }

    private function actionDisable(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->config->load();

        if (!$this->config->disableServer($server)) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        $this->manager->disconnectServer($server);
        $this->config->save();

        return ToolResult::success(sprintf(
            "MCP server \"%s\" disabled and disconnected.\n\nCall restart_coqui(reason: \"Disable MCP server %s\") to unregister its tools.",
            $server,
            $server,
        ));
    }

    private function actionConnect(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        try {
            $this->manager->connectServer($server);
            $status = $this->manager->getServerStatus($server);
            $toolCount = $status['toolCount'];

            return ToolResult::success(sprintf(
                "MCP server \"%s\" connected successfully.\n"
                . "Server: %s %s\n"
                . "Tools discovered: %d\n\n"
                . "NOTE: To register the newly discovered tools with the agent, call restart_coqui(reason: \"Register MCP tools from %s\").",
                $server,
                $status['serverName'] ?? $server,
                $status['serverVersion'] ?? '',
                $toolCount,
                $server,
            ));
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf(
                'Failed to connect to MCP server "%s": %s',
                $server,
                $e->getMessage(),
            ));
        }
    }

    private function actionDisconnect(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->manager->disconnectServer($server);

        return ToolResult::success(sprintf('MCP server "%s" disconnected.', $server));
    }

    private function actionStatus(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->config->load();

        if ($this->config->getServer($server) === null) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        $status = $this->manager->getServerStatus($server);
        $config = $this->config->getServer($server);

        $lines = [sprintf('MCP Server: %s', $server), ''];
        $lines[] = sprintf('  Status: %s', $status['connected'] ? 'CONNECTED' : 'DISCONNECTED');
        $lines[] = sprintf('  Disabled: %s', $this->config->isDisabled($server) ? 'yes' : 'no');

        if ($status['serverName'] !== null) {
            $lines[] = sprintf('  Server Name: %s', $status['serverName']);
        }

        if ($status['serverVersion'] !== null) {
            $lines[] = sprintf('  Server Version: %s', $status['serverVersion']);
        }

        $lines[] = sprintf('  Tools: %d', $status['toolCount']);

        if ($status['error'] !== null) {
            $lines[] = sprintf('  Error: %s', $status['error']);
        }

        if (isset($config['command'])) {
            $args = is_array($config['args'] ?? null) ? implode(' ', $config['args']) : '';
            $lines[] = sprintf('  Command: %s %s', $config['command'], $args);
        }

        $env = $this->config->getEnv($server);

        if ($env !== []) {
            $lines[] = '  Environment:';

            foreach ($env as $key => $val) {
                $lines[] = sprintf('    %s = %s', $key, $val);
            }
        }

        if ($status['instructions'] !== null) {
            $lines[] = '';
            $lines[] = '  Instructions:';
            $lines[] = '    ' . str_replace("\n", "\n    ", $status['instructions']);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function actionTools(string $server): ToolResult
    {
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $tools = $this->manager->getServerToolDefs($server);

        if ($tools === []) {
            $status = $this->manager->getServerStatus($server);

            if (!$status['connected']) {
                return ToolResult::error(sprintf(
                    'Server "%s" is not connected. Use mcp(action: "connect", server: "%s") first.',
                    $server,
                    $server,
                ));
            }

            return ToolResult::success(sprintf('Server "%s" has no tools.', $server));
        }

        $lines = [sprintf('Tools from MCP server "%s":', $server), ''];

        foreach ($tools as $tool) {
            $namespacedName = 'mcp_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($server)) . '_' . $tool['name'];
            $lines[] = sprintf('  %s', $namespacedName);
            $lines[] = sprintf('    Original: %s', $tool['name']);

            if ($tool['description'] !== '') {
                $lines[] = sprintf('    Description: %s', $tool['description']);
            }

            $props = $tool['inputSchema']['properties'] ?? [];

            if (is_array($props) && $props !== []) {
                $required = $tool['inputSchema']['required'] ?? [];
                $lines[] = '    Parameters:';

                foreach ($props as $pName => $pSchema) {
                    $type = $pSchema['type'] ?? 'any';
                    $req = in_array($pName, is_array($required) ? $required : [], true) ? 'required' : 'optional';
                    $desc = $pSchema['description'] ?? '';
                    $lines[] = sprintf('      - %s (%s, %s)%s', $pName, $type, $req, $desc !== '' ? ': ' . $desc : '');
                }
            }

            $lines[] = '';
        }

        return ToolResult::success(implode("\n", $lines));
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
        if ($server === '') {
            return ToolResult::error('Server name is required.');
        }

        $this->config->load();

        if ($this->config->getServer($server) === null) {
            return ToolResult::error(sprintf('Server "%s" not found.', $server));
        }

        // key = authUrl, value = tokenUrl, command = clientId (optional), args = scopes (optional)
        $authUrl = trim((string) ($input['key'] ?? ''));
        $tokenUrl = trim((string) ($input['value'] ?? ''));
        $clientId = trim((string) ($input['command'] ?? ''));
        $scopesRaw = trim((string) ($input['args'] ?? ''));

        if ($authUrl === '' || $tokenUrl === '') {
            return ToolResult::error(
                'Auth URL and token URL are required for OAuth.'
                . ' Usage: mcp(action: "auth", server: "name", key: "https://auth.example.com/authorize", value: "https://auth.example.com/token")'
                . ' Optionally: command: "client-id", args: "scope1 scope2"',
            );
        }

        $authConfig = [
            'authUrl' => $authUrl,
            'tokenUrl' => $tokenUrl,
        ];

        if ($clientId !== '') {
            $authConfig['clientId'] = $clientId;
        }

        if ($scopesRaw !== '') {
            $authConfig['scopes'] = explode(' ', $scopesRaw);
        }

        try {
            $tokens = $this->oauthHandler->authorize($server, $authConfig);

            // Make the access token available as an env var for the server
            $envKey = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $server) ?? $server) . '_ACCESS_TOKEN';
            putenv($envKey . '=' . $tokens['access_token']);

            // Store reference in server config
            $this->config->setServerEnv($server, $envKey, '${' . $envKey . '}');
            $this->config->save();

            $expiresInfo = isset($tokens['expires_at'])
                ? sprintf(' Expires: %s.', date('Y-m-d H:i:s', $tokens['expires_at']))
                : '';

            return ToolResult::success(sprintf(
                "OAuth authentication successful for server \"%s\".\n\n"
                . "Access token stored as env var \"%s\".%s\n\n"
                . "The token is available immediately. Reconnect the server to use it:\n"
                . "mcp(action: \"connect\", server: \"%s\")",
                $server,
                $envKey,
                $expiresInfo,
                $server,
            ));
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf(
                'OAuth authentication failed for server "%s": %s',
                $server,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Parse a space-separated args string into an array.
     *
     * Handles quoted strings: "foo bar" stays as one argument.
     *
     * @return list<string>
     */
    private function parseArgs(string $raw): array
    {
        $args = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $char = $raw[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($char === ' ') {
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $args[] = $current;
        }

        return $args;
    }
}
