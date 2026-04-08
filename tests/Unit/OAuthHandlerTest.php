<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Mcp\Auth\OAuthException;
use CoquiBot\Toolkits\Mcp\Auth\OAuthHandler;

// -- OAuthException --

test('configError creates exception', function () {
    $e = OAuthException::configError('authUrl is required');

    expect($e)->toBeInstanceOf(OAuthException::class)
        ->and($e->getMessage())->toContain('authUrl is required');
});

test('authorizationFailed creates exception with error and description', function () {
    $e = OAuthException::authorizationFailed('access_denied', 'User denied access');

    expect($e->getMessage())->toContain('access_denied')
        ->and($e->getMessage())->toContain('User denied access');
});

test('authorizationFailed creates exception without description', function () {
    $e = OAuthException::authorizationFailed('timeout');

    expect($e->getMessage())->toContain('timeout');
});

test('tokenExchangeFailed creates exception', function () {
    $e = OAuthException::tokenExchangeFailed('Token endpoint returned 500');

    expect($e->getMessage())->toContain('Token endpoint returned 500');
});

// -- OAuthHandler --

test('authorize throws on missing authUrl', function () {
    $path = sys_get_temp_dir() . '/mcp-oauth-test-' . uniqid();
    mkdir($path, 0o755, true);

    $handler = new OAuthHandler($path);

    $handler->authorize('test', ['tokenUrl' => 'https://example.com/token']);

    rmdir($path);
})->throws(OAuthException::class, 'authUrl and tokenUrl are required');

test('authorize throws on missing tokenUrl', function () {
    $path = sys_get_temp_dir() . '/mcp-oauth-test-' . uniqid();
    mkdir($path, 0o755, true);

    $handler = new OAuthHandler($path);

    $handler->authorize('test', ['authUrl' => 'https://example.com/auth']);

    rmdir($path);
})->throws(OAuthException::class, 'authUrl and tokenUrl are required');

test('hasTokens returns false when no tokens stored', function () {
    $path = sys_get_temp_dir() . '/mcp-oauth-test-' . uniqid();
    mkdir($path, 0o755, true);

    $handler = new OAuthHandler($path);

    expect($handler->hasTokens('nonexistent'))->toBeFalse();

    rmdir($path);
});

test('clearTokens does not throw when no tokens exist', function () {
    $path = sys_get_temp_dir() . '/mcp-oauth-test-' . uniqid();
    mkdir($path, 0o755, true);

    $handler = new OAuthHandler($path);
    $handler->clearTokens('nonexistent');

    expect(true)->toBeTrue(); // No exception thrown

    rmdir($path);
});

test('getAccessToken returns null when no tokens stored', function () {
    $path = sys_get_temp_dir() . '/mcp-oauth-test-' . uniqid();
    mkdir($path, 0o755, true);

    $handler = new OAuthHandler($path);

    $token = $handler->getAccessToken('test', [
        'tokenUrl' => 'https://example.com/token',
    ]);

    expect($token)->toBeNull();

    rmdir($path);
});
