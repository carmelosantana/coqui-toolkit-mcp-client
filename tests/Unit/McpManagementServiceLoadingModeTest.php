<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Mcp\Auth\OAuthHandler;
use CoquiBot\Toolkits\Mcp\Config\McpConfig;
use CoquiBot\Toolkits\Mcp\McpManagementService;
use CoquiBot\Toolkits\Mcp\McpServerManager;
use CoquiBot\Toolkits\Mcp\Support\ServerLoadingModeStore;

test('mcp management service persists per-server loading mode overrides', function () {
    $path = sys_get_temp_dir() . '/mcp-loading-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->addServer('github', [
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
    ]);
    $config->save();

    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        new OAuthHandler($path),
        new ServerLoadingModeStore($path),
    );

    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('auto');

    $service->promoteServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('eager');

    $service->demoteServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('deferred');

    $service->autoServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('auto');

    unlink($path . '/mcp.json');
    unlink($path . '/toolkit-loading.json');
    rmdir($path);
});

test('mcp management service can rename servers and preserve description plus loading mode', function () {
    $path = sys_get_temp_dir() . '/mcp-loading-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->addServer('github', [
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
        'description' => 'GitHub integration',
    ]);
    $config->save();

    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        new OAuthHandler($path),
        new ServerLoadingModeStore($path),
    );

    $service->promoteServer('github');
    $result = $service->updateServer(
        'github',
        newName: 'github-prod',
        descriptionProvided: true,
        description: 'Primary GitHub integration',
    );

    expect($result['name'])->toBe('github-prod')
        ->and($result['applied'])->toBe('next_turn')
        ->and($service->getServerSnapshot('github-prod')['description'])->toBe('Primary GitHub integration')
        ->and($service->getServerSnapshot('github-prod')['loadingMode'])->toBe('eager')
        ->and(fn() => $service->getServerSnapshot('github'))->toThrow(RuntimeException::class);

    unlink($path . '/mcp.json');
    unlink($path . '/toolkit-loading.json');
    rmdir($path);
});