<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\JsonRpc;

/**
 * Auto-incrementing ID generator for JSON-RPC requests.
 */
final class IdGenerator
{
    private int $next = 1;

    public function next(): int
    {
        return $this->next++;
    }
}
