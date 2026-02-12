<?php

namespace ConstructorIO\Laravel\Traits;

/**
 * Provides backend integration context for Constructor.io server-side API calls.
 *
 * When making API calls server-side on behalf of browser users, Constructor requires
 * specific HTTP headers and query parameters to properly track sessions, personalize
 * results, and attribute analytics.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-backend-integrations-required-parameters
 */
trait BackendRequestContext
{
    /**
     * Build HTTP headers required for backend integration.
     *
     * Returns associative array suitable for Laravel's Http::withHeaders().
     *
     * @return array<string, string>
     */
    protected function buildBackendHeaders(): array
    {
        $headers = [];

        try {
            $ip = request()->ip();
            if ($ip) {
                $headers['X-Forwarded-For'] = $ip;
            }
        } catch (\Throwable) {
            // Non-HTTP context (CLI, queue worker, etc.) — skip
        }

        try {
            $userAgent = request()->userAgent();
            if ($userAgent) {
                $headers['User-Agent'] = $userAgent;
            }
        } catch (\Throwable) {
            // Non-HTTP context — skip
        }

        $token = config('constructor.backend_token') ?? config('constructor.api_token');
        if ($token) {
            $headers['x-cnstrc-token'] = $token;
        }

        return $headers;
    }

    /**
     * Build HTTP headers in cURL format for streaming requests.
     *
     * Returns array of "Header: value" strings for CURLOPT_HTTPHEADER.
     *
     * @return array<int, string>
     */
    protected function buildBackendHeadersForCurl(): array
    {
        $curlHeaders = [];

        foreach ($this->buildBackendHeaders() as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        return $curlHeaders;
    }

    /**
     * Add backend query parameters to a request params array.
     *
     * Only sets parameters that are not already present, preserving
     * any values explicitly passed via options.
     *
     * @param  array  &$params  Request parameters (modified by reference)
     */
    protected function addBackendQueryParams(array &$params): void
    {
        // Client identifier
        if (!isset($params['c'])) {
            $clientId = config('constructor.client_identifier');
            if (!$clientId) {
                $appName = config('app.name', 'laravel');
                $clientId = 'cio-be-laravel-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($appName));
            }
            $params['c'] = $clientId;
        }

        // Browser/app instance identifier from Constructor JS SDK cookie
        if (!isset($params['i'])) {
            $instanceId = $this->getConstructorCookie('ConstructorioID_client_id');
            if ($instanceId) {
                $params['i'] = $instanceId;
            }
        }

        // Session identifier from Constructor JS SDK cookie
        if (!isset($params['s'])) {
            $sessionId = $this->getConstructorCookie('ConstructorioID_session_id');
            if ($sessionId) {
                $params['s'] = $sessionId;
            }
        }

        // Request timestamp in milliseconds
        $params['_dt'] = (int) (microtime(true) * 1000);

        // Origin referrer
        if (!isset($params['origin_referrer'])) {
            try {
                $referer = request()->header('Referer');
                if ($referer) {
                    $params['origin_referrer'] = $referer;
                }
            } catch (\Throwable) {
                // Non-HTTP context — skip
            }
        }
    }

    /**
     * Read a Constructor cookie directly from $_COOKIE.
     *
     * Constructor's JS SDK sets cookies that are NOT encrypted by Laravel's
     * EncryptCookies middleware. We bypass $request->cookie() which would
     * attempt to decrypt them and fail.
     *
     * @param  string  $name  Cookie name
     * @return string|null  Cookie value or null if not available
     */
    protected function getConstructorCookie(string $name): ?string
    {
        try {
            return $_COOKIE[$name] ?? null;
        } catch (\Throwable) {
            // Non-HTTP context or $_COOKIE not available
            return null;
        }
    }
}
