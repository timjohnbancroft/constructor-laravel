<?php

namespace ConstructorIO\Laravel\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for Constructor.io catalog management and admin operations.
 *
 * This service handles catalog uploads, task monitoring, and authenticated
 * API operations. It uses Bearer token authentication for write operations
 * and Basic Auth for read operations.
 *
 * @example
 * // Create instance
 * $service = new ConstructorService(
 *     config('constructor.api_key'),
 *     config('constructor.api_token')
 * );
 *
 * // Upload catalog
 * $result = $service->uploadCatalog('/path/to/items.csv', 'create_or_replace');
 * $taskId = $result['task_id'];
 *
 * // Wait for completion
 * $status = $service->waitForTaskCompletion($taskId);
 * if ($status['successful']) {
 *     echo "Catalog uploaded!";
 * }
 */
class ConstructorService
{
    protected string $baseUrl;

    /**
     * Public API key (used for identifying the index).
     */
    public string $apiKey;

    /**
     * Secret API token (used for authenticated operations).
     */
    protected string $apiToken;

    /**
     * Whether credentials have been verified.
     */
    protected bool $credentialsVerified = false;

    /**
     * Create a new ConstructorService instance.
     *
     * @param  string|null  $apiKey  API key. If null, uses config('constructor.api_key')
     * @param  string|null  $apiToken  API token. If null, uses config('constructor.api_token')
     *
     * @throws \Exception  If required configuration is missing
     */
    public function __construct(?string $apiKey = null, ?string $apiToken = null)
    {
        // Use provided credentials or fall back to config (set by middleware)
        $key = $apiKey ?? Config::get('constructor.api_key');
        $token = $apiToken ?? Config::get('constructor.api_token');
        $baseUrl = config('constructor.search_base_url');

        // Validate before assignment to provide clear error message
        if (empty($key) || empty($token) || empty($baseUrl)) {
            throw new \Exception('Constructor.io configuration not properly set. Ensure CONSTRUCTOR_API_KEY, CONSTRUCTOR_API_TOKEN, and CONSTRUCTOR_SEARCH_BASE_URL are configured.');
        }

        $this->apiKey = $key;
        $this->apiToken = $token;
        $this->baseUrl = $baseUrl;
    }

    /**
     * Make an HTTP request to Constructor.io API.
     *
     * Handles authentication automatically based on $requiresAuth flag.
     * READ operations use Basic Auth, WRITE operations should use Bearer
     * (handled separately in uploadCatalog).
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param  string  $uri  API endpoint path (e.g., '/v1/verify')
     * @param  array  $options  Request options (query, json, headers)
     * @param  bool  $requiresAuth  Whether to include Basic Auth
     * @return array  Decoded JSON response
     *
     * @throws \Exception  On HTTP errors or authentication failures
     */
    public function makeRequest(string $method, string $uri, array $options = [], bool $requiresAuth = false): array
    {
        // Verify credentials if required and not already verified
        if ($requiresAuth && ! $this->credentialsVerified) {
            $this->verifyCredentials();
        }

        // Initialize the HTTP client with any provided headers
        $http = Http::withHeaders($options['headers'] ?? []);

        // Include Basic Auth if the endpoint requires authentication
        // Note: READ operations use Basic Auth, WRITE operations use Bearer (handled separately)
        if ($requiresAuth) {
            $http = $http->withBasicAuth($this->apiToken, '');
        }

        // Build the full URL
        $url = "{$this->baseUrl}{$uri}";

        // Ensure the API key is included in the query parameters
        $query = array_merge(
            ['key' => $this->apiKey],
            $options['query'] ?? []
        );

        // Prepare request options
        $requestOptions = [
            'query' => $query,
        ];

        // Add JSON payload if provided
        if (isset($options['json'])) {
            $requestOptions['json'] = $options['json'];
        }

        try {
            // Make the HTTP request using the 'send' method
            $response = $http->send(strtoupper($method), $url, $requestOptions);

            // Handle the response
            if ($response->successful()) {
                return $response->json();
            }

            // Handle rate limiting
            if ($response->status() === 429) {
                throw new \Exception('Rate limit exceeded', 429);
            }

            // Handle authentication errors
            if ($response->status() === 401) {
                throw new \Exception('Authentication failed: Invalid API key or token', 401);
            }

            // Handle other errors
            throw new \Exception('Constructor.io API request failed: '.$response->body(), $response->status());
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Handle network or connection errors
            throw new \Exception('HTTP request error: '.$e->getMessage(), $e->getCode());
        }
    }

    /**
     * Verify Constructor.io credentials before making authenticated requests.
     *
     * @throws \Exception  If credential verification fails
     */
    protected function verifyCredentials(): void
    {
        $this->credentialsVerified = true; // Set to true before calling verify
        try {
            $this->verify(); // Calls the verify method
        } catch (\Exception $e) {
            $this->credentialsVerified = false; // Reset to false if verification fails
            throw new \Exception('Failed to verify Constructor.io credentials: '.$e->getMessage(), $e->getCode());
        }
    }

    /**
     * Verify API credentials with Constructor.io.
     *
     * Makes a test request to the verify endpoint to confirm credentials are valid.
     *
     * @return array  Verification response from Constructor.io
     *
     * @throws \Exception  If verification fails
     */
    public function verify(): array
    {
        // Verify is a READ operation - uses Basic Auth
        return $this->makeRequest('get', '/v1/verify', [], true);
    }

    /**
     * Upload catalog file to Constructor.io
     *
     * @param string $filePath Absolute path to items.csv or items.jsonl
     * @param string $operation 'create_or_replace' or 'patch'
     * @param array $options Additional options (force, section, variations, etc.)
     * @return array Response with task_id
     * @throws \Exception
     */
    public function uploadCatalog(string $filePath, string $operation = 'create_or_replace', array $options = []): array
    {
        // Validate file exists and is readable
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("Catalog file not found or not readable: {$filePath}");
        }

        // Determine HTTP method based on operation
        $method = $operation === 'patch' ? 'PATCH' : 'PUT';

        try {
            // Build URL with API key as query parameter
            $url = "{$this->baseUrl}/v1/catalog?key={$this->apiKey}";

            // Add force parameter to URL if specified
            if (isset($options['force']) && $options['force']) {
                $url .= "&force=true";
            }

            // Add section to URL if specified
            if (isset($options['section'])) {
                $url .= "&section=" . urlencode($options['section']);
            }

            Log::info('Uploading catalog to Constructor', [
                'file' => basename($filePath),
                'force' => isset($options['force']) && $options['force'],
                'section' => $options['section'] ?? 'Products',
                'url' => preg_replace('/key=[^&]+/', 'key=***', $url), // Mask API key in logs
            ]);

            // Build multipart array - this is the ONLY way to send both file and form parameters
            $multipart = [
                [
                    'name'     => 'items',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ],
            ];

            // Add item_groups file if provided (only for CSV format)
            if (isset($options['item_groups_file']) && file_exists($options['item_groups_file'])) {
                $multipart[] = [
                    'name'     => 'item_groups',
                    'contents' => fopen($options['item_groups_file'], 'r'),
                    'filename' => basename($options['item_groups_file']),
                ];

                Log::info('Including item_groups file in upload', [
                    'item_groups_file' => basename($options['item_groups_file']),
                ]);
            }

            // Use asMultipart() to send properly formatted multipart request
            $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ])
                ->timeout(300)
                ->asMultipart()
                ->{strtolower($method)}($url, $multipart);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Catalog uploaded to Constructor', [
                    'task_id' => $data['task_id'] ?? null,
                    'file_size' => filesize($filePath),
                    'operation' => $operation,
                ]);

                return $data;
            }

            // Handle specific error codes
            if ($response->status() === 429) {
                throw new \Exception('Rate limit exceeded. Please try again later.', 429);
            }

            if ($response->status() === 401) {
                Log::error('Constructor authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers(),
                ]);
                throw new \Exception('Authentication failed. Check API credentials. Response: ' . $response->body(), 401);
            }

            Log::error('Constructor catalog upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Catalog upload failed: {$response->body()}", $response->status());

        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw new \Exception("HTTP request error: {$e->getMessage()}", $e->getCode());
        }
    }

    /**
     * Check status of a catalog upload task
     *
     * @param string $taskId Task ID from uploadCatalog response
     * @return array Task status information
     */
    public function getTaskStatus(string $taskId): array
    {
        try {
            // Task status is a READ operation - uses Basic Auth
            $response = $this->makeRequest('get', "/v1/tasks/{$taskId}", [], true);

            // Return full response with convenience flags
            return array_merge($response, [
                'completed' => in_array($response['status'] ?? '', ['DONE', 'FAILED']),
                'successful' => ($response['status'] ?? '') === 'DONE',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to get task status for {$taskId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Poll task status until completion or timeout
     *
     * @param string $taskId
     * @param int $maxAttempts Maximum number of polling attempts
     * @param int $delaySeconds Delay between attempts
     * @return array Final task status
     */
    public function waitForTaskCompletion(string $taskId, int $maxAttempts = 60, int $delaySeconds = 10): array
    {
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $status = $this->getTaskStatus($taskId);

            if ($status['completed']) {
                return $status;
            }

            $attempt++;
            sleep($delaySeconds);
        }

        throw new \Exception("Task {$taskId} did not complete within timeout period");
    }

    /**
     * Get current catalog information (if endpoint exists)
     *
     * @return array Catalog metadata
     */
    public function getCatalogInfo(): array
    {
        try {
            // Catalog info is a READ operation - uses Basic Auth
            return $this->makeRequest('get', '/v1/catalog', [], true);
        } catch (\Exception $e) {
            Log::warning("Could not fetch catalog info: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get the base URL for Constructor.io API.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
