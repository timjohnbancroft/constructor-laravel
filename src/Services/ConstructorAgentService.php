<?php

namespace ConstructorIO\Laravel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for Constructor AI Agent features.
 *
 * This service handles communication with Constructor's Agent API (agent.cnstrc.com)
 * for both the AI Shopping Agent (ASA) and Product Insights Agent (PIA).
 *
 * ASA: Natural language product discovery
 * PIA: Product-specific Q&A on product detail pages
 *
 * NOTE: This class requires a configuration object or model with the following properties:
 * - api_key: Your Constructor.io API key
 * - agent_domain: Your agent domain (required for Shopping Agent)
 *
 * You can adapt the constructor to accept these values directly or via config.
 */
class ConstructorAgentService
{
    protected string $apiKey;

    protected ?string $agentDomain;

    protected string $baseUrl;

    protected int $timeout;

    /**
     * Create a new ConstructorAgentService instance.
     *
     * @param string $apiKey Your Constructor.io API key
     * @param string|null $agentDomain Your agent domain (required for Shopping Agent)
     */
    public function __construct(string $apiKey, ?string $agentDomain = null)
    {
        $this->apiKey = $apiKey;
        $this->agentDomain = $agentDomain;
        $this->baseUrl = config('constructor.agent_base_url', 'https://agent.cnstrc.com');
        $this->timeout = config('constructor.timeout', 30);
    }

    /**
     * AI Shopping Agent - natural language product discovery.
     *
     * Endpoint: GET /v1/intent/{query}
     *
     * @param  string  $query  Natural language query from the user
     * @param  string|null  $threadId  Thread ID for conversation continuity
     * @param  array  $options  Additional options (filters, guard, num_results, etc.)
     * @return array Response with message and product recommendations
     *
     * @throws \Exception If agent_domain is not configured or API fails
     */
    public function askShoppingAgent(string $query, ?string $threadId = null, array $options = []): array
    {
        if (empty($this->agentDomain)) {
            throw new \Exception('Agent domain is required for Shopping Agent. Configure agent_domain in sandbox settings.');
        }

        try {
            $params = [
                'key' => $this->apiKey,
                'domain' => $this->agentDomain,
                'guard' => $options['guard'] ?? config('constructor.agent_guard', true),
                'num_result_events' => $options['num_result_events'] ?? config('constructor.agent_num_result_events', 5),
                'num_results_per_event' => $options['num_results_per_event'] ?? config('constructor.agent_num_results_per_event', 4),
            ];

            // Add thread_id for conversation continuity
            if ($threadId) {
                $params['thread_id'] = $threadId;
            }

            // Add pre-filter expression if filters provided
            if (! empty($options['filters'])) {
                $params['pre_filter_expression'] = is_string($options['filters'])
                    ? $options['filters']
                    : json_encode($options['filters']);
            }

            // Add user context if available
            $this->addUserContext($params, $options);

            $endpoint = '/v1/intent/'.urlencode($query);
            $response = $this->makeRequest($endpoint, $params);

            return $this->transformShoppingAgentResponse($response);
        } catch (\Exception $e) {
            Log::error('ConstructorAgentService::askShoppingAgent error: '.$e->getMessage(), [
                'query' => $query,
                'thread_id' => $threadId,
            ]);
            throw $e;
        }
    }

    /**
     * AI Shopping Agent with streaming - natural language product discovery with real-time updates.
     *
     * Endpoint: GET /v1/intent/{query}
     *
     * This method streams SSE events as they arrive, calling the onEvent callback
     * for each event. This enables real-time UI updates as the AI responds.
     *
     * @param  string  $query  Natural language query from the user
     * @param  callable  $onEvent  Callback: fn(string $eventType, array $eventData) called for each SSE event
     * @param  string|null  $threadId  Thread ID for conversation continuity
     * @param  array  $options  Additional options (filters, guard, num_results, etc.)
     * @return array Final aggregated response with message and product recommendations
     *
     * @throws \Exception If agent_domain is not configured or API fails
     */
    public function askShoppingAgentStreaming(
        string $query,
        callable $onEvent,
        ?string $threadId = null,
        array $options = []
    ): array {
        if (empty($this->agentDomain)) {
            throw new \Exception('Agent domain is required for Shopping Agent. Configure agent_domain in sandbox settings.');
        }

        $params = [
            'key' => $this->apiKey,
            'domain' => $this->agentDomain,
            'guard' => $options['guard'] ?? config('constructor.agent_guard', true),
            'num_result_events' => $options['num_result_events'] ?? config('constructor.agent_num_result_events', 5),
            'num_results_per_event' => $options['num_results_per_event'] ?? config('constructor.agent_num_results_per_event', 4),
        ];

        // Add thread_id for conversation continuity
        if ($threadId) {
            $params['thread_id'] = $threadId;
        }

        // Add pre-filter expression if filters provided
        if (! empty($options['filters'])) {
            $params['pre_filter_expression'] = is_string($options['filters'])
                ? $options['filters']
                : json_encode($options['filters']);
        }

        // Add user context if available
        $this->addUserContext($params, $options);

        $endpoint = '/v1/intent/'.urlencode($query);
        $url = $this->baseUrl.$endpoint.'?'.http_build_query($params);

        // Aggregated result to return at the end
        $result = [
            'thread_id' => null,
            'text' => '',
            'products' => [],
            'follow_up_questions' => [],
        ];

        try {
            // Use cURL for true streaming support
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/event-stream',
                    'Cache-Control: no-cache',
                ],
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$result, $onEvent) {
                    $this->processStreamChunk($data, $result, $onEvent);

                    return strlen($data);
                },
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (! $success || $httpCode >= 400) {
                if ($httpCode === 429) {
                    throw new \Exception('Constructor Agent API rate limit exceeded. Please try again later.');
                }
                if ($httpCode === 401 || $httpCode === 403) {
                    throw new \Exception('Constructor Agent API authentication failed. Check your API key and domain configuration.');
                }
                throw new \Exception("Constructor Agent API error: {$httpCode} - {$error}");
            }

            // Call end event
            $onEvent('complete', $result);

            return $this->transformShoppingAgentResponse($result);

        } catch (\Exception $e) {
            Log::error('ConstructorAgentService::askShoppingAgentStreaming error: '.$e->getMessage(), [
                'query' => $query,
                'thread_id' => $threadId,
            ]);
            throw $e;
        }
    }

    /**
     * Process a chunk of streaming data from the SSE response.
     *
     * SSE format:
     * event: <type>
     * data: <json>
     *
     * Events are separated by double newlines.
     */
    protected function processStreamChunk(string $chunk, array &$result, callable $onEvent): void
    {
        static $buffer = '';

        $buffer .= $chunk;

        // Normalize line endings
        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);

        // Process complete events (separated by double newlines)
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $eventBlock = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $event = $this->parseEventBlock($eventBlock);
            if ($event) {
                $this->handleStreamEvent($event['type'], $event['data'], $result, $onEvent);
            }
        }
    }

    /**
     * Parse a single SSE event block into type and data.
     */
    protected function parseEventBlock(string $block): ?array
    {
        $lines = explode("\n", trim($block));
        $eventType = null;
        $eventData = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event: ')) {
                $eventType = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'data: ')) {
                $eventData = json_decode(trim(substr($line, 6)), true);
            }
        }

        if ($eventType === null || $eventData === null) {
            return null;
        }

        return ['type' => $eventType, 'data' => $eventData];
    }

    /**
     * Handle a parsed SSE event, updating result and calling the callback.
     */
    protected function handleStreamEvent(string $type, array $data, array &$result, callable $onEvent): void
    {
        switch ($type) {
            case 'start':
                $result['thread_id'] = $data['thread_id'] ?? null;
                $onEvent('start', ['thread_id' => $result['thread_id']]);
                break;

            case 'message':
                $text = $data['text'] ?? '';
                $result['text'] .= $text;
                $onEvent('message', ['text' => $text, 'accumulated' => $result['text']]);
                break;

            case 'search_result':
            case 'search_results':
                $products = [];
                if (isset($data['response']['results'])) {
                    $products = $data['response']['results'];
                } elseif (isset($data['data'])) {
                    $products = [$data['data']];
                } elseif (isset($data['products'])) {
                    $products = $data['products'];
                } elseif (isset($data['results'])) {
                    $products = $data['results'];
                }

                // Transform and add to result
                $transformedProducts = $this->transformProducts($products);
                $result['products'] = array_merge($result['products'], $transformedProducts);
                $onEvent('products', ['products' => $transformedProducts]);
                break;

            case 'suggestions':
            case 'follow_up':
            case 'follow_up_questions':
                $questions = [];
                if (isset($data['questions'])) {
                    $questions = $data['questions'];
                } elseif (isset($data['suggestions'])) {
                    $questions = $data['suggestions'];
                } elseif (is_array($data)) {
                    $questions = $data;
                }
                $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $questions);
                $onEvent('follow_up', ['questions' => $questions]);
                break;

            case 'end':
                if (isset($data['follow_up_questions'])) {
                    $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $data['follow_up_questions']);
                } elseif (isset($data['suggestions'])) {
                    $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $data['suggestions']);
                }
                $onEvent('end', $data);
                break;
        }
    }

    /**
     * Product Insights Agent - get suggested questions for a product.
     *
     * Endpoint: GET /v1/item_questions
     *
     * @param  string  $itemId  Product/item ID
     * @param  array  $options  Additional options (variation_id, num_results)
     * @return array Response with list of suggested questions
     */
    public function getProductQuestions(string $itemId, array $options = []): array
    {
        try {
            $params = [
                'key' => $this->apiKey,
                'item_id' => $itemId,
                'num_results' => $options['num_results'] ?? 5,
            ];

            // Add variation ID if specified
            if (isset($options['variation_id'])) {
                $params['variation_id'] = $options['variation_id'];
            }

            // Add user context
            $this->addUserContext($params, $options);

            $response = $this->makeRequest('/v1/item_questions', $params);

            return $this->transformQuestionsResponse($response);
        } catch (\Exception $e) {
            Log::error('ConstructorAgentService::getProductQuestions error: '.$e->getMessage(), [
                'item_id' => $itemId,
            ]);

            // Return empty questions array on error instead of throwing
            return ['questions' => []];
        }
    }

    /**
     * Product Insights Agent - get answer to a product question.
     *
     * Endpoint: GET /v1/item_questions/{question}/answer
     *
     * @param  string  $question  The question to answer
     * @param  string  $itemId  Product/item ID
     * @param  string|null  $threadId  Thread ID for conversation continuity
     * @param  array  $options  Additional options (variation_id, guard, num_results)
     * @return array Response with answer and follow-up questions
     */
    public function askProductQuestion(string $question, string $itemId, ?string $threadId = null, array $options = []): array
    {
        try {
            $params = [
                'key' => $this->apiKey,
                'item_id' => $itemId,
                'guard' => $options['guard'] ?? config('constructor.agent_guard', true),
                'num_results' => $options['num_results'] ?? 3,
            ];

            // Add thread_id for conversation continuity
            if ($threadId) {
                $params['thread_id'] = $threadId;
            }

            // Add variation ID if specified
            if (isset($options['variation_id'])) {
                $params['variation_id'] = $options['variation_id'];
            }

            // Add user context
            $this->addUserContext($params, $options);

            $endpoint = '/v1/item_questions/'.urlencode($question).'/answer';
            $response = $this->makeRequest($endpoint, $params);

            return $this->transformAnswerResponse($response);
        } catch (\Exception $e) {
            Log::error('ConstructorAgentService::askProductQuestion error: '.$e->getMessage(), [
                'question' => $question,
                'item_id' => $itemId,
                'thread_id' => $threadId,
            ]);
            throw $e;
        }
    }

    /**
     * Make an HTTP request to the Constructor Agent API.
     */
    protected function makeRequest(string $endpoint, array $params): array
    {
        $url = $this->baseUrl.$endpoint;

        $response = Http::timeout($this->timeout)
            ->retry(
                config('constructor.retry_times', 2),
                config('constructor.retry_sleep', 100)
            )
            ->get($url, $params);

        if ($response->failed()) {
            $statusCode = $response->status();
            $body = $response->body();

            // Handle specific error codes
            if ($statusCode === 429) {
                throw new \Exception('Constructor Agent API rate limit exceeded. Please try again later.');
            }

            if ($statusCode === 401 || $statusCode === 403) {
                throw new \Exception('Constructor Agent API authentication failed. Check your API key and domain configuration.');
            }

            throw new \Exception("Constructor Agent API error: {$statusCode} - {$body}");
        }

        // Check if response is SSE format (starts with "event:")
        $body = $response->body();
        if (str_starts_with(trim($body), 'event:')) {
            return $this->parseSseResponse($body);
        }

        // Fallback to JSON parsing for non-SSE responses
        return $response->json() ?? [];
    }

    /**
     * Parse SSE (Server-Sent Events) response into structured data.
     *
     * The Constructor Agent API returns SSE format with events like:
     * - start: Contains thread_id and request info
     * - message: Contains AI-generated text response (may be multiple events for streaming)
     * - search_result/search_results: Contains product recommendations
     * - suggestions/follow_up: Contains follow-up question suggestions
     * - end: Marks the end of the stream (may contain final follow-up questions)
     *
     * SSE format:
     * ```
     * event: message
     * data: {"text": "Here are some options..."}
     *
     * event: search_result
     * data: {"response": {"results": [...]}}
     * ```
     *
     * @param  string  $body  Raw SSE response body
     * @return array{thread_id: ?string, text: string, products: array, follow_up_questions: array}
     */
    protected function parseSseResponse(string $body): array
    {
        $result = [
            'thread_id' => null,
            'text' => '',
            'products' => [],
            'follow_up_questions' => [],
        ];

        // Normalize line endings (CRLF to LF)
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Split by double newlines (SSE event separator)
        $events = preg_split('/\n\n+/', trim($body));

        foreach ($events as $eventBlock) {
            $lines = explode("\n", $eventBlock);
            $eventType = null;
            $eventData = null;

            foreach ($lines as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $eventType = trim(substr($line, 7));
                } elseif (str_starts_with($line, 'data: ')) {
                    $eventData = json_decode(trim(substr($line, 6)), true);
                }
            }

            if ($eventData === null) {
                continue;
            }

            // Extract data based on event type
            switch ($eventType) {
                case 'start':
                    $result['thread_id'] = $eventData['thread_id'] ?? null;
                    break;
                case 'message':
                    $result['text'] .= $eventData['text'] ?? '';
                    break;
                case 'search_result':
                case 'search_results':
                    // Handle both singular and plural event names
                    // search_result events contain products in response.results array
                    if (isset($eventData['response']['results'])) {
                        $result['products'] = array_merge($result['products'], $eventData['response']['results']);
                    } elseif (isset($eventData['data'])) {
                        $result['products'][] = $eventData['data'];
                    } elseif (isset($eventData['products'])) {
                        $result['products'] = array_merge($result['products'], $eventData['products']);
                    } elseif (isset($eventData['results'])) {
                        $result['products'] = array_merge($result['products'], $eventData['results']);
                    }
                    break;
                case 'suggestions':
                case 'follow_up':
                case 'follow_up_questions':
                    // Extract follow-up questions/suggestions
                    if (isset($eventData['questions'])) {
                        $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $eventData['questions']);
                    } elseif (isset($eventData['suggestions'])) {
                        $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $eventData['suggestions']);
                    } elseif (is_array($eventData)) {
                        $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $eventData);
                    }
                    break;
                case 'end':
                    // Check if end event contains follow-up questions
                    if (isset($eventData['follow_up_questions'])) {
                        $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $eventData['follow_up_questions']);
                    } elseif (isset($eventData['suggestions'])) {
                        $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $eventData['suggestions']);
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * Add user context parameters to the request.
     *
     * Adds client identifier, user segments, and session information
     * to the request parameters for personalization and analytics.
     *
     * Constructor uses these parameters:
     * - c: Client identifier and version (e.g., 'cio-laravel-1.0')
     * - us: User segments for targeting (comma-separated)
     * - ui: User identifier for logged-in users
     * - s: Session number for analytics
     * - i: Browser/app instance identifier
     *
     * @param  array  &$params  Request parameters (modified by reference)
     * @param  array  $options  Options containing user context values
     */
    protected function addUserContext(array &$params, array $options): void
    {
        // Client identifier and version
        if (isset($options['client'])) {
            $params['c'] = $options['client'];
        } else {
            $params['c'] = 'cio-laravel-1.0';
        }

        // User segments/context
        if (isset($options['user_segments'])) {
            $params['us'] = is_array($options['user_segments'])
                ? implode(',', $options['user_segments'])
                : $options['user_segments'];
        }

        // User identifier (for logged-in users)
        if (isset($options['user_id'])) {
            $params['ui'] = $options['user_id'];
        }

        // Session number
        if (isset($options['session'])) {
            $params['s'] = $options['session'];
        }

        // Browser/app instance identifier
        if (isset($options['instance_id'])) {
            $params['i'] = $options['instance_id'];
        }
    }

    /**
     * Transform Shopping Agent response to a consistent format.
     *
     * Extracts the AI message, recommended products, and thread ID from
     * the Shopping Agent response. Handles multiple response formats
     * from different API versions and SSE parsing results.
     *
     * @param  array  $response  Raw or parsed response from Shopping Agent
     * @return array{message: string, products: array, thread_id: ?string, _raw: array}
     */
    protected function transformShoppingAgentResponse(array $response): array
    {
        $message = '';
        $products = [];
        $threadId = $response['thread_id'] ?? null;

        // Extract message and products from response
        // The response format may vary based on the API version
        if (isset($response['message'])) {
            $message = $response['message'];
        } elseif (isset($response['response']['message'])) {
            $message = $response['response']['message'];
        } elseif (isset($response['text'])) {
            $message = $response['text'];
        }

        // Extract products
        if (isset($response['products'])) {
            $products = $this->transformProducts($response['products']);
        } elseif (isset($response['response']['results'])) {
            $products = $this->transformProducts($response['response']['results']);
        } elseif (isset($response['results'])) {
            $products = $this->transformProducts($response['results']);
        }

        return [
            'message' => $message,
            'products' => $products,
            'thread_id' => $threadId,
            '_raw' => $response,
        ];
    }

    /**
     * Transform questions response to a consistent format.
     *
     * Normalizes suggested questions from the Product Insights Agent.
     * The API may return questions as objects with 'value', 'question',
     * or 'text' keys, or as plain strings. This normalizes all formats.
     *
     * @param  array  $response  Raw response from item_questions endpoint
     * @return array{questions: array<int, string>, _raw: array}
     */
    protected function transformQuestionsResponse(array $response): array
    {
        $rawQuestions = [];

        if (isset($response['questions'])) {
            $rawQuestions = $response['questions'];
        } elseif (isset($response['response']['questions'])) {
            $rawQuestions = $response['response']['questions'];
        } elseif (isset($response['items'])) {
            $rawQuestions = $response['items'];
        }

        // Normalize questions - API may return objects with 'value', 'question', or 'text' keys
        $questions = [];
        foreach ($rawQuestions as $q) {
            if (is_array($q)) {
                $questions[] = $q['value'] ?? $q['question'] ?? $q['text'] ?? '';
            } elseif (is_string($q)) {
                $questions[] = $q;
            }
        }

        return [
            'questions' => array_values(array_filter($questions)),
            '_raw' => $response,
        ];
    }

    /**
     * Transform answer response to a consistent format.
     *
     * Extracts the AI-generated answer, follow-up questions, and thread ID
     * from the Product Insights Agent response. Normalizes follow-up questions
     * from objects to plain strings.
     *
     * @param  array  $response  Raw response from item_questions/answer endpoint
     * @return array{answer: string, follow_up_questions: array<int, string>, thread_id: ?string, _raw: array}
     */
    protected function transformAnswerResponse(array $response): array
    {
        $answer = '';
        $followUpQuestions = [];
        $threadId = $response['thread_id'] ?? null;

        // Extract answer - API returns answer in 'value' field
        if (isset($response['value'])) {
            $answer = $response['value'];
        } elseif (isset($response['answer'])) {
            $answer = $response['answer'];
        } elseif (isset($response['response']['answer'])) {
            $answer = $response['response']['answer'];
        } elseif (isset($response['text'])) {
            $answer = $response['text'];
        }

        // Extract follow-up questions - API returns them as array of objects with 'value' key
        $rawQuestions = [];
        if (isset($response['follow_up_questions'])) {
            $rawQuestions = $response['follow_up_questions'];
        } elseif (isset($response['response']['follow_up_questions'])) {
            $rawQuestions = $response['response']['follow_up_questions'];
        } elseif (isset($response['suggestions'])) {
            $rawQuestions = $response['suggestions'];
        }

        // Normalize follow-up questions - extract 'value' if they're objects
        foreach ($rawQuestions as $q) {
            if (is_array($q) && isset($q['value'])) {
                $followUpQuestions[] = $q['value'];
            } elseif (is_string($q)) {
                $followUpQuestions[] = $q;
            }
        }

        return [
            'answer' => $answer,
            'follow_up_questions' => array_values(array_filter($followUpQuestions)),
            'thread_id' => $threadId,
            '_raw' => $response,
        ];
    }

    /**
     * Transform product results to a consistent format.
     *
     * Normalizes Constructor's product structure (with nested 'data' object)
     * into a flat format consistent with other services. Used by both
     * Shopping Agent and Product Insights Agent responses.
     *
     * @param  array  $products  Array of products from Constructor response
     * @return array<int, array{id: ?string, name: string, description: string, url: string, image_url: ?string, price: ?float, original_price: ?float, sku: ?string, brand: ?string}>
     */
    protected function transformProducts(array $products): array
    {
        return array_map(function ($product) {
            $data = $product['data'] ?? $product;

            return [
                'id' => $data['id'] ?? $product['value'] ?? null,
                'name' => $product['value'] ?? $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'url' => $data['url'] ?? '',
                'image_url' => $data['image_url'] ?? null,
                'price' => $data['price'] ?? null,
                'original_price' => $data['original_price'] ?? null,
                'sku' => $data['sku'] ?? null,
                'brand' => $data['brand'] ?? null,
            ];
        }, $products);
    }

    /**
     * Search for complementary products based on a query.
     * Used to find products mentioned in PIA answers or for "Complete the Look" widget.
     *
     * @param  string  $productName  The product name to find complements for
     * @param  int  $limit  Maximum number of products to return
     * @param  string|null  $productCategory  The product's category (used to determine complementary categories)
     * @return array Array of product data
     */
    public function searchComplementaryProducts(string $productName, int $limit = 4, ?string $productCategory = null): array
    {
        if (empty($this->agentDomain)) {
            // Cannot use Shopping Agent without domain
            return [];
        }

        // Get complementary category slots based on product type
        $slots = $this->getComplementaryCategorySlots($productCategory);
        $slotList = implode(', ', $slots);

        // Build explicit prompt requesting one item from each category for variety
        $query = "I need to complete an outfit with {$productName}. "
               . "Show me exactly {$limit} products, one from each of these categories: {$slotList}. "
               . "Each product must be from a DIFFERENT category.";

        try {
            $response = $this->askShoppingAgent($query, null, [
                'num_result_events' => 1,
                'num_results_per_event' => $limit,
            ]);

            return $response['products'] ?? [];
        } catch (\Exception $e) {
            Log::warning('ConstructorAgentService::searchComplementaryProducts failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get complementary category slots based on product type.
     *
     * Returns an array of category names that would typically complement
     * or complete an outfit with the given product type. Used by
     * searchComplementaryProducts() to request diverse product recommendations.
     *
     * For example, if the product is "pants", returns ['shirt', 'jacket', 'shoes', 'belt']
     * to help build a complete outfit.
     *
     * @param  string|null  $category  The product's category (matched case-insensitively)
     * @return array<int, string>  Array of 4 complementary category names
     */
    protected function getComplementaryCategorySlots(?string $category): array
    {
        if (! $category) {
            return ['shirt', 'pants', 'shoes', 'belt'];
        }

        $category = strtolower($category);

        // Map product categories to complementary slots
        $slotMappings = [
            'pants' => ['shirt', 'jacket', 'shoes', 'belt'],
            'jeans' => ['shirt', 'jacket', 'shoes', 'belt'],
            'chino' => ['polo shirt', 'blazer', 'loafers', 'belt'],
            'shorts' => ['polo shirt', 'sneakers', 'hat', 'sunglasses'],
            'shirt' => ['pants', 'jacket', 'shoes', 'belt'],
            'polo' => ['chinos', 'jacket', 'loafers', 'belt'],
            'top' => ['pants', 'cardigan', 'shoes', 'necklace'],
            'blouse' => ['skirt', 'blazer', 'heels', 'earrings'],
            'sweater' => ['pants', 'shirt', 'boots', 'scarf'],
            'jacket' => ['shirt', 'pants', 'shoes', 'scarf'],
            'blazer' => ['dress shirt', 'dress pants', 'oxford shoes', 'tie'],
            'coat' => ['sweater', 'pants', 'boots', 'gloves'],
            'dress' => ['cardigan', 'heels', 'clutch', 'earrings'],
            'skirt' => ['blouse', 'cardigan', 'flats', 'belt'],
            'shoes' => ['pants', 'shirt', 'belt', 'watch'],
            'boots' => ['jeans', 'sweater', 'jacket', 'scarf'],
        ];

        // Find matching slots
        foreach ($slotMappings as $key => $slots) {
            if (str_contains($category, $key)) {
                return $slots;
            }
        }

        // Default complementary slots
        return ['shirt', 'pants', 'shoes', 'accessories'];
    }
}
