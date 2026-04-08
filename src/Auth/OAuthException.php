<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Auth;

/**
 * OAuth-related errors for MCP authentication.
 */
final class OAuthException extends \RuntimeException
{
    public static function configError(string $message): self
    {
        return new self('OAuth config error: ' . $message);
    }

    public static function authorizationFailed(string $error, string $description = ''): self
    {
        $message = 'OAuth authorization failed: ' . $error;

        if ($description !== '') {
            $message .= ' — ' . $description;
        }

        return new self($message);
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('OAuth token exchange failed: ' . $reason);
    }
}
