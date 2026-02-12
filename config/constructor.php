<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Constructor Search API Configuration
    |--------------------------------------------------------------------------
    |
    | These settings configure the Constructor.io Search API endpoints used
    | for search functionality. Credentials (api_key, api_token) should be
    | stored in your .env file.
    |
    */

    // Search API base URL (ac.cnstrc.com)
    'search_base_url' => env('CONSTRUCTOR_SEARCH_BASE_URL', 'https://ac.cnstrc.com'),

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Constructor.io API credentials. The API key is public and used for
    | search/browse requests. The API token is secret and used for catalog
    | management and authenticated endpoints.
    |
    */

    // Public API key (used for search, browse, autocomplete)
    'api_key' => env('CONSTRUCTOR_API_KEY'),

    // Secret API token (used for catalog uploads, admin operations)
    'api_token' => env('CONSTRUCTOR_API_TOKEN'),

    // Agent domain for Shopping Agent (e.g., 'your-store.cnstrc.com')
    'agent_domain' => env('CONSTRUCTOR_AGENT_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Constructor Agent API Configuration
    |--------------------------------------------------------------------------
    |
    | These settings configure the Constructor.io Agent API (Shopping Agent
    | and Product Insights Agent) used for AI-powered product discovery
    | and product Q&A functionality.
    |
    */

    // Agent API base URL (agent.cnstrc.com)
    'agent_base_url' => env('CONSTRUCTOR_AGENT_BASE_URL', 'https://agent.cnstrc.com'),

    // Enable content moderation for agent responses
    'agent_guard' => env('CONSTRUCTOR_AGENT_GUARD', true),

    // Maximum number of result events to return from Shopping Agent
    'agent_num_result_events' => env('CONSTRUCTOR_AGENT_NUM_RESULT_EVENTS', 5),

    // Maximum results per event from Shopping Agent
    'agent_num_results_per_event' => env('CONSTRUCTOR_AGENT_NUM_RESULTS_PER_EVENT', 4),

    /*
    |--------------------------------------------------------------------------
    | Backend Integration
    |--------------------------------------------------------------------------
    |
    | When making server-side API calls on behalf of browser users, Constructor
    | requires specific headers and parameters for session tracking,
    | personalization, and analytics attribution.
    |
    | @see https://docs.constructor.com/docs/integrating-with-constructor-backend-integrations-required-parameters
    |
    */

    // Backend authentication token (sent as x-cnstrc-token header)
    // If null, falls back to api_token
    'backend_token' => env('CONSTRUCTOR_BACKEND_TOKEN'),

    // Client identifier for 'c' query parameter
    // Format: cio-be-laravel-{company-name}
    // If null, auto-generates from app.name
    'client_identifier' => env('CONSTRUCTOR_CLIENT_IDENTIFIER'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    */

    // Request timeout in seconds
    'timeout' => env('CONSTRUCTOR_TIMEOUT', 30),

    // Retry configuration for failed requests
    'retry_times' => env('CONSTRUCTOR_RETRY_TIMES', 2),
    'retry_sleep' => env('CONSTRUCTOR_RETRY_SLEEP', 100), // milliseconds
];
