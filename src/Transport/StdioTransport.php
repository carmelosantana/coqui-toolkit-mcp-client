<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Transport;

use CoquiBot\Toolkits\Mcp\Exception\McpConnectionException;
use CoquiBot\Toolkits\Mcp\Exception\McpProtocolException;
use CoquiBot\Toolkits\Mcp\JsonRpc\Message;

/**
 * Stdio transport for MCP servers.
 *
 * Spawns the MCP server as a subprocess via proc_open() and communicates
 * over stdin (write) / stdout (read) using newline-delimited JSON-RPC 2.0.
 * Stderr is captured for diagnostics but not parsed as protocol messages.
 *
 * Unlike the browser toolkit's fire-and-forget pattern, this transport
 * keeps the subprocess alive across multiple requests — the MCP server
 * is a long-running process.
 */
final class StdioTransport implements TransportInterface
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int POLL_INTERVAL_US = 5_000; // 5ms poll interval
    private const int MAX_STDERR_BYTES = 8_192;

    /** @var resource|null */
    private mixed $process = null;

    /** @var resource|null stdin pipe (write) */
    private mixed $stdin = null;

    /** @var resource|null stdout pipe (read) */
    private mixed $stdout = null;

    /** @var resource|null stderr pipe (read) */
    private mixed $stderr = null;

    /** Buffered partial reads from stdout (incomplete JSON lines). */
    private string $readBuffer = '';

    /** Last stderr output captured (for diagnostics). */
    private string $lastStderr = '';

    public function __construct(
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    #[\Override]
    public function start(string $command, array $args = [], array $env = []): void
    {
        if ($this->isConnected()) {
            $this->close();
        }

        $cmdParts = array_map('escapeshellarg', [$command, ...$args]);
        $cmdString = implode(' ', $cmdParts);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin — we write
            1 => ['pipe', 'w'], // stdout — we read
            2 => ['pipe', 'w'], // stderr — diagnostics
        ];

        // Merge current environment with server-specific env vars
        $processEnv = array_merge($this->getParentEnv(), $env);

        $process = proc_open(
            $cmdString,
            $descriptors,
            $pipes,
            null,
            $processEnv,
        );

        if (!is_resource($process)) {
            throw McpConnectionException::processStartFailed($cmdString, 'proc_open returned false');
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        $this->readBuffer = '';

        // Set stdout and stderr to non-blocking for poll-based reads
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        // Verify the process actually started (give it 500ms to crash)
        usleep(100_000);
        $status = proc_get_status($this->process);

        if (!$status['running']) {
            $stderrOutput = $this->drainStderr();
            $this->cleanup();

            throw McpConnectionException::processStartFailed(
                $cmdString,
                $stderrOutput !== '' ? $stderrOutput : 'Process exited immediately with code ' . $status['exitcode'],
            );
        }
    }

    #[\Override]
    public function send(Message $request): Message
    {
        $this->assertConnected();

        // Write the request as a newline-delimited JSON line
        $this->writeLine($request->toJson());

        // Read the response, matching by request ID
        return $this->readResponse($request->id ?? 0);
    }

    #[\Override]
    public function sendNotification(Message $notification): void
    {
        $this->assertConnected();
        $this->writeLine($notification->toJson());
    }

    #[\Override]
    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        // Close stdin first to signal the server to exit
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }

        // Wait a bit for graceful shutdown
        $deadline = time() + 3;

        while (time() < $deadline) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                break;
            }

            usleep(50_000);
        }

        // Force-kill if still running
        $status = proc_get_status($this->process);

        if ($status['running']) {
            $pid = $status['pid'];

            // SIGTERM first
            if (function_exists('posix_kill')) {
                posix_kill($pid, 15);
            } else {
                proc_terminate($this->process, 15);
            }

            usleep(200_000);

            $status = proc_get_status($this->process);

            if ($status['running']) {
                // SIGKILL
                if (function_exists('posix_kill')) {
                    posix_kill($pid, 9);
                } else {
                    proc_terminate($this->process, 9);
                }
            }
        }

        $this->cleanup();
    }

    #[\Override]
    public function isConnected(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    /**
     * Get the last stderr output captured from the server.
     */
    public function lastStderr(): string
    {
        return $this->lastStderr;
    }

    /**
     * Write a JSON line to the server's stdin.
     */
    private function writeLine(string $json): void
    {
        if (!is_resource($this->stdin)) {
            throw McpConnectionException::disconnected('stdio');
        }

        $line = $json . "\n";
        $written = fwrite($this->stdin, $line);

        if ($written === false || $written !== strlen($line)) {
            throw McpConnectionException::disconnected('stdio');
        }

        fflush($this->stdin);
    }

    /**
     * Read the next JSON-RPC response matching the given request ID.
     *
     * Handles out-of-order messages: notifications from the server are
     * silently consumed, responses with non-matching IDs are discarded.
     */
    private function readResponse(int $expectedId): Message
    {
        $deadline = time() + $this->timeout;

        while (time() < $deadline) {
            // Drain stderr periodically for diagnostics
            $this->drainStderr();

            // Check if process is still alive
            if (!$this->isConnected()) {
                throw McpConnectionException::disconnected('stdio');
            }

            // Try to read a complete line from stdout
            $line = $this->readLine();

            if ($line === null) {
                usleep(self::POLL_INTERVAL_US);
                continue;
            }

            // Skip empty lines
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $message = Message::fromJson($line);
            } catch (\InvalidArgumentException) {
                // Skip unparseable output (server may emit debug text)
                continue;
            }

            // Server-initiated notifications — consume silently
            if ($message->isNotification()) {
                continue;
            }

            // Response with matching ID — return it
            if ($message->id === $expectedId) {
                if ($message->isError() && $message->error !== null) {
                    throw McpProtocolException::jsonRpcError(
                        $message->error->code,
                        $message->error->message,
                        $message->error->data,
                    );
                }

                return $message;
            }

            // Response with non-matching ID — discard and keep reading
        }

        throw McpConnectionException::timeout('stdio', $this->timeout);
    }

    /**
     * Read a single complete line from the read buffer + stdout.
     *
     * Returns null if no complete line is available yet.
     */
    private function readLine(): ?string
    {
        // Check buffer first for a complete line
        $newlinePos = strpos($this->readBuffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($this->readBuffer, 0, $newlinePos);
            $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);

            return $line;
        }

        // Read more data from stdout
        if (!is_resource($this->stdout)) {
            return null;
        }

        $chunk = fread($this->stdout, 65_536);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $this->readBuffer .= $chunk;

        // Check again for a complete line
        $newlinePos = strpos($this->readBuffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($this->readBuffer, 0, $newlinePos);
            $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);

            return $line;
        }

        return null;
    }

    /**
     * Drain stderr into lastStderr for diagnostics.
     */
    private function drainStderr(): string
    {
        if (!is_resource($this->stderr)) {
            return '';
        }

        $output = '';
        $chunk = fread($this->stderr, self::MAX_STDERR_BYTES);

        if ($chunk !== false && $chunk !== '') {
            $output = $chunk;
            $this->lastStderr = $output;
        }

        return $output;
    }

    /**
     * Assert that the transport is connected.
     */
    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw McpConnectionException::disconnected('stdio');
        }
    }

    /**
     * Clean up all resources.
     */
    private function cleanup(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
        $this->process = null;
        $this->readBuffer = '';
    }

    /**
     * Get environment variables from the current process.
     *
     * @return array<string, string>
     */
    private function getParentEnv(): array
    {
        return getenv();
    }

    public function __destruct()
    {
        $this->close();
    }
}
