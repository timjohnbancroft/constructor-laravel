<?php

namespace ConstructorIO\Laravel\Services\Search;

use ConstructorIO\Laravel\Contracts\SandboxSearchContract;
use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Constructor.io implementation of the SandboxSearchContract.
 *
 * This service provides search functionality using Constructor.io as the backend.
 * It makes direct HTTP calls to Constructor's Search API (ac.cnstrc.com).
 *
 * NOTE: This class requires a configuration object with the following properties:
 * - api_key: Your Constructor.io API key (required)
 * - api_token: Your Constructor.io API token (optional, for authenticated endpoints)
 *
 * You can adapt the constructor to accept these values directly or via config.
 */
class ConstructorSandboxSearch implements SandboxSearchContract
{
    protected string $apiKey;

    protected ?string $apiToken;

    protected string $baseUrl;

    protected int $timeout;

    /**
     * Create a new ConstructorSandboxSearch instance.
     *
     * @param string $apiKey Your Constructor.io API key
     * @param string|null $apiToken Your Constructor.io API token (for authenticated endpoints)
     */
    public function __construct(string $apiKey, ?string $apiToken = null)
    {
        $this->apiKey = $apiKey;
        $this->apiToken = $apiToken;
        $this->baseUrl = config('constructor.search_base_url', 'https://ac.cnstrc.com');
        $this->timeout = config('constructor.timeout', 30);
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query, array $filters = [], array $options = []): SearchResults
    {
        try {
            // Build the search endpoint URL
            $searchQuery = empty($query) ? '*' : $query;
            $endpoint = "/search/{$searchQuery}";

            // Build query parameters
            $params = $this->buildSearchParams($filters, $options);

            // Make the request
            $response = $this->makeRequest($endpoint, $params);

            // Transform the response
            return $this->transformSearchResponse($response, $options);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::search error: '.$e->getMessage());

            return SearchResults::empty();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        try {
            // Build the browse endpoint URL with proper encoding
            // Constructor stores category IDs (group_id) as URL-encoded strings
            // (e.g., "Condiments%2C%20Spice%20%26%20Bake"), so they need double-encoding.
            // Other facet values (Brand, etc.) are stored as plain text, so single-encode.
            if ($filterName === 'group_id') {
                // Double-encode for group_id to handle special characters in category names
                $encodedFilterValue = rawurlencode(rawurlencode($filterValue));
            } else {
                // Single-encode for facet values
                $encodedFilterValue = rawurlencode($filterValue);
            }
            $endpoint = "/browse/{$filterName}/{$encodedFilterValue}";

            // Build query parameters
            $params = $this->buildSearchParams($filters, $options);

            // Debug logging
            Log::info('ConstructorSandboxSearch::browse', [
                'filterName' => $filterName,
                'filterValue' => $filterValue,
                'additionalFilters' => $filters,
                'options' => $options,
                'endpoint' => $endpoint,
                'params' => $params,
            ]);

            // Make the request
            $response = $this->makeRequest($endpoint, $params);

            // Log response summary
            Log::info('ConstructorSandboxSearch::browse response', [
                'total_results' => $response['response']['total_num_results'] ?? 0,
                'facets_count' => count($response['response']['facets'] ?? []),
            ]);

            // Transform the response
            return $this->transformSearchResponse($response, $options);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::browse error: '.$e->getMessage());

            return SearchResults::empty();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function autocomplete(string $query, array $options = []): AutocompleteResults
    {
        try {
            $endpoint = "/autocomplete/{$query}";

            $params = [
                'key' => $this->apiKey,
            ];

            // Build section-specific result limits
            // Constructor API expects format: num_results_<Section Name>=<limit>
            $sections = $options['sections'] ?? [];

            // Suggestions section
            $suggestionsConfig = $sections['suggestions'] ?? ['enabled' => true, 'limit' => 5];
            if ($suggestionsConfig['enabled'] ?? true) {
                $params['num_results_Search Suggestions'] = $suggestionsConfig['limit'] ?? 5;
            }

            // Products section
            $productsConfig = $sections['products'] ?? ['enabled' => true, 'limit' => 6];
            if ($productsConfig['enabled'] ?? true) {
                $params['num_results_Products'] = $productsConfig['limit'] ?? 6;
            }

            // Add section if specified (for filtering to a single section)
            if (isset($options['section'])) {
                $params['section'] = $options['section'];
            }

            $response = $this->makeRequest($endpoint, $params);

            return $this->transformAutocompleteResponse($response, $options);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::autocomplete error: ' . $e->getMessage());

            return AutocompleteResults::empty();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getZeroStateData(array $options = []): AutocompleteResults
    {
        try {
            $topCategories = [];
            $popularProducts = [];

            $showTopCategories = $options['show_top_categories'] ?? true;
            $showPopularProducts = $options['show_popular_products'] ?? true;

            // Get top categories for left column
            if ($showTopCategories) {
                $categoriesLimit = $options['categories_limit'] ?? 5;
                $topCategories = $this->getBrowseGroups([
                    'max_items' => $categoriesLimit,
                    'max_children' => 0,
                ]);
            }

            // Get popular products from configured recommendation pod
            $podId = trim($options['recommendation_pod_id'] ?? '');
            if ($showPopularProducts && !empty($podId)) {
                $productsLimit = $options['products_limit'] ?? 6;

                // Parse extra params from JSON string
                $extraParams = [];
                $paramsJson = $options['recommendation_pod_params'] ?? '';
                if (!empty($paramsJson) && is_string($paramsJson)) {
                    $extraParams = json_decode($paramsJson, true) ?? [];
                }

                $popularProducts = $this->getPopularProductsFromPod($podId, $productsLimit, $extraParams);
            }

            return AutocompleteResults::zeroState(
                trending: [], // Trending not supported - using categories instead
                popularProducts: $popularProducts,
                topCategories: $topCategories,
            );
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::getZeroStateData error: ' . $e->getMessage());

            return AutocompleteResults::empty();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supportsZeroState(): bool
    {
        return true;
    }

    /**
     * Get trending/popular search terms.
     *
     * Uses Constructor's autocomplete with a space or common character to get popular searches.
     */
    protected function getTrendingSearches(int $limit = 5): array
    {
        try {
            // Constructor returns popular searches when given minimal input
            // Using a space or common letter can trigger popular results
            $endpoint = '/autocomplete/ ';

            $params = [
                'key' => $this->apiKey,
                'num_results_per_section' => json_encode([
                    'Search Suggestions' => $limit,
                ]),
            ];

            $response = $this->makeRequest($endpoint, $params);

            $trending = [];
            if (isset($response['sections']['Search Suggestions'])) {
                foreach ($response['sections']['Search Suggestions'] as $suggestion) {
                    $trending[] = [
                        'term' => $suggestion['value'] ?? '',
                        'data' => $suggestion['data'] ?? [],
                    ];
                }
            }

            return array_slice($trending, 0, $limit);
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getTrendingSearches error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Get popular products from a specific recommendation pod.
     *
     * @param  string  $podId  The recommendation pod ID (e.g., 'hp-bestsellers')
     * @param  int  $limit  Maximum number of products to return
     * @param  array  $extraParams  Additional parameters for the recommendation strategy (e.g., filters, term, item_id)
     * @return array Array of transformed product data
     */
    protected function getPopularProductsFromPod(string $podId, int $limit = 6, array $extraParams = []): array
    {
        try {
            $endpoint = "/recommendations/v1/pods/{$podId}";

            $params = [
                'key' => $this->apiKey,
                'num_results' => $limit,
            ];

            // Merge extra params (filters, term, item_id, etc.)
            // Note: filters should be passed as nested array, not JSON encoded
            // Laravel's Http client will convert filters[key]=value format automatically
            foreach ($extraParams as $key => $value) {
                $params[$key] = $value;
            }

            $response = $this->makeRequest($endpoint, $params);

            $products = [];
            if (isset($response['response']['results'])) {
                foreach ($response['response']['results'] as $result) {
                    $products[] = $this->transformProduct($result);
                }
            }

            return array_slice($products, 0, $limit);
        } catch (\Exception $e) {
            // Pod doesn't exist or other error - return empty silently
            Log::debug("ConstructorSandboxSearch::getPopularProductsFromPod - Pod '{$podId}' not available: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Get popular products for zero-state display.
     *
     * @deprecated Use getPopularProductsFromPod() instead
     */
    protected function getPopularProducts(int $limit = 6): array
    {
        try {
            // Fallback: Browse all products sorted by popularity or relevance
            $endpoint = '/browse/items';

            $params = [
                'key' => $this->apiKey,
                'section' => 'Products',
                'num_results_per_page' => $limit,
            ];

            $response = $this->makeRequest($endpoint, $params);

            $products = [];
            if (isset($response['response']['results'])) {
                foreach ($response['response']['results'] as $result) {
                    $products[] = $this->transformProduct($result);
                }
            }

            return array_slice($products, 0, $limit);
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getPopularProducts error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFacets(string $query, array $filters = []): array
    {
        // Constructor returns facets with search results, so we just do a search
        $results = $this->search($query, $filters, ['per_page' => 1]);

        return $results->facets;
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return 'Constructor';
    }

    /**
     * Build query parameters for search/browse requests.
     *
     * Constructs the query parameters array that will be sent to Constructor's
     * search or browse API endpoints. Handles pagination, sorting, filters,
     * and user context.
     *
     * @param  array  $filters  Facet filters as [facet_name => [values]] or [facet_name => value]
     * @param  array  $options  Request options including:
     *   - page: int - Page number (1-indexed, default: 1)
     *   - per_page: int - Results per page (default: 24)
     *   - section: string - Constructor section (default: 'Products')
     *   - sort_by: string - Field to sort by
     *   - sort_order: string - 'ascending' or 'descending'
     *   - range_filters: array - Range filters as [facet => ['min' => x, 'max' => y]]
     *   - user_id: string - User identifier for personalization
     *   - session_id: string - Session identifier for analytics
     * @return array  Query parameters ready for HTTP request
     */
    protected function buildSearchParams(array $filters, array $options): array
    {
        $params = [
            'key' => $this->apiKey,
            'page' => $options['page'] ?? 1,
            'num_results_per_page' => $options['per_page'] ?? 24,
        ];

        // Add section (usually 'Products')
        $params['section'] = $options['section'] ?? 'Products';

        // Add sorting
        if (isset($options['sort_by'])) {
            $params['sort_by'] = $options['sort_by'];
            $params['sort_order'] = $options['sort_order'] ?? 'descending';
        }

        // Build filters expression
        $filterExpression = $this->buildFilterExpression($filters, $options);
        if (! empty($filterExpression)) {
            // Constructor browse API requires filters to be passed in the 'qs' parameter
            // Format: qs={"filters":{"Brand":["Nike"]}}
            $params['qs'] = ['filters' => $filterExpression];
        }

        // Add user context if available
        if (isset($options['user_id'])) {
            $params['ui'] = $options['user_id'];
        }
        if (isset($options['session_id'])) {
            $params['s'] = $options['session_id'];
        }

        return $params;
    }

    /**
     * Build Constructor filter expression from filters array.
     *
     * Constructor expects filters as a dictionary/object, not a JSON string.
     * Returns array structure that will be JSON-encoded when building the query.
     *
     * Supports two filter types:
     * 1. Standard filters: [facet_name => [value1, value2]] - Products matching ANY value
     * 2. Range filters: [facet_name => ['min' => x, 'max' => y]] - Products within range
     *
     * @param  array  $filters  Standard facet filters
     * @param  array  $options  Options containing 'range_filters' for numeric ranges
     * @return array|null  Filter expression object or null if no filters
     *
     * @example
     * // Standard filters
     * buildFilterExpression(['brand' => ['Nike', 'Adidas']], [])
     * // Returns: ['brand' => ['Nike', 'Adidas']]
     *
     * // With range filter
     * buildFilterExpression([], ['range_filters' => ['price' => ['min' => 50, 'max' => 100]]])
     * // Returns: ['price' => ['min' => 50, 'max' => 100]]
     */
    protected function buildFilterExpression(array $filters, array $options): ?array
    {
        if (empty($filters) && empty($options['range_filters'])) {
            return null;
        }

        $filterObject = [];

        // Add standard filters (facet_name => [values])
        foreach ($filters as $facetKey => $values) {
            if (empty($values)) {
                continue;
            }

            $values = is_array($values) ? $values : [$values];
            $filterObject[$facetKey] = $values;
        }

        // Add range filters (facet_name => {min: x, max: y})
        if (isset($options['range_filters'])) {
            foreach ($options['range_filters'] as $facetKey => $range) {
                $rangeValue = [];

                if (isset($range['min']) && $range['min'] !== null) {
                    $rangeValue['min'] = $range['min'];
                }
                if (isset($range['max']) && $range['max'] !== null) {
                    $rangeValue['max'] = $range['max'];
                }

                if (!empty($rangeValue)) {
                    $filterObject[$facetKey] = $rangeValue;
                }
            }
        }

        if (empty($filterObject)) {
            return null;
        }

        return $filterObject;
    }

    /**
     * Make an HTTP request to Constructor API.
     *
     * Handles URL construction, parameter encoding (JSON for complex objects),
     * timeout/retry logic, and error handling.
     *
     * @param  string  $endpoint  API endpoint path (e.g., '/search/shoes')
     * @param  array  $params  Query parameters including 'key' for API key
     * @return array  Decoded JSON response
     *
     * @throws \Exception  On HTTP errors (4xx/5xx status codes)
     */
    protected function makeRequest(string $endpoint, array $params): array
    {
        $url = $this->baseUrl.$endpoint;

        // Convert complex parameters to JSON strings for Constructor API
        // The 'qs' parameter expects a JSON-encoded object with filters, sort options, etc.
        $queryParams = [];
        foreach ($params as $key => $value) {
            if ($key === 'qs' && is_array($value)) {
                // JSON-encode the qs object (contains filters, sort, etc.)
                $queryParams[$key] = json_encode($value);
            } elseif ($key === 'filters' && is_array($value)) {
                // Fallback: JSON-encode filters directly if used
                $queryParams[$key] = json_encode($value);
            } else {
                $queryParams[$key] = $value;
            }
        }

        Log::debug('Constructor API request', [
            'url' => $url,
            'params' => $queryParams,
        ]);

        // Use Laravel HTTP client with query params - it handles URL encoding properly
        $response = Http::timeout($this->timeout)
            ->retry(
                config('constructor.retry_times', 2),
                config('constructor.retry_sleep', 100)
            )
            ->get($url, $queryParams);

        if ($response->failed()) {
            Log::error('Constructor API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url,
                'params' => $queryParams,
            ]);
            throw new \Exception("Constructor API error: {$response->status()} - {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Transform Constructor search response to SearchResults DTO.
     *
     * Extracts products, facets, groups, and metadata from Constructor's
     * response format and normalizes them into the SearchResults DTO.
     *
     * @param  array  $response  Raw API response from Constructor
     * @param  array  $options  Original request options (for page/perPage values)
     * @return SearchResults  Normalized search results
     */
    protected function transformSearchResponse(array $response, array $options): SearchResults
    {
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 24;

        // Extract products from response
        $products = [];
        $responseData = $response['response'] ?? $response;

        if (isset($responseData['results'])) {
            foreach ($responseData['results'] as $result) {
                $products[] = $this->transformProduct($result);
            }
        }

        // Extract total count
        $total = $responseData['total_num_results'] ?? count($products);

        // Extract facets
        $facets = $this->transformFacets($responseData['facets'] ?? []);
        // Extract groups (for browse responses)
        $groups = $this->transformGroups($responseData['groups'] ?? []);

        return new SearchResults(
            products: $products,
            total: $total,
            page: $page,
            perPage: $perPage,
            facets: $facets,
            metadata: [
                'request_id' => $response['request']['request_id'] ?? null,
                'result_id' => $responseData['result_id'] ?? null,
            ],
            groups: $groups
        );
    }

    /**
     * Transform a Constructor product result to a standard format.
     *
     * Normalizes Constructor's product structure (with nested 'data' object)
     * into a flat, consistent format used throughout the application.
     *
     * Constructor returns products as:
     * { value: "Product Name", data: { id, url, price, image_url, ... } }
     *
     * This transforms to:
     * { id, name, description, url, image_url, price, brand, categories, _raw }
     *
     * @param  array  $result  Single product from Constructor response
     * @return array  Normalized product data with '_raw' containing original data
     */
    protected function transformProduct(array $result): array
    {
        $data = $result['data'] ?? [];

        return [
            'id' => $result['data']['id'] ?? $result['value'] ?? null,
            'name' => $result['value'] ?? $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'url' => $data['url'] ?? '',
            'image_url' => $data['image_url'] ?? null,
            'price' => $data['price'] ?? null,
            'original_price' => $data['original_price'] ?? null,
            'sku' => $data['sku'] ?? null,
            'brand' => $data['brand'] ?? null,
            'categories' => $data['categories'] ?? [],
            'facets' => $data['facets'] ?? [],
            'metadata' => $data['metadata'] ?? [],
            // Include raw data for any additional fields
            '_raw' => $data,
        ];
    }

    /**
     * Transform Constructor facets to standard format.
     *
     * Converts Constructor's facet structure into a consistent format for the UI:
     * - Generates display names from facet keys (snake_case to Title Case)
     * - Maps Constructor facet types to UI types (single/range/checkbox_list)
     * - Extracts option values with their counts
     *
     * @param  array  $facets  Facets array from Constructor response
     * @return array<string, array{name: string, values: array, type: string}>  Normalized facets keyed by facet name
     */
    protected function transformFacets(array $facets): array
    {
        $formatted = [];

        foreach ($facets as $facet) {
            $name = $facet['name'] ?? '';
            $displayName = $facet['display_name'] ?? ucwords(str_replace(['_', '-'], ' ', $name));
            $type = $facet['type'] ?? 'multiple';

            // Map Constructor facet types to our types
            $facetType = match ($type) {
                'single' => 'single_select',
                'range' => 'range',
                default => 'checkbox_list',
            };

            // Build values array
            $values = [];
            if (isset($facet['options'])) {
                foreach ($facet['options'] as $option) {
                    $optionValue = $option['value'] ?? '';
                    $optionCount = $option['count'] ?? 0;
                    $values[$optionValue] = $optionCount;
                }
            }

            if (! empty($values)) {
                $formatted[$name] = [
                    'name' => $displayName,
                    'values' => $values,
                    'type' => $facetType,
                ];

                // Add range info if available
                if ($facetType === 'range' && isset($facet['min'], $facet['max'])) {
                    $formatted[$name]['min'] = $facet['min'];
                    $formatted[$name]['max'] = $facet['max'];
                }
            }
        }

        return $formatted;
    }

    /**
     * Transform Constructor groups to standard format.
     *
     * Converts Constructor's category group hierarchy into a flat array
     * with nested children, suitable for navigation menus and breadcrumbs.
     *
     * @param  array  $groups  Groups array from Constructor browse response
     * @return array<int, array{group_id: string, id: string, name: string, children: array}>  Normalized groups
     */
    protected function transformGroups(array $groups): array
    {
        $result = [];

        foreach ($groups as $group) {
            $groupId = $group['group_id'] ?? $group['id'] ?? '';
            $displayName = $group['display_name'] ?? $group['name'] ?? '';

            $children = [];
            if (!empty($group['children'])) {
                foreach ($group['children'] as $child) {
                    $children[] = [
                        'id' => $child['group_id'] ?? $child['id'] ?? '',
                        'name' => $child['display_name'] ?? $child['name'] ?? '',
                        'has_children' => !empty($child['children']),
                    ];
                }
            }

            $result[] = [
                'group_id' => $groupId,
                'id' => $groupId,
                'name' => $displayName,
                'children' => $children,
            ];
        }

        return $result;
    }

    /**
     * Transform Constructor autocomplete response to AutocompleteResults DTO.
     *
     * Extracts search suggestions, product previews, and category suggestions
     * from Constructor's autocomplete response. Handles different section names
     * that Constructor may use for categories (Categories, Groups, group_ids).
     *
     * @param  array  $response  Raw autocomplete response from Constructor
     * @param  array  $options  Options including 'include_categories_from_products' flag
     * @return AutocompleteResults  Normalized autocomplete results
     */
    protected function transformAutocompleteResponse(array $response, array $options = []): AutocompleteResults
    {
        $suggestions = [];
        $products = [];
        $categories = [];

        // Extract search suggestions with matched terms for highlighting
        if (isset($response['sections']['Search Suggestions'])) {
            foreach ($response['sections']['Search Suggestions'] as $suggestion) {
                $suggestions[] = [
                    'term' => $suggestion['value'] ?? '',
                    'matched_terms' => $suggestion['matched_terms'] ?? [],
                    'data' => $suggestion['data'] ?? [],
                ];
            }
        }

        // Extract product suggestions
        if (isset($response['sections']['Products'])) {
            foreach ($response['sections']['Products'] as $result) {
                $products[] = $this->transformProduct($result);
            }
        }

        // Extract categories from groups if available (Constructor can return these)
        // Check for various possible section names for categories
        $categorySectionNames = ['Categories', 'Groups', 'group_ids'];
        foreach ($categorySectionNames as $sectionName) {
            if (isset($response['sections'][$sectionName])) {
                foreach ($response['sections'][$sectionName] as $category) {
                    $categories[] = [
                        'id' => $category['data']['id'] ?? $category['value'] ?? '',
                        'name' => $category['value'] ?? $category['data']['name'] ?? '',
                        'path' => $category['data']['path'] ?? null,
                        'count' => $category['data']['count'] ?? null,
                    ];
                }
                break; // Use the first matching section
            }
        }

        // If categories not in sections, try to extract from product groups
        if (empty($categories) && ($options['include_categories_from_products'] ?? false)) {
            $categories = $this->extractCategoriesFromProducts($products);
        }

        return new AutocompleteResults(
            suggestions: $suggestions,
            products: $products,
            categories: $categories,
            metadata: [
                'request_id' => $response['request']['request_id'] ?? null,
                'result_id' => $response['result_id'] ?? null,
            ],
        );
    }

    /**
     * Extract unique categories from product results.
     *
     * Used as a fallback when Constructor doesn't return a categories section.
     */
    protected function extractCategoriesFromProducts(array $products, int $limit = 5): array
    {
        $categoryMap = [];

        foreach ($products as $product) {
            $groups = $product['_raw']['groups'] ?? [];
            foreach ($groups as $group) {
                $groupId = $group['group_id'] ?? '';
                if (! empty($groupId) && ! isset($categoryMap[$groupId])) {
                    $categoryMap[$groupId] = [
                        'id' => $groupId,
                        'name' => $group['display_name'] ?? $groupId,
                        'path' => $group['path'] ?? null,
                        'count' => null,
                    ];
                }
            }
        }

        return array_slice(array_values($categoryMap), 0, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function getRecommendations(string $podId, array $options = []): RecommendationResults
    {
        try {
            $endpoint = "/recommendations/v1/pods/{$podId}";

            $params = [
                'key' => $this->apiKey,
                'num_results' => $options['num_results'] ?? 8,
            ];

            // Add user identifier - required by some recommendation pods
            // Uses session ID or generates a random one for anonymous users
            $params['ui'] = $options['user_id'] ?? session()->getId() ?? 'anonymous-' . substr(md5(request()->ip() ?? 'default'), 0, 16);

            // Add section if specified
            if (isset($options['section'])) {
                $params['section'] = $options['section'];
            }

            $response = $this->makeRequest($endpoint, $params);

            return $this->transformRecommendationResponse($podId, $response);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::getRecommendations error: ' . $e->getMessage(), [
                'pod_id' => $podId,
            ]);

            return RecommendationResults::empty($podId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getItemRecommendations(string $podId, string $itemId, array $options = []): RecommendationResults
    {
        try {
            $endpoint = "/recommendations/v1/pods/{$podId}";

            $params = [
                'key' => $this->apiKey,
                'item_id' => $itemId,
                'num_results' => $options['num_results'] ?? 8,
            ];

            // Add section if specified
            if (isset($options['section'])) {
                $params['section'] = $options['section'];
            }

            $response = $this->makeRequest($endpoint, $params);

            return $this->transformRecommendationResponse($podId, $response);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::getItemRecommendations error: ' . $e->getMessage(), [
                'pod_id' => $podId,
                'item_id' => $itemId,
            ]);

            return RecommendationResults::empty($podId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supportsRecommendations(): bool
    {
        return true;
    }

    /**
     * Transform Constructor recommendation response to RecommendationResults DTO.
     *
     * Extracts pod metadata (ID, display name) and recommended products
     * from Constructor's recommendation response.
     *
     * @param  string  $podId  The recommendation pod ID that was requested
     * @param  array  $response  Raw recommendation response from Constructor
     * @return RecommendationResults  Normalized recommendation results
     */
    protected function transformRecommendationResponse(string $podId, array $response): RecommendationResults
    {
        $pod = $response['pod'] ?? [];
        $title = $pod['display_name'] ?? '';

        $products = [];
        if (isset($response['response']['results'])) {
            foreach ($response['response']['results'] as $result) {
                $products[] = $this->transformProduct($result);
            }
        }

        $total = $response['response']['total_num_results'] ?? count($products);

        return new RecommendationResults(
            podId: $podId,
            title: $title,
            products: $products,
            total: $total,
            metadata: [
                'request_id' => $response['request']['request_id'] ?? null,
                'pod' => $pod,
            ],
        );
    }

    /**
     * {@inheritdoc}
     *
     * Returns hierarchical groups with children for navigation menus.
     * Format: [{ id, name, count, image, children: [{ id, name, count }, ...] }, ...]
     *
     * @param array $options Options including:
     *   - max_items: Maximum number of groups to return (default: 10)
     *   - max_children: Maximum children per group (default: 5)
     *   - with_images: Whether to fetch product images for categories without images (default: false)
     */
    public function getBrowseGroups(array $options = []): array
    {
        try {
            // Use Constructor's dedicated browse/groups endpoint
            $endpoint = '/browse/groups';
            $params = [
                'key' => $this->apiKey,
                'section' => $options['section'] ?? 'Products',
            ];

            // Add filters if specified
            if (isset($options['filters'])) {
                $params['filters'] = json_encode($options['filters']);
            }

            $response = $this->makeRequest($endpoint, $params);

            // Transform the response to our standard format
            $groups = [];
            $maxItems = $options['max_items'] ?? 10;
            $maxChildren = $options['max_children'] ?? 5;
            $withImages = $options['with_images'] ?? false;
            $count = 0;

            // The response contains groups in response.groups array
            // The first group is typically "All" with children being the actual categories
            $responseGroups = $response['response']['groups'] ?? $response['groups'] ?? [];

            // If there's a root group with children, use the children as categories
            if (count($responseGroups) === 1 && !empty($responseGroups[0]['children'])) {
                $responseGroups = $responseGroups[0]['children'];
            }

            foreach ($responseGroups as $group) {
                if ($count >= $maxItems) {
                    break;
                }

                $groupId = $group['group_id'] ?? $group['id'] ?? '';

                // Extract image from data object if available
                $image = null;
                if (isset($group['data']) && is_array($group['data'])) {
                    $image = $group['data']['image_url'] ?? null;
                }

                // If no image from API and with_images is requested, fetch from a product in this category
                if ($image === null && $withImages && !empty($groupId)) {
                    $image = $this->getFirstProductImageForGroup($groupId);
                }

                // Transform children if present
                $children = [];
                if (!empty($group['children']) && is_array($group['children'])) {
                    $childCount = 0;
                    foreach ($group['children'] as $child) {
                        if ($childCount >= $maxChildren) {
                            break;
                        }
                        $children[] = [
                            'id' => $child['group_id'] ?? $child['id'] ?? '',
                            'name' => $child['display_name'] ?? $child['name'] ?? '',
                            'count' => $child['count'] ?? 0,
                        ];
                        $childCount++;
                    }
                }

                $groups[] = [
                    'id' => $groupId,
                    'name' => $group['display_name'] ?? $group['name'] ?? '',
                    'count' => $group['count'] ?? 0,
                    'image' => $image,
                    'children' => $children,
                ];
                $count++;
            }

            return $groups;
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::getBrowseGroups error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the first product image for a given group/category from Constructor.
     * Searches for products in the category and returns the first image found.
     */
    protected function getFirstProductImageForGroup(string $groupId): ?string
    {
        try {
            // Decode the group ID in case it comes URL-encoded from browse/groups
            $decodedGroupId = urldecode($groupId);

            // Use the browse method which handles double-encoding for special characters
            $results = $this->browse('group_id', $decodedGroupId, [], ['per_page' => 5]);

            // Look through results for a product with an image
            foreach ($results->products as $product) {
                $imageUrl = $product['image_url'] ?? null;
                if (!empty($imageUrl)) {
                    return $imageUrl;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getFirstProductImageForGroup error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCollections(array $options = []): array
    {
        try {
            $maxItems = $options['max_items'] ?? 10;

            // If API token is available, try the admin collections endpoint first
            if (!empty($this->apiToken)) {
                try {
                    $collections = $this->getCollectionsFromAdminApi($maxItems);
                    if (!empty($collections)) {
                        return $collections;
                    }
                } catch (\Exception $e) {
                    Log::warning('ConstructorSandboxSearch::getCollections admin API failed, falling back to facets: ' . $e->getMessage());
                }
            }

            // Fall back to extracting collections from search facets
            return $this->getCollectionsFromFacets($maxItems, $options);
        } catch (\Exception $e) {
            Log::error('ConstructorSandboxSearch::getCollections error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get collections from Constructor's admin API.
     * Requires API token authentication.
     */
    protected function getCollectionsFromAdminApi(int $maxItems): array
    {
        $endpoint = '/v1/collections';
        $params = [
            'key' => $this->apiKey,
            'num_results' => $maxItems,
        ];

        // Add authorization header for admin API
        $url = $this->baseUrl . $endpoint;
        $response = Http::timeout($this->timeout)
            ->withBasicAuth($this->apiToken, '')
            ->get($url, $params);

        if ($response->failed()) {
            throw new \Exception("Constructor admin API error: {$response->status()}");
        }

        $data = $response->json();
        $collections = [];
        $items = $data['collections'] ?? $data['response']['collections'] ?? [];

        foreach ($items as $collection) {
            $collections[] = [
                'id' => $collection['id'] ?? '',
                'name' => $collection['display_name'] ?? $collection['name'] ?? '',
                'description' => $collection['description'] ?? null,
                'image' => $collection['image_url'] ?? null,
            ];
        }

        return $collections;
    }

    /**
     * Get collections from search facets.
     * Uses collection_id or similar facets from search results.
     */
    protected function getCollectionsFromFacets(int $maxItems, array $options): array
    {
        // Common facet names for collections in Constructor
        $collectionFacetNames = $options['facet_names'] ?? ['collection_id', 'collection_ids', 'collections', 'collection'];

        // Make a search request with minimal results to get facets
        $endpoint = '/search/*';
        $params = [
            'key' => $this->apiKey,
            'num_results_per_page' => 1,
            'section' => 'Products',
        ];

        $response = $this->makeRequest($endpoint, $params);

        // Search for collection facet in the response
        $facets = $response['response']['facets'] ?? [];

        foreach ($facets as $facet) {
            $facetName = $facet['name'] ?? '';
            if (in_array($facetName, $collectionFacetNames)) {
                $collections = [];
                $count = 0;

                foreach ($facet['options'] ?? [] as $option) {
                    if ($count >= $maxItems) {
                        break;
                    }

                    $collections[] = [
                        'id' => $option['value'] ?? '',
                        'name' => $option['display_name'] ?? $option['value'] ?? '',
                        'description' => null,
                        'image' => null,
                        'count' => $option['count'] ?? 0,
                    ];
                    $count++;
                }

                return $collections;
            }
        }

        // Log available facets for debugging
        $availableFacets = array_map(fn($f) => $f['name'] ?? 'unknown', $facets);
        Log::info('ConstructorSandboxSearch::getCollectionsFromFacets - no collection facet found', [
            'searched_facet_names' => $collectionFacetNames,
            'available_facets' => $availableFacets,
        ]);

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsBrowseGroups(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsCollections(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Browse products within a Constructor collection.
     * Tries the authenticated /v1/collections/{id}/items endpoint first,
     * falls back to /browse/collection_id/{id} if auth fails or token is missing.
     */
    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults
    {
        // If we have an API token, try the authenticated collections API first
        if (!empty($this->apiToken)) {
            $result = $this->browseCollectionAuthenticated($collectionId, $filters, $options);
            if ($result->total > 0) {
                return $result;
            }
        }

        // Fallback: Use the standard browse endpoint with collection_id filter
        // This works without authentication and is the most reliable method
        return $this->browse('collection_id', $collectionId, $filters, $options);
    }

    /**
     * Browse collection using the authenticated /v1/collections/{id}/items endpoint.
     */
    protected function browseCollectionAuthenticated(string $collectionId, array $filters, array $options): SearchResults
    {
        try {
            $page = $options['page'] ?? 1;
            $perPage = $options['per_page'] ?? 24;

            $endpoint = "/v1/collections/{$collectionId}/items";
            $params = [
                'key' => $this->apiKey,
                'section' => $options['section'] ?? 'Products',
                'num_results_per_page' => $perPage,
                'page' => $page,
            ];

            // Add filters if specified
            if (!empty($filters)) {
                $params['filters'] = json_encode($filters);
            }

            // Collections API requires authentication
            $url = $this->baseUrl . $endpoint;
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->apiToken, '')
                ->get($url, $params);

            if ($response->failed()) {
                Log::warning('ConstructorSandboxSearch::browseCollectionAuthenticated API error, will fallback to browse', [
                    'collection_id' => $collectionId,
                    'status' => $response->status(),
                ]);
                return SearchResults::empty();
            }

            $responseData = $response->json();

            // Transform the response using same logic as search/browse
            return $this->transformSearchResponse($responseData, $options);
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::browseCollectionAuthenticated error: ' . $e->getMessage(), [
                'collection_id' => $collectionId,
            ]);
            return SearchResults::empty();
        }
    }

    /**
     * {@inheritdoc}
     *
     * Get a single collection's metadata from Constructor.
     * Tries the authenticated /v1/collections/{id} endpoint first,
     * returns a basic object derived from collection ID if auth fails.
     */
    public function getCollection(string $collectionId): ?array
    {
        // Try authenticated API if we have a token
        if (!empty($this->apiToken)) {
            $result = $this->getCollectionAuthenticated($collectionId);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: Return basic metadata derived from collection ID
        // This allows the page to display even without full API access
        return [
            'id' => $collectionId,
            'name' => ucwords(str_replace(['-', '_'], ' ', $collectionId)),
            'description' => null,
            'image' => null,
        ];
    }

    /**
     * Get collection metadata using the authenticated API.
     */
    protected function getCollectionAuthenticated(string $collectionId): ?array
    {
        try {
            $endpoint = "/v1/collections/{$collectionId}";
            $params = [
                'key' => $this->apiKey,
                'section' => 'Products',
            ];

            $url = $this->baseUrl . $endpoint;
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->apiToken, '')
                ->get($url, $params);

            if ($response->failed()) {
                if ($response->status() === 404) {
                    return null;
                }
                Log::warning('ConstructorSandboxSearch::getCollectionAuthenticated API error, will use fallback', [
                    'collection_id' => $collectionId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            // Extract collection data from response
            $collection = $data['collection'] ?? $data;

            return [
                'id' => $collection['id'] ?? $collectionId,
                'name' => $collection['display_name'] ?? $collection['name'] ?? '',
                'description' => $collection['description'] ?? null,
                'image' => $collection['image_url'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getCollectionAuthenticated error: ' . $e->getMessage(), [
                'collection_id' => $collectionId,
            ]);
            return null;
        }
    }

    /**
     * Get the first product image from a collection.
     *
     * Used as a fallback when no collection tile image is configured.
     */
    public function getFirstProductImageFromCollection(string $collectionId): ?string
    {
        try {
            // Browse the collection with just 1 result to get the first product
            $results = $this->browseCollection($collectionId, [], ['per_page' => 1]);

            if ($results->total > 0 && !empty($results->products)) {
                $product = $results->products[0];
                // Check for image_url property (common) or images array
                if (isset($product->image_url) && $product->image_url) {
                    return $product->image_url;
                }
                if (isset($product->images) && is_array($product->images) && !empty($product->images)) {
                    return $product->images[0];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getFirstProductImageFromCollection error: ' . $e->getMessage(), [
                'collection_id' => $collectionId,
            ]);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Get available facets from a browse request.
     * Uses the browse endpoint instead of search since Constructor doesn't support '*' wildcard.
     * Returns a list of all facets that can be used for "Shop by Facet" sections.
     */
    public function getAvailableFacets(): array
    {
        try {
            // Use browse endpoint instead of search - Constructor search doesn't support '*' wildcard
            // browse/group_id/all returns all products with facets
            $results = $this->browse('group_id', 'all', [], ['per_page' => 1]);

            $facets = [];
            foreach ($results->facets as $facetKey => $facetData) {
                // Skip range facets and group_ids (handled by categories)
                $facetType = $facetData['type'] ?? 'checkbox_list';
                if ($facetType === 'range' || $facetKey === 'group_ids' || $facetKey === 'group_id') {
                    continue;
                }

                $facets[] = [
                    'name' => $facetKey,
                    'display_name' => $facetData['name'] ?? ucwords(str_replace(['_', '-'], ' ', $facetKey)),
                    'type' => $facetType,
                ];
            }

            return $facets;
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getAvailableFacets error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Get facet values with sample product images.
     * Returns the top N facet values sorted by product count.
     */
    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10): array
    {
        try {
            // Use browse endpoint instead of search - Constructor search doesn't support '*' wildcard
            $results = $this->browse('group_id', 'all', [], ['per_page' => 1]);
            $facetData = $results->facets[$facetName] ?? null;

            if (!$facetData || empty($facetData['values'])) {
                return [];
            }

            // Get top N values by count (already sorted by count from API)
            $values = $facetData['values'];
            // Sort by count descending if not already
            arsort($values);
            $topValues = array_slice($values, 0, $maxItems, true);

            $result = [];
            foreach ($topValues as $value => $count) {
                // Get sample product image for this facet value
                $image = $this->getFirstProductImageForFacetValue($facetName, $value);

                $result[] = [
                    'value' => $value,
                    'display_name' => $value,
                    'count' => $count,
                    'image' => $image,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getFacetValuesWithImages error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get first product image for a facet value.
     *
     * Uses the browse endpoint to get products matching the facet value.
     */
    protected function getFirstProductImageForFacetValue(string $facetName, string $facetValue): ?string
    {
        try {
            // Use browse endpoint: /browse/{facet_name}/{facet_value}
            $results = $this->browse($facetName, $facetValue, [], ['per_page' => 1]);

            foreach ($results->products as $product) {
                // Products are returned as arrays from transformProduct
                $imageUrl = $product['image_url'] ?? null;
                if (!empty($imageUrl)) {
                    return $imageUrl;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug("ConstructorSandboxSearch::getFirstProductImageForFacetValue failed for {$facetName}={$facetValue}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Search recipes using the Recipes section.
     */
    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults
    {
        $options['section'] = 'Recipes';

        return $this->search($query, $filters, $options);
    }

    /**
     * {@inheritdoc}
     *
     * Browse recipes by category/facet.
     */
    public function browseRecipes(
        string $filterName,
        string $filterValue,
        array $filters = [],
        array $options = []
    ): SearchResults {
        $options['section'] = 'Recipes';

        return $this->browse($filterName, $filterValue, $filters, $options);
    }

    /**
     * {@inheritdoc}
     *
     * Get a single recipe by ID.
     */
    public function getRecipe(string $recipeId): ?array
    {
        try {
            // Constructor doesn't support filtering recipes by ID directly,
            // so we search for all recipes and filter client-side
            $results = $this->searchRecipes('recipe', [], ['per_page' => 100]);

            foreach ($results->products as $recipe) {
                if (($recipe['id'] ?? '') === $recipeId) {
                    return $recipe;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('ConstructorSandboxSearch::getRecipe error: ' . $e->getMessage(), [
                'recipe_id' => $recipeId,
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Check if the search provider supports recipes.
     */
    public function supportsRecipes(): bool
    {
        return true;
    }
}
