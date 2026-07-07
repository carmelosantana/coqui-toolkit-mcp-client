<?php

declare(strict_types=1);

/**
 * PHPStan/runtime stubs for the Coqui core MCP contracts this toolkit consumes.
 *
 * The MCP engine + runtime service live in Coqui core
 * (CoquiBot\Coqui\Mcp\*, CoquiBot\Coqui\Contract\McpOAuthInterface). This
 * toolkit has NO composer dependency on coqui; it references those core types
 * only via these stubs for static analysis and via runtime objects passed in
 * $context['mcp_runtime']. Guarded so the real core definitions win at runtime.
 */

namespace CoquiBot\Coqui\Contract {
    if (!interface_exists(McpOAuthInterface::class)) {
        interface McpOAuthInterface
        {
            /**
             * @param array<string, mixed> $authConfig
             * @return array{access_token: string, refresh_token?: string, expires_at?: int}
             */
            public function authorize(string $serverName, array $authConfig): array;

            /**
             * @param array<string, mixed> $authConfig
             */
            public function getAccessToken(string $serverName, array $authConfig): ?string;

            public function hasTokens(string $serverName): bool;

            public function clearTokens(string $serverName): void;
        }
    }
}

namespace CoquiBot\Coqui\Mcp {
    use CoquiBot\Coqui\Contract\McpOAuthInterface;

    if (!class_exists(McpRuntime::class)) {
        final class McpRuntime
        {
            public function managementService(): McpManagementService
            {
                throw new \LogicException('stub');
            }

            public function registerOAuth(McpOAuthInterface $oauth): void {}
        }
    }

    /**
     * Subset of core's McpManagementService — only the methods the `mcp`
     * management tool and the `/mcp` REPL handler invoke. Signatures mirror
     * core's real class.
     *
     * @phpstan-type McpAudit array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}
     * @phpstan-type McpServerSnapshot array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: McpAudit}
     * @phpstan-type McpServerList array<string, McpServerSnapshot>
     */
    if (!class_exists(McpManagementService::class)) {
        final class McpManagementService
        {
            /**
             * @return array<string, array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}}>
             */
            public function listServers(): array
            {
                return [];
            }

            /**
             * @return list<string>
             */
            public function configuredServerNames(): array
            {
                return [];
            }

            /**
             * @return array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}}
             */
            public function getServerSnapshot(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @param list<string> $args
             * @return array{name: string, command: string, args: list<string>, applied: string}
             */
            public function addServer(string $name, string $command, array $args = [], ?string $description = null): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @param list<string>|null $args
             * @return array{name: string, command: string, args: list<string>, applied: string}
             */
            public function updateServer(
                string $name,
                ?string $command = null,
                ?array $args = null,
                ?string $newName = null,
                bool $descriptionProvided = false,
                ?string $description = null,
            ): array {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, applied: string}
             */
            public function removeServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, key: string, placeholder: string, applied: string}
             */
            public function setServerSecret(string $name, string $key, string $value): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, disabled: bool, applied: string}
             */
            public function enableServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, disabled: bool, applied: string}
             */
            public function disableServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, loading_mode: string, applied: string}
             */
            public function promoteServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, loading_mode: string, applied: string}
             */
            public function demoteServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, loading_mode: string, applied: string}
             */
            public function autoServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, duration_ms: int, snapshot: array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}}}
             */
            public function connectServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, applied: string}
             */
            public function disconnectServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, duration_ms: int, snapshot: array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}}}
             */
            public function refreshServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return array{name: string, ok: bool, duration_ms: int, snapshot: array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}}}
             */
            public function testServer(string $name): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return list<array{name: string, namespacedName: string, description: string, inputSchema: array<string, mixed>}>
             */
            public function getServerTools(string $name): array
            {
                return [];
            }

            /**
             * @return list<array{server: string, name: string, namespacedName: string, description: string}>
             */
            public function searchTools(string $query, ?string $server = null): array
            {
                return [];
            }

            /**
             * @param list<string> $scopes
             * @return array{server: string, env_key: string, expires_at: ?int, applied: string}
             */
            public function authorizeServer(string $server, string $authUrl, string $tokenUrl, string $clientId = '', array $scopes = []): array
            {
                throw new \LogicException('stub');
            }

            /**
             * @return list<string>
             */
            public function parseArgs(string $raw): array
            {
                return [];
            }
        }
    }
}
