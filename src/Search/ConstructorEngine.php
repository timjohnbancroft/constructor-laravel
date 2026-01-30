<?php

namespace ConstructorIO\Laravel\Search;

use ConstructorIO\Laravel\Collections\SearchResultCollection;
use ConstructorIO\Laravel\Services\ConstructorService;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * Laravel Scout Engine for Constructor.io
 *
 * This engine integrates Constructor.io with Laravel Scout for
 * model indexing and searching.
 *
 * Usage:
 * 1. Register this engine in your AppServiceProvider
 * 2. Set SCOUT_DRIVER=constructor in your .env file
 * 3. Add the Searchable trait to your models
 *
 * Example registration in AppServiceProvider:
 * ```php
 * resolve(EngineManager::class)->extend('constructor', function () {
 *     return new ConstructorEngine(
 *         new ConstructorService(
 *             config('scout.constructor.api_key'),
 *             config('scout.constructor.api_token')
 *         )
 *     );
 * });
 * ```
 *
 * Model requirements:
 * - Implement getConstructorSection() to return the section name (e.g., 'Products')
 * - Implement toSearchableArray() to return the data to index
 */
class ConstructorEngine extends Engine
{
    /**
     * The Constructor.io service instance.
     */
    protected ConstructorService $constructorService;

    /**
     * Whether to include soft deleted models in results.
     */
    protected bool $softDelete;

    /**
     * Client identifier sent with requests for analytics.
     */
    protected string $clientId;

    /**
     * Constructor for the ConstructorEngine.
     *
     * @param  ConstructorService  $constructorService  The service to interact with Constructor.io
     */
    public function __construct(ConstructorService $constructorService, $softDelete = false)
    {
        // Store the ConstructorService instance for API interactions
        $this->constructorService = $constructorService;
        $this->softDelete = $softDelete;
        $this->clientId = config('scout.constructor.client_id', 'cio-laravel-'.config('app.version', '1.0'));

    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        //Check for empty models
        if ($models->isEmpty()) {
            return;
        }

        // Group models by their Constructor section to handle multi-section indexes
        $itemsBySection = $models->groupBy(function ($model) {
            return $model->getConstructorSection();
        });

        foreach ($itemsBySection as $section => $modelsInSection) {
            // Convert each model to a searchable array format
            $items = $modelsInSection->map(function ($model) {
                $item = $model->toSearchableArray();
                // Ensure 'id' is included
                if (! isset($item['id'])) {
                    $item['id'] = $model->getScoutKey();
                }

                return $item;
            })->toArray();

            try {
                // Send a PUT request to update items in Constructor.io
                $response = $this->constructorService->makeRequest('put', '/v2/items', [
                    'query' => [
                        'section' => $section,
                        'force' => config('scout.constructor.force', false),
                        'notification_email' => config('scout.constructor.notification_email'),
                        // Include 'on_missing' if needed
                        // 'on_missing' => 'FAIL',
                        // 'c' => $this->clientId, // Include client ID if required
                    ],
                    'json' => [
                        'items' => $items,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ], true); // Set $requiresAuth to true

                // Check the status of the update task
                // $this->checkTaskStatus($response['task_id'] ?? null);
            } catch (\Exception $e) {
                // Log any errors that occur during the update process
                Log::error("Failed to update Constructor.io index for section {$section}: ".$e->getMessage());
            }
        }
    }

    /**
     * Remove the given models from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $itemsBySection = $models->groupBy(function ($model) {
            return $model->getConstructorSection();
        });

        foreach ($itemsBySection as $section => $modelsInSection) {
            $items = $modelsInSection->map(function ($model) {
                return ['id' => $model->getScoutKey()];
            })->toArray();

            try {
                $response = $this->constructorService->makeRequest('delete', '/v2/items', [
                    'query' => [
                        'section' => $section,
                        'force' => config('scout.constructor.force', false),
                        'notification_email' => config('scout.constructor.notification_email'),
                    ],
                    'json' => [
                        'items' => $items,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ], true);

                // Check the status of the delete task if needed
                // $this->checkTaskStatus($response['task_id'] ?? null);
            } catch (\Exception $e) {
                Log::error("Failed to delete items from Constructor.io index for section {$section}: ".$e->getMessage());
            }
        }
    }

    public function search(Builder $builder)
    {
        $query = trim($builder->query ?? '');  // Trim and ensure we have a string
        $page = $builder->model->scoutMetadata()['page'] ?? 1;
        $perPage = min(max(1, $builder->model->scoutMetadata()['perPage'] ?? 10), 200);
        $section = $builder->model->getConstructorSection() ?? 'Products';

        try {
            $params = [
                'key' => $this->constructorService->apiKey,
                'c' => $this->clientId,
                'section' => $section,
                'num_results_per_page' => $perPage,
                'page' => $page,
            ];

            // Add sort parameters if they exist
            if (isset($builder->model->scoutMetadata()['sort_by'])) {
                $params['sort_by'] = $builder->model->scoutMetadata()['sort_by'];
            }
            if (isset($builder->model->scoutMetadata()['sort_order'])) {
                $params['sort_order'] = $builder->model->scoutMetadata()['sort_order'];
            }

            // Add filters if they exist
            $filters = $this->formatFilters($builder->wheres);
            if (! empty($filters)) {
                $params['filters'] = $filters;
            }

            // Add additional metadata parameters
            $metadata = $builder->model->scoutMetadata();
            foreach (['filter_match_types', 'pre_filter_expression', 'variations_map', 'fmt_options'] as $param) {
                if (isset($metadata[$param])) {
                    $params[$param] = $metadata[$param];
                }
            }

            // Debug log
            Log::debug('Constructor Search Params', [
                'params' => $params,
                'query' => $query,
                'endpoint' => empty($query) ? '/browse/items' : "/search/$query",
            ]);

            // If query is empty, use browse endpoint
            $endpoint = empty($query) ? '/browse/items' : "/search/$query";

            $response = $this->constructorService->makeRequest('get', $endpoint, [
                'query' => array_filter($params),
            ]);

            return $this->mapSearchResults($response['response'] ?? [], $builder->model);
        } catch (\Exception $e) {
            Log::error('Constructor search error: '.$e->getMessage(), [
                'query' => $query,
                'params' => $params ?? [],
                'endpoint' => empty($query) ? '/browse/items' : "/search/$query",
                'trace' => $e->getTraceAsString(),
            ]);

            if ($e->getCode() === 429) {
                Log::warning('Rate limit exceeded for Constructor.io search request. Retrying after delay.');
                sleep(1);

                return $this->search($builder);
            }

            return $this->getEmptySearchResults();
        }
    }

    /**
     * Format Scout where clauses into Constructor.io filter format.
     *
     * @param  array  $wheres  Array of where clauses from Scout Builder
     * @return array|null  Formatted filters or null if empty
     */
    protected function formatFilters(array $wheres): ?array
    {
        if (empty($wheres)) {
            return null;
        }

        $filters = [];
        foreach ($wheres as $where) {
            if (! isset($where['column']) || ! isset($where['value'])) {
                continue;
            }

            $key = $where['column'];
            $value = $where['value'];
            $operator = $where['operator'] ?? '=';

            // Initialize the array for this key if it doesn't exist
            if (! isset($filters[$key])) {
                $filters[$key] = [];
            }

            // Handle different types of values and operators
            if (is_array($value)) {
                $filters[$key] = array_merge($filters[$key], array_map('strval', $value));
            } elseif ($operator === '>' || $operator === '>=') {
                $filters[$key][] = sprintf('%s-inf', $value);
            } elseif ($operator === '<' || $operator === '<=') {
                $filters[$key][] = sprintf('-inf-%s', $value);
            } else {
                $filters[$key][] = strval($value);
            }
        }

        return $filters;
    }

    /**
     * Map Constructor.io search response to a SearchResultCollection.
     *
     * Extracts products, facets, total count, and sort options from the
     * Constructor response and populates the collection metadata.
     *
     * @param  array  $response  The 'response' portion of Constructor API response
     * @param  \Illuminate\Database\Eloquent\Model  $model  The model to hydrate results into
     * @return SearchResultCollection  Collection with metadata
     */
    protected function mapSearchResults(array $response, $model): SearchResultCollection
    {
        $results = collect($response['results'] ?? [])->map(function ($result) use ($model) {
            $data = array_merge(
                ['id' => $result['data']['id'] ?? null],
                $result['data'] ?? [],  // Merge all data fields
                ['name' => $result['value'] ?? null]  // Include the value field
            );

            return $model->newFromBuilder($data);
        });

        $collection = new SearchResultCollection($results);

        // Set total count from response
        $collection->setTotal($response['total_num_results'] ?? $results->count());

        // Transform and set facets
        if (!empty($response['facets'])) {
            $facets = [];
            foreach ($response['facets'] as $facet) {
                $name = $facet['name'] ?? '';
                $displayName = $facet['display_name'] ?? ucwords(str_replace(['_', '-'], ' ', $name));
                $type = $facet['type'] ?? 'multiple';

                $values = [];
                foreach ($facet['options'] ?? [] as $option) {
                    $values[$option['value'] ?? ''] = $option['count'] ?? 0;
                }

                if (!empty($values)) {
                    $facets[$name] = [
                        'name' => $displayName,
                        'values' => $values,
                        'type' => $type === 'single' ? 'single_select' : ($type === 'range' ? 'range' : 'checkbox_list'),
                    ];
                }
            }
            $collection->setFacets($facets);
        }

        // Set sort options if available
        if (!empty($response['sort_options'])) {
            $collection->setSortOptions($response['sort_options']);
        }

        return $collection;
    }

    /**
     * Get an empty SearchResultCollection for error cases.
     */
    protected function getEmptySearchResults(): SearchResultCollection
    {
        return (new SearchResultCollection)
            ->setTotal(0)
            ->setFacets([])
            ->setSortOptions([]);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->search($builder);
    }

    public function mapIds($results)
    {
        return $results->pluck('id')->all();
    }

    public function map(Builder $builder, $results, $model)
    {
        return $results;
    }

    public function getTotalCount($results)
    {
        return $results->getTotal();
    }

    public function flush($model)
    {
        $section = $model->getConstructorSection();

        try {
            // Fetch all item IDs for this model
            $itemIds = $model::pluck('id')->map(function ($id) {
                return ['id' => (string) $id];
            })->toArray();

            $response = $this->constructorService->makeRequest('delete', '/v2/items', [
                'query' => [
                    'section' => $section,
                ],
                'json' => [
                    'items' => $itemIds,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ], true);

            Log::info('Flush operation completed for '.get_class($model)." in section {$section}. Task ID: ".($response['task_id'] ?? 'Unknown'));

            // Optionally, you can check the task status
            // $this->checkTaskStatus($response['task_status_path']);
        } catch (\Exception $e) {
            Log::error("Failed to flush items from Constructor.io index for section {$section}: ".$e->getMessage());
        }
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        return $this->map($builder, $results, $model);
    }

    public function createIndex($name, array $options = [])
    {
        // No-op as Constructor.io doesn't require explicit index creation
    }

    public function deleteIndex($name)
    {
        // No-op as Constructor.io doesn't support index deletion via API
    }

    /**
     * Check and poll task status until completion.
     *
     * @param  string|null  $taskId  The task ID to check
     */
    protected function checkTaskStatus(?string $taskId): void
    {
        if (! $taskId) {
            return;
        }

        $maxAttempts = 5;
        $attempt = 0;
        $delay = 2;

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->constructorService->makeRequest('get', "/v1/tasks/{$taskId}", [
                    'query' => [
                        'key' => $this->constructorService->apiKey,
                    ],
                ]);

                $status = $response['status'] ?? null;

                if ($status === 'DONE') {
                    return;
                } elseif ($status === 'FAILED') {
                    Log::error('Constructor.io task failed: '.json_encode($response));

                    return;
                }

                $attempt++;
                sleep($delay);
                $delay *= 2;
            } catch (\Exception $e) {
                Log::error('Failed to check Constructor.io task status: '.$e->getMessage());

                return;
            }
        }

        Log::warning("Constructor.io task status check timed out for task ID: {$taskId}");
    }

    public function autocomplete($query, $options = [])
    {
        try {
            $response = $this->constructorService->makeRequest('get', "/autocomplete/{$query}", [
                'query' => array_merge([
                    'key' => $this->constructorService->apiKey,
                    'c' => $this->clientId,
                ], $options),
            ]);

            return $response['sections'] ?? [];
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                Log::warning('Rate limit exceeded for Constructor.io autocomplete request. Retrying after delay.');
                sleep(1);

                return $this->autocomplete($query, $options);
            }

            Log::error('Autocomplete request to Constructor.io failed: '.$e->getMessage());

            return [];
        }
    }

    public function browse($filterName, $filterValue, $options = [])
    {
        try {
            $page = $options['page'] ?? 1;
            $perPage = min(max(1, $options['perPage'] ?? 10), 200);
            $section = $options['section'] ?? 'Products';

            $params = [
                'key' => $this->constructorService->apiKey,
                'c' => $this->clientId,
                'section' => $section,
                'num_results_per_page' => $perPage,
                'page' => $page,
            ];

            // Add filters if they exist
            if (isset($options['filters']) && ! empty($options['filters'])) {
                $params['filters'] = $this->formatFilters($options['filters']);
            }

            // Add sort parameters if they exist
            if (isset($options['sort_by'])) {
                $params['sort_by'] = $options['sort_by'];
            }
            if (isset($options['sort_order'])) {
                $params['sort_order'] = $options['sort_order'];
            }

            // Add additional metadata parameters
            foreach (['filter_match_types', 'pre_filter_expression', 'variations_map', 'fmt_options'] as $param) {
                if (isset($options[$param])) {
                    $params[$param] = $options[$param];
                }
            }

            // Add any remaining optional parameters directly if provided in $options
            foreach (['offset', 'now', 'qs', 'origin_referrer', 'us', 'ui', 's', 'i'] as $optionalParam) {
                if (isset($options[$optionalParam])) {
                    $params[$optionalParam] = $options[$optionalParam];
                }
            }

            // Debug log for inspection
            Log::debug('Constructor Browse Params', [
                'params' => $params,
                'endpoint' => "/browse/{$filterName}/{$filterValue}",
            ]);

            $response = $this->constructorService->makeRequest('get', "/browse/{$filterName}/{$filterValue}", [
                'query' => array_filter($params),
            ]);

            // Use the provided model or fall back to a generic model
            $modelClass = $options['model'] ?? null;
            if ($modelClass) {
                return $this->mapSearchResults($response['response'] ?? [], new $modelClass);
            }

            return collect($response['response']['results'] ?? []);
        } catch (\Exception $e) {
            Log::error('Constructor browse error: '.$e->getMessage(), [
                'filterName' => $filterName,
                'filterValue' => $filterValue,
                'params' => $params ?? [],
                'trace' => $e->getTraceAsString(),
            ]);

            if ($e->getCode() === 429) {
                Log::warning('Rate limit exceeded for Constructor.io browse request. Retrying after delay.');
                sleep(1);

                return $this->browse($filterName, $filterValue, $options);
            }

            return collect();  // Return an empty collection on error
        }
    }

    public function recommendations($podId, $options = [])
    {
        try {
            $response = $this->constructorService->makeRequest('get', "/recommendations/v1/pods/{$podId}", [
                'query' => array_merge([
                    'key' => $this->constructorService->apiKey,
                    'c' => $this->clientId,
                ], $options),
            ]);

            if (isset($options['model'])) {
                return $this->mapSearchResults($response['response'] ?? [], new $options['model']);
            }

            return collect($response['response']['results'] ?? []);
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                Log::warning('Rate limit exceeded for Constructor.io recommendations request. Retrying after delay.');
                sleep(1);

                return $this->recommendations($podId, $options);
            }

            Log::error('Recommendations request to Constructor.io failed: '.$e->getMessage());

            return collect();
        }
    }

    /**
     * Retrieve a single item from Constructor.io by ID.
     *
     * @param  string  $itemId  The ID of the item to retrieve
     * @param  string|null  $section  The section to retrieve the item from (defaults to Products)
     * @return array|null  Returns the item data array or null if not found
     */
    public function getItem(string $itemId, ?string $section = null): ?array
    {
        try {
            // Debug log
            Log::debug('Constructor Get Item Request', [
                'itemId' => $itemId,
                'section' => $section,
                'endpoint' => "/v2/items/{$itemId}",
            ]);

            // Make authenticated request through the ConstructorService
            $item = $this->constructorService->makeRequest(
                'get',
                "/v2/items/{$itemId}",
                [
                    'query' => [
                        'key' => $this->constructorService->apiKey,
                        'section' => $section ?? 'Products',
                    ],
                ],
                true // This endpoint requires authentication
            );

            return $item;

        } catch (\Exception $e) {
            Log::error('Constructor get item error: '.$e->getMessage(), [
                'itemId' => $itemId,
                'section' => $section,
                'trace' => $e->getTraceAsString(),
            ]);

            if ($e->getCode() === 429) {
                Log::warning('Rate limit exceeded for Constructor.io get item request. Retrying after delay.');
                sleep(1);

                return $this->getItem($itemId, $section);
            }

            return null;
        }
    }
}
