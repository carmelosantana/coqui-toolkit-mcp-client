<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Mcp\McpToolkit;

test('mcp toolkit exposes a repl command handler', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);
    $handlers = $toolkit->commandHandlers();

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0]->commandName())->toBe('mcp')
        ->and($handlers[0]->subcommands())->toContain('list', 'search', 'test', 'connect', 'set-env');

    rmdir($path);
});

test('mcp toolkit exposes management tool plus discovered tool list', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('mcp');

    rmdir($path);
});