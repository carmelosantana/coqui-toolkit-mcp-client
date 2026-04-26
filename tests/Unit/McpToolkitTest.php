<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\Mcp\McpToolkit;

test('mcp toolkit exposes a repl command handler', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);
    $handlers = $toolkit->commandHandlers();

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0]->commandName())->toBe('mcp')
        ->and($handlers[0]->subcommands())->toContain('list', 'search', 'test', 'connect', 'set-env', 'promote', 'demote', 'auto');

    rmdir($path);
});

test('mcp toolkit exposes only the management tool from the root toolkit', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('mcp');

    rmdir($path);
});

test('mcp toolkit exposes no child toolkits when no servers are connected', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);

    expect($toolkit->childToolkits())->toBe([]);

    rmdir($path);
});

test('mcp toolkit fromCoquiContext applies MCP stdio policy to management actions', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new class {
        public function get(string $key): mixed
        {
            return match ($key) {
                'agents.defaults.mcp.allowedStdioCommands' => [['npx', '-y', '@modelcontextprotocol/server-github']],
                'agents.defaults.mcp.deniedStdioCommands' => [],
                default => null,
            };
        }
    };

    $toolkit = McpToolkit::fromCoquiContext([
        'workspacePath' => $path,
        'config' => $config,
    ]);

    $result = $toolkit->tools()[0]->execute([
        'action' => 'add',
        'server' => 'fetch',
        'command' => 'uvx',
        'args' => 'mcp-server-fetch',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('allowed policy');

    rmdir($path);
});