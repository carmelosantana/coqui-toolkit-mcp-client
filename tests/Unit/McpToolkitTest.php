<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Toolkits\Mcp\McpToolkit;

/**
 * Build a fake MCP runtime that mimics core's McpRuntime surface the toolkit
 * uses: registerOAuth() and managementService(). The toolkit repo has no coqui
 * dependency, so we cannot use core's real runtime here — a double is enough
 * because the toolkit only calls these two methods on it.
 *
 * managementService() returns the stub McpManagementService (loaded via
 * autoload-dev.files) so the toolkit's ?McpManagementService property accepts
 * it. The toolkit only invokes service methods when the `mcp` tool executes an
 * action, which these structural tests do not exercise.
 */
function fakeMcpRuntime(): object
{
    return new class {
        public bool $oauthRegistered = false;

        public function registerOAuth(object $oauth): void
        {
            $this->oauthRegistered = true;
        }

        public function managementService(): McpManagementService
        {
            return new McpManagementService();
        }
    };
}

test('mcp toolkit exposes the management tool and command handler when a runtime is present', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $runtime = fakeMcpRuntime();

    $toolkit = McpToolkit::fromCoquiContext([
        'workspacePath' => $path,
        'mcp_runtime' => $runtime,
    ]);

    $tools = $toolkit->tools();
    $handlers = $toolkit->commandHandlers();

    expect($runtime->oauthRegistered)->toBeTrue()
        ->and($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('mcp')
        ->and($handlers)->toHaveCount(1)
        ->and($handlers[0]->commandName())->toBe('mcp')
        ->and($handlers[0]->subcommands())->toContain('list', 'search', 'test', 'connect', 'set-env', 'promote', 'demote', 'auto');

    rmdir($path);
});

test('mcp toolkit no-ops cleanly when no runtime is present', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = McpToolkit::fromCoquiContext([
        'workspacePath' => $path,
    ]);

    expect($toolkit->tools())->toBe([])
        ->and($toolkit->commandHandlers())->toBe([]);

    rmdir($path);
});

test('mcp toolkit constructed from env no-ops until a runtime is supplied', function () {
    $path = sys_get_temp_dir() . '/mcp-toolkit-test-' . uniqid();
    mkdir($path, 0o755, true);

    $toolkit = new McpToolkit($path);

    expect($toolkit->tools())->toBe([])
        ->and($toolkit->commandHandlers())->toBe([]);

    rmdir($path);
});
