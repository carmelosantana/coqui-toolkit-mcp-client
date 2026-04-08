<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Exception;

/**
 * Connection to an MCP server failed — process didn't start, crashed, or timed out.
 */
final class McpConnectionException extends \RuntimeException
{
    public static function processStartFailed(string $command, string $reason = ''): self
    {
        $message = sprintf('Failed to start MCP server process: %s', $command);

        if ($reason !== '') {
            $message .= ' — ' . $reason;
        }

        return new self($message);
    }

    public static function initializeFailed(string $server, string $reason): self
    {
        return new self(sprintf('MCP server "%s" initialization failed: %s', $server, $reason));
    }

    public static function disconnected(string $server): self
    {
        return new self(sprintf('MCP server "%s" is not connected', $server));
    }

    public static function timeout(string $server, int $seconds): self
    {
        return new self(sprintf('MCP server "%s" did not respond within %d seconds', $server, $seconds));
    }
}
