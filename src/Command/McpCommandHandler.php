<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Command;

use CoquiBot\Coqui\Contract\ToolkitCommandExample;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitCommandHelp;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpEntry;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpProvider;
use CoquiBot\Coqui\Contract\ToolkitReplContext;
use CoquiBot\Coqui\Contract\ToolkitTabCompletionProvider;
use CoquiBot\Toolkits\Mcp\McpManagementService;
use CoquiBot\Toolkits\Mcp\Support\McpManagementFormatter;

/**
 * First-class REPL command handler for MCP server management.
 */
final class McpCommandHandler implements ToolkitCommandHandler, ToolkitCommandHelpProvider, ToolkitTabCompletionProvider
{
    /**
     * @var list<string>
     */
    private const array ACTIONS = [
        'list',
        'status',
        'tools',
        'search',
        'test',
        'connect',
        'disconnect',
        'refresh',
        'add',
        'update',
        'remove',
        'enable',
        'disable',
        'promote',
        'demote',
        'auto',
        'set-env',
        'auth',
    ];

    public function __construct(
        private readonly McpManagementService $service,
        private readonly McpManagementFormatter $formatter,
    ) {}

    public function commandName(): string
    {
        return 'mcp';
    }

    /**
     * @return list<string>
     */
    public function subcommands(): array
    {
        return self::ACTIONS;
    }

    public function usage(): string
    {
        return '/mcp [action]';
    }

    public function description(): string
    {
        return 'Manage MCP servers, inspect discovered tools, and test MCP connectivity.';
    }

    public function help(): ToolkitCommandHelp
    {
        return new ToolkitCommandHelp(
            title: 'MCP Server Management',
            summary: 'Add, update, connect, test, and inspect configured MCP servers from the Coqui REPL.',
            subcommands: [
                new ToolkitCommandHelpEntry('list', '/mcp list', 'List configured MCP servers and their live status.'),
                new ToolkitCommandHelpEntry('status', '/mcp status <server>', 'Show detailed status, env links, and live audit fields for one server.'),
                new ToolkitCommandHelpEntry('tools', '/mcp tools <server>', 'List discovered MCP tools for one server.'),
                new ToolkitCommandHelpEntry('search', '/mcp search <query> [server]', 'Search discovered MCP tool names and descriptions.'),
                new ToolkitCommandHelpEntry('test', '/mcp test <server>', 'Reconnect and verify tool discovery for one server.'),
                new ToolkitCommandHelpEntry('connect', '/mcp connect <server>', 'Connect a configured server immediately.'),
                new ToolkitCommandHelpEntry('disconnect', '/mcp disconnect <server>', 'Disconnect a configured server immediately.'),
                new ToolkitCommandHelpEntry('refresh', '/mcp refresh <server>', 'Reconnect and refresh tool discovery for a server.'),
                new ToolkitCommandHelpEntry('add', '/mcp add <server> <command> [args...]', 'Add a new MCP server configuration.'),
                new ToolkitCommandHelpEntry('update', '/mcp update <server> <command> [args...]', 'Update an existing MCP server command or args.'),
                new ToolkitCommandHelpEntry('remove', '/mcp remove <server>', 'Remove a configured MCP server.'),
                new ToolkitCommandHelpEntry('enable', '/mcp enable <server>', 'Enable a disabled server.'),
                new ToolkitCommandHelpEntry('disable', '/mcp disable <server>', 'Disable and disconnect a server.'),
                new ToolkitCommandHelpEntry('promote', '/mcp promote <server>', 'Force one MCP server toolkit to load eagerly on future turns.'),
                new ToolkitCommandHelpEntry('demote', '/mcp demote <server>', 'Force one MCP server toolkit to stay deferred on future turns.'),
                new ToolkitCommandHelpEntry('auto', '/mcp auto <server>', 'Return one MCP server toolkit to automatic budget-gated loading.'),
                new ToolkitCommandHelpEntry('set-env', '/mcp set-env <server> <ENV_KEY>', 'Prompt for a secret value and store it as an env link for the server.'),
                new ToolkitCommandHelpEntry('auth', '/mcp auth <server> <auth-url> <token-url> [client-id] [scopes...]', 'Run browser-based OAuth and link the resulting access token.'),
            ],
            examples: [
                new ToolkitCommandExample('/mcp add github npx -y @modelcontextprotocol/server-github', 'Add the GitHub MCP server.'),
                new ToolkitCommandExample('/mcp set-env github GITHUB_TOKEN', 'Prompt for a token and link it to the GitHub server.'),
                new ToolkitCommandExample('/mcp test github', 'Reconnect and verify that GitHub tools can be discovered.'),
            ],
            notes: [
                'Server config changes are re-read on future turns, but an already-running REPL or API process may still need /mcp connect, /mcp refresh, or /restart for an immediate runtime rebuild.',
                'Tool discovery remains namespaced as mcp_{server}_{tool} so per-tool visibility can reuse Coqui\'s existing tool controls.',
                'Use promote, demote, and auto to control whether a specific MCP server toolkit loads eagerly or stays deferred under the toolkit token budget.',
            ],
        );
    }

    public function handle(ToolkitReplContext $context, string $arg): void
    {
        $tokens = $this->service->parseArgs(trim($arg));
        $action = strtolower($tokens[0] ?? 'list');

        try {
            match ($action) {
                'list' => $context->io->writeln($this->formatter->formatServerList($this->service->listServers())),
                'status' => $context->io->writeln($this->formatter->formatServerStatus($this->service->getServerSnapshot($this->requireToken($tokens, 1, 'Server name is required.')))),
                'tools' => $context->io->writeln($this->formatter->formatServerTools($this->requireToken($tokens, 1, 'Server name is required.'), $this->service->getServerTools($this->requireToken($tokens, 1, 'Server name is required.')))),
                'search' => $this->handleSearch($context, $tokens),
                'test' => $this->handleTest($context, $tokens),
                'connect' => $this->handleConnect($context, $tokens),
                'disconnect' => $this->handleDisconnect($context, $tokens),
                'refresh' => $this->handleRefresh($context, $tokens),
                'add' => $this->handleAdd($context, $tokens),
                'update' => $this->handleUpdate($context, $tokens),
                'remove' => $this->handleRemove($context, $tokens),
                'enable' => $this->handleEnable($context, $tokens),
                'disable' => $this->handleDisable($context, $tokens),
                'promote' => $this->handlePromote($context, $tokens),
                'demote' => $this->handleDemote($context, $tokens),
                'auto' => $this->handleAuto($context, $tokens),
                'set-env' => $this->handleSetEnv($context, $tokens),
                'auth' => $this->handleAuth($context, $tokens),
                default => $context->io->error(sprintf('Unknown /mcp subcommand: %s. Use /mcp help.', $action)),
            };
        } catch (\Throwable $e) {
            $context->io->error($e->getMessage());
        }
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    public function completeArguments(string $commandName, array $parts): array
    {
        if ($parts === []) {
            return self::ACTIONS;
        }

        $action = strtolower($parts[0]);
        if (count($parts) === 1) {
            return self::ACTIONS;
        }

        if (in_array($action, ['status', 'tools', 'test', 'connect', 'disconnect', 'refresh', 'update', 'remove', 'enable', 'disable', 'promote', 'demote', 'auto', 'set-env', 'auth', 'search'], true)) {
            return $this->service->configuredServerNames();
        }

        return [];
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSearch(ToolkitReplContext $context, array $tokens): void
    {
        $query = $this->requireToken($tokens, 1, 'Search query is required.');
        $server = $tokens[2] ?? null;
        $context->io->writeln($this->formatter->formatSearchResults($query, $this->service->searchTools($query, $server)));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleTest(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->testServer($server);
        $context->io->success(sprintf('MCP server "%s" test succeeded in %d ms.', $server, $result['duration_ms']));
        $context->io->writeln($this->formatter->formatServerStatus($result['snapshot']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleConnect(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->connectServer($server);
        $context->io->success(sprintf('MCP server "%s" connected in %d ms.', $server, $result['duration_ms']));
        $context->io->writeln($this->formatter->formatServerStatus($result['snapshot']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleDisconnect(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $this->service->disconnectServer($server);
        $context->io->success(sprintf('MCP server "%s" disconnected.', $server));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleRefresh(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->refreshServer($server);
        $context->io->success(sprintf('MCP server "%s" refreshed in %d ms.', $server, $result['duration_ms']));
        $context->io->writeln($this->formatter->formatServerStatus($result['snapshot']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleAdd(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $command = $this->requireToken($tokens, 2, 'Command is required.');
        $args = array_slice($tokens, 3);
        $result = $this->service->addServer($server, $command, $args);
        $context->io->success(sprintf('MCP server "%s" added. Saved for future turns.', $result['name']));
        $context->io->note($this->runtimeRefreshNotice($result['name']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleUpdate(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $command = $tokens[2] ?? null;
        $args = count($tokens) > 3 ? array_slice($tokens, 3) : null;
        $result = $this->service->updateServer($server, $command, $args);
        $context->io->success(sprintf('MCP server "%s" updated (%s).', $result['name'], $result['applied']));
        $context->io->note($this->runtimeRefreshNotice($result['name']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleRemove(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $this->service->removeServer($server);
        $context->io->success(sprintf('MCP server "%s" removed.', $server));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleEnable(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->enableServer($server);
        $context->io->success(sprintf('MCP server "%s" enabled.', $server));
        $context->io->note($this->runtimeRefreshNotice($result['name']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleDisable(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $this->service->disableServer($server);
        $context->io->success(sprintf('MCP server "%s" disabled.', $server));
    }

    /**
     * @param list<string> $tokens
     */
    private function handlePromote(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->promoteServer($server);
        $context->io->success(sprintf('MCP server "%s" loading mode set to %s (%s).', $server, $result['loading_mode'], $result['applied']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleDemote(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->demoteServer($server);
        $context->io->success(sprintf('MCP server "%s" loading mode set to %s (%s).', $server, $result['loading_mode'], $result['applied']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleAuto(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $result = $this->service->autoServer($server);
        $context->io->success(sprintf('MCP server "%s" loading mode set to %s (%s).', $server, $result['loading_mode'], $result['applied']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSetEnv(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $key = $this->requireToken($tokens, 2, 'Environment variable key is required.');
        $value = $context->prompt->askHidden(sprintf('Value for %s', $key));
        if ($value === null || $value === '') {
            throw new \RuntimeException(sprintf('No value provided for %s.', $key));
        }

        $result = $this->service->setServerSecret($server, $key, $value);
        $context->io->success(sprintf('Linked %s to server "%s" (%s).', $result['key'], $server, $result['applied']));
        $context->io->note($this->runtimeRefreshNotice($result['name']));
    }

    /**
     * @param list<string> $tokens
     */
    private function handleAuth(ToolkitReplContext $context, array $tokens): void
    {
        $server = $this->requireToken($tokens, 1, 'Server name is required.');
        $authUrl = $this->requireToken($tokens, 2, 'Auth URL is required.');
        $tokenUrl = $this->requireToken($tokens, 3, 'Token URL is required.');
        $clientId = $tokens[4] ?? '';
        $scopes = count($tokens) > 5 ? array_slice($tokens, 5) : [];
        $result = $this->service->authorizeServer($server, $authUrl, $tokenUrl, $clientId, $scopes);

        $suffix = $result['expires_at'] !== null
            ? sprintf(' Expires: %s.', gmdate('Y-m-d H:i:s', $result['expires_at']))
            : '';
        $context->io->success(sprintf('OAuth complete for "%s". Token linked as %s.%s', $server, $result['env_key'], $suffix));
        $context->io->note($this->runtimeRefreshNotice($server));
    }

    /**
     * @param list<string> $tokens
     */
    private function requireToken(array $tokens, int $index, string $message): string
    {
        $value = trim((string) ($tokens[$index] ?? ''));

        if ($value === '') {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private function runtimeRefreshNotice(string $server): string
    {
        return sprintf(
            'If the current REPL or API process needs "%s" immediately, run /mcp connect %s, /mcp refresh %s, or /restart.',
            $server,
            $server,
            $server,
        );
    }
}