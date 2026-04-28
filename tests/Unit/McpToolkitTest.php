<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\Mcp\McpToolkit;
use CoquiBot\Toolkits\Mcp\McpServerToolkit;

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

test('mcp toolkit re-syncs child toolkits after config changes', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $scriptPath = $path . '/fixture-server.php';
    file_put_contents($scriptPath, <<<'PHP'
<?php
declare(strict_types=1);

function readFrame(): ?array
{
    $line = fgets(STDIN);

    if ($line === false) {
        return null;
    }

    $trimmed = rtrim($line, "\r\n");

    if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
        return json_decode($trimmed, true);
    }

    $headers = [];

    while (true) {
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === '') {
            break;
        }

        [$key, $value] = array_map('trim', explode(':', $trimmed, 2));
        $headers[strtolower($key)] = $value;
        $line = fgets(STDIN);

        if ($line === false) {
            return null;
        }
    }

    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread(STDIN, $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $body .= $chunk;
    }

    return json_decode($body, true);
}

function writeFrame(array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
    fflush(STDOUT);
}

while (($message = readFrame()) !== null) {
    $id = $message['id'] ?? null;
    $method = $message['method'] ?? null;

    if ($method === 'initialize' && $id !== null) {
        writeFrame([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'serverInfo' => [
                    'name' => 'Fixture MCP',
                    'version' => '1.0.0',
                ],
            ],
        ]);
        continue;
    }

    if ($method === 'tools/list' && $id !== null) {
        writeFrame([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'echo',
                        'description' => 'Echo test tool',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
            ],
        ]);
        continue;
    }
}
PHP);

    $toolkit = new McpToolkit($path);

    expect($toolkit->childToolkits())->toBe([]);

    file_put_contents($path . '/mcp.json', json_encode([
        'mcpServers' => [
            'fixture' => [
                'command' => PHP_BINARY,
                'args' => [$scriptPath],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $children = $toolkit->childToolkits();

    expect($children)->toHaveCount(1)
        ->and($children[0])->toBeInstanceOf(McpServerToolkit::class)
        ->and($children[0]->toolkitLoadingKey())->toBe('McpServer:fixture')
        ->and($children[0]->tools())->toHaveCount(1)
        ->and($children[0]->tools()[0]->name())->toBe('mcp_fixture_echo');

    unlink($scriptPath);
    unlink($path . '/mcp.json');
    rmdir($path);
});