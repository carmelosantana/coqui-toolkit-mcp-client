<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Mcp\Auth;

/**
 * Handles OAuth 2.1 browser-based authentication for MCP servers.
 *
 * Flow:
 * 1. Starts a temporary local HTTP server as the redirect URI
 * 2. Opens the authorization URL in the user's browser
 * 3. Waits for the callback with the authorization code
 * 4. Exchanges the code for tokens (with PKCE)
 * 5. Stores tokens in .workspace/.mcp-tokens/{servername}.json
 * 6. Returns the access token for use as an env var
 *
 * Also handles token refresh when tokens expire.
 */
final class OAuthHandler
{
    private const string TOKENS_DIR = '.mcp-tokens';
    private const int CALLBACK_TIMEOUT = 120; // 2 minutes to complete auth
    private const int CALLBACK_PORT_MIN = 49152;
    private const int CALLBACK_PORT_MAX = 65535;

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    /**
     * Perform the full OAuth 2.1 authorization flow.
     *
     * Opens the user's browser to the auth URL, waits for the callback,
     * exchanges the code for tokens, and stores them.
     *
     * @param array{authUrl: string, tokenUrl: string, clientId?: string, scopes?: list<string>} $authConfig
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     *
     * @throws OAuthException If the flow fails at any step
     */
    public function authorize(string $serverName, array $authConfig): array
    {
        $authUrl = $authConfig['authUrl'] ?? '';
        $tokenUrl = $authConfig['tokenUrl'] ?? '';
        $clientId = $authConfig['clientId'] ?? $serverName;
        $scopes = $authConfig['scopes'] ?? [];

        if ($authUrl === '' || $tokenUrl === '') {
            throw OAuthException::configError('authUrl and tokenUrl are required in auth config');
        }

        // Generate PKCE challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Find an available port and start a local callback server
        $port = $this->findAvailablePort();
        $redirectUri = sprintf('http://127.0.0.1:%d/callback', $port);

        // Build authorization URL
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => bin2hex(random_bytes(16)),
        ];

        if ($scopes !== []) {
            $params['scope'] = implode(' ', $scopes);
        }

        $fullAuthUrl = $authUrl . '?' . http_build_query($params);

        // Open browser
        $this->openBrowser($fullAuthUrl);

        // Wait for callback
        $callbackData = $this->waitForCallback($port, $params['state']);

        if (isset($callbackData['error'])) {
            throw OAuthException::authorizationFailed(
                $callbackData['error'],
                $callbackData['error_description'] ?? '',
            );
        }

        $authCode = $callbackData['code'] ?? '';

        if ($authCode === '') {
            throw OAuthException::authorizationFailed('no_code', 'No authorization code received');
        }

        // Exchange code for tokens
        $tokens = $this->exchangeCode($tokenUrl, $authCode, $redirectUri, $clientId, $codeVerifier);

        // Store tokens
        $this->storeTokens($serverName, $tokens);

        return $tokens;
    }

    /**
     * Get a valid access token for a server, refreshing if expired.
     *
     * @param array{tokenUrl: string, clientId?: string} $authConfig
     *
     * @return string|null Access token or null if no tokens stored
     */
    public function getAccessToken(string $serverName, array $authConfig): ?string
    {
        $tokens = $this->loadTokens($serverName);

        if ($tokens === null) {
            return null;
        }

        // Check if token is expired (with 60s buffer)
        $expiresAt = $tokens['expires_at'] ?? 0;

        if ($expiresAt > 0 && $expiresAt < time() + 60) {
            $refreshToken = $tokens['refresh_token'] ?? null;

            if ($refreshToken === null) {
                // Token expired and no refresh token — re-auth needed
                return null;
            }

            $tokenUrl = $authConfig['tokenUrl'] ?? '';
            $clientId = $authConfig['clientId'] ?? $serverName;

            try {
                $newTokens = $this->refreshToken($tokenUrl, $refreshToken, $clientId);
                $this->storeTokens($serverName, $newTokens);

                return $newTokens['access_token'];
            } catch (\Throwable) {
                return null;
            }
        }

        return $tokens['access_token'] ?? null;
    }

    /**
     * Check if stored tokens exist for a server.
     */
    public function hasTokens(string $serverName): bool
    {
        return $this->loadTokens($serverName) !== null;
    }

    /**
     * Delete stored tokens for a server.
     */
    public function clearTokens(string $serverName): void
    {
        $path = $this->tokensPath($serverName);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Generate a PKCE code verifier (43-128 characters, URL-safe base64).
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate a PKCE code challenge from a verifier (S256 method).
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Find an available port for the callback server.
     */
    private function findAvailablePort(): int
    {
        for ($i = 0; $i < 100; $i++) {
            $port = random_int(self::CALLBACK_PORT_MIN, self::CALLBACK_PORT_MAX);
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw OAuthException::configError('Could not find an available port for OAuth callback');
    }

    /**
     * Open a URL in the user's default browser.
     */
    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Linux' => 'xdg-open',
            'Darwin' => 'open',
            'Windows' => 'start',
            default => null,
        };

        if ($command === null) {
            // Can't open browser — provide the URL for manual opening
            return;
        }

        $escapedUrl = escapeshellarg($url);
        exec("{$command} {$escapedUrl} > /dev/null 2>&1 &");
    }

    /**
     * Start a temporary HTTP server and wait for the OAuth callback.
     *
     * @return array<string, string> Query parameters from the callback
     */
    private function waitForCallback(int $port, string $expectedState): array
    {
        $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            throw OAuthException::configError("Could not start callback server on port {$port}: {$errstr}");
        }

        stream_set_timeout($server, self::CALLBACK_TIMEOUT);

        $result = [];

        try {
            $client = @stream_socket_accept($server, self::CALLBACK_TIMEOUT);

            if ($client === false) {
                throw OAuthException::authorizationFailed('timeout', 'No callback received within timeout');
            }

            $request = '';

            while (($line = fgets($client)) !== false) {
                $request .= $line;

                if (trim($line) === '') {
                    break;
                }
            }

            // Parse the GET request to extract query parameters
            if (preg_match('/GET\s+\/callback\?([^\s]+)/', $request, $matches)) {
                parse_str($matches[1], $queryParams);
                /** @var array<string, string> $queryParams */
                $result = $queryParams;
            }

            // Validate state
            $receivedState = $result['state'] ?? '';

            if ($receivedState !== $expectedState) {
                throw OAuthException::authorizationFailed('state_mismatch', 'OAuth state parameter does not match');
            }

            // Send a success response to the browser
            $body = '<!DOCTYPE html><html><body><h1>Authorization Complete</h1><p>You can close this tab and return to Coqui.</p><script>window.close();</script></body></html>';
            $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            fwrite($client, $response);
            fclose($client);
        } finally {
            fclose($server);
        }

        return $result;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function exchangeCode(
        string $tokenUrl,
        string $code,
        string $redirectUri,
        string $clientId,
        string $codeVerifier,
    ): array {
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
        ];

        return $this->tokenRequest($tokenUrl, $postData);
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function refreshToken(string $tokenUrl, string $refreshToken, string $clientId): array
    {
        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ];

        return $this->tokenRequest($tokenUrl, $postData);
    }

    /**
     * Make a token endpoint request.
     *
     * @param array<string, string> $postData
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function tokenRequest(string $tokenUrl, array $postData): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($postData),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($tokenUrl, false, $context);

        if ($response === false) {
            throw OAuthException::tokenExchangeFailed('Token endpoint request failed');
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw OAuthException::tokenExchangeFailed('Invalid token response');
        }

        $result = [
            'access_token' => (string) $data['access_token'],
        ];

        if (isset($data['refresh_token'])) {
            $result['refresh_token'] = (string) $data['refresh_token'];
        }

        if (isset($data['expires_in'])) {
            $result['expires_at'] = time() + (int) $data['expires_in'];
        }

        return $result;
    }

    /**
     * Store tokens to disk.
     *
     * @param array{access_token: string, refresh_token?: string, expires_at?: int} $tokens
     */
    private function storeTokens(string $serverName, array $tokens): void
    {
        $dir = $this->tokensDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        $path = $this->tokensPath($serverName);
        $json = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($path, $json . "\n");
        chmod($path, 0o600);
    }

    /**
     * Load tokens from disk.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}|null
     */
    private function loadTokens(string $serverName): ?array
    {
        $path = $this->tokensPath($serverName);

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            return null;
        }

        /** @var array{access_token: string, refresh_token?: string, expires_at?: int} $data */
        return $data;
    }

    private function tokensDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/' . self::TOKENS_DIR;
    }

    private function tokensPath(string $serverName): string
    {
        $sanitized = preg_replace('/[^a-z0-9_-]/', '_', strtolower($serverName)) ?? $serverName;

        return $this->tokensDir() . '/' . $sanitized . '.json';
    }
}
