<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Support;

/**
 * Shared human-readable formatting for MCP REPL and tool output.
 *
 * @phpstan-import-type McpServerList from \CoquiBot\Toolkits\Mcp\McpManagementService
 * @phpstan-import-type McpServerSnapshot from \CoquiBot\Toolkits\Mcp\McpManagementService
 */
final class McpManagementFormatter
{
    /**
     * @param McpServerList $servers
     */
    public function formatServerList(array $servers): string
    {
        if ($servers === []) {
            return "No MCP servers configured.\n\nAdd one with /mcp add <name> <command> [args...] or mcp(action: \"add\", ...).";
        }

        $lines = ['Configured MCP Servers:', ''];

        foreach ($servers as $server) {
            $state = $server['disabled'] ? 'DISABLED' : ($server['connected'] ? 'CONNECTED' : 'DISCONNECTED');
            $label = $server['serverName'] ?? $server['name'];
            $lines[] = sprintf('  %s [%s]', $label, $state);
            $lines[] = sprintf('    Name: %s', $server['name']);
            $lines[] = sprintf('    Loading: %s', $server['loadingMode']);
            $lines[] = sprintf('    Tools: %d', $server['toolCount']);

            if ($server['error'] !== null) {
                $lines[] = sprintf('    Error: %s', $server['error']);
            }

            $audit = $server['audit'];
            if ($audit['last_tested_at'] !== null) {
                $testStatus = $audit['last_test_succeeded'] === true ? 'ok' : 'failed';
                $lines[] = sprintf('    Last Test: %s (%s ms)', $testStatus, (string) ($audit['last_test_duration_ms'] ?? '?'));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
         * @param McpServerSnapshot $server
     */
    public function formatServerStatus(array $server): string
    {
        $lines = [sprintf('MCP Server: %s', $server['name']), ''];
        $lines[] = sprintf('  Status: %s', $server['connected'] ? 'CONNECTED' : 'DISCONNECTED');
        $lines[] = sprintf('  Disabled: %s', $server['disabled'] ? 'yes' : 'no');
                $lines[] = sprintf('  Loading: %s', $server['loadingMode']);

        if ($server['serverName'] !== null) {
            $lines[] = sprintf('  Server Name: %s', $server['serverName']);
        }

        if ($server['serverVersion'] !== null) {
            $lines[] = sprintf('  Server Version: %s', $server['serverVersion']);
        }

        $lines[] = sprintf('  Tools: %d', $server['toolCount']);

        if ($server['command'] !== null) {
            $lines[] = sprintf('  Command: %s %s', $server['command'], implode(' ', $server['args']));
        }

        if ($server['error'] !== null) {
            $lines[] = sprintf('  Error: %s', $server['error']);
        }

        if ($server['env'] !== []) {
            $lines[] = '  Environment:';
            foreach ($server['env'] as $key => $value) {
                $lines[] = sprintf('    %s = %s', $key, $value);
            }
        }

        $audit = $server['audit'];
        if ($audit['last_connected_at'] !== null || $audit['last_tested_at'] !== null || $audit['last_disconnected_at'] !== null) {
            $lines[] = '  Audit:';

            if ($audit['last_connected_at'] !== null) {
                $lines[] = sprintf('    Last Connected: %s (%s ms)', $audit['last_connected_at'], (string) ($audit['last_connection_duration_ms'] ?? '?'));
            }

            if ($audit['last_connection_error'] !== null) {
                $lines[] = sprintf('    Last Connection Error: %s', $audit['last_connection_error']);
            }

            if ($audit['last_disconnected_at'] !== null) {
                $lines[] = sprintf('    Last Disconnected: %s', $audit['last_disconnected_at']);
            }

            if ($audit['last_tested_at'] !== null) {
                $testStatus = $audit['last_test_succeeded'] === true ? 'ok' : 'failed';
                $lines[] = sprintf('    Last Test: %s at %s (%s ms)', $testStatus, $audit['last_tested_at'], (string) ($audit['last_test_duration_ms'] ?? '?'));
            }

            if ($audit['last_test_error'] !== null) {
                $lines[] = sprintf('    Last Test Error: %s', $audit['last_test_error']);
            }
        }

        if ($server['instructions'] !== null && $server['instructions'] !== '') {
            $lines[] = '';
            $lines[] = '  Instructions:';
            $lines[] = '    ' . str_replace("\n", "\n    ", $server['instructions']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{name: string, namespacedName: string, description: string, inputSchema: array<string, mixed>}> $tools
     */
    public function formatServerTools(string $server, array $tools): string
    {
        if ($tools === []) {
            return sprintf('Server "%s" has no discovered tools.', $server);
        }

        $lines = [sprintf('Tools from MCP server "%s":', $server), ''];

        foreach ($tools as $tool) {
            $lines[] = sprintf('  %s', $tool['namespacedName']);
            $lines[] = sprintf('    Original: %s', $tool['name']);

            if ($tool['description'] !== '') {
                $lines[] = sprintf('    Description: %s', $tool['description']);
            }

            $props = $tool['inputSchema']['properties'] ?? [];
            if (is_array($props) && $props !== []) {
                $required = $tool['inputSchema']['required'] ?? [];
                $lines[] = '    Parameters:';
                foreach ($props as $paramName => $schema) {
                    $type = is_array($schema) ? (string) ($schema['type'] ?? 'any') : 'any';
                    $requiredLabel = in_array($paramName, is_array($required) ? $required : [], true) ? 'required' : 'optional';
                    $description = is_array($schema) ? (string) ($schema['description'] ?? '') : '';
                    $lines[] = sprintf('      - %s (%s, %s)%s', $paramName, $type, $requiredLabel, $description !== '' ? ': ' . $description : '');
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{server: string, name: string, namespacedName: string, description: string}> $results
     */
    public function formatSearchResults(string $query, array $results): string
    {
        if ($results === []) {
            return sprintf('No MCP tools matched "%s".', $query);
        }

        $lines = [sprintf('MCP tools matching "%s":', $query), ''];

        foreach ($results as $result) {
            $lines[] = sprintf('  %s', $result['namespacedName']);
            $lines[] = sprintf('    Server: %s', $result['server']);
            if ($result['description'] !== '') {
                $lines[] = sprintf('    Description: %s', $result['description']);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}