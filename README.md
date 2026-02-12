# Constructor.io Laravel Integration

[![Latest Version on Packagist](https://img.shields.io/packagist/v/timjohnbancroft/constructor-laravel.svg?style=flat-square)](https://packagist.org/packages/timjohnbancroft/constructor-laravel)
[![License](https://img.shields.io/packagist/l/timjohnbancroft/constructor-laravel.svg?style=flat-square)](https://packagist.org/packages/timjohnbancroft/constructor-laravel)

A Laravel package for [Constructor.io](https://constructor.io) - AI-powered product discovery.

## Features

- **Product Search** - Full-text search with filters, sorting, pagination
- **Category Browse** - Browse products by category or facet
- **Autocomplete** - Search suggestions and product previews with zero-state support
- **AI Shopping Agent** - Natural language product discovery ("I need a gift for my mom")
- **Product Insights Agent** - AI-powered Q&A on product detail pages
- **Recommendations** - Personalized product recommendations
- **Collections** - Browse curated product collections
- **Catalog Management** - Bulk catalog uploads (CSV, JSONL)
- **Backend Integration** - Automatic forwarding of user context headers and cookies for server-side API calls
- **Laravel Scout** - Full Scout engine integration

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Your Laravel Application                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌─────────────────────┐    ┌────────────────────────────────────────┐    │
│   │  Constructor Facade │───▶│  ConstructorSandboxSearch              │    │
│   │  (Search/Browse)    │    │  - search(), browse(), autocomplete()  │    │
│   └─────────────────────┘    │  - getRecommendations()                │    │
│                              │  - getBrowseGroups(), getCollections() │    │
│                              └──────────────┬─────────────────────────┘    │
│                                             │                               │
│   ┌─────────────────────┐                   ▼                               │
│   │ ConstructorService  │    ┌────────────────────────────────────────┐    │
│   │ (Catalog Management)│───▶│        ac.cnstrc.com (Search API)      │    │
│   │ - uploadCatalog()   │    └────────────────────────────────────────┘    │
│   │ - getTaskStatus()   │                                                   │
│   └─────────────────────┘                                                   │
│                                                                             │
│   ┌─────────────────────┐    ┌────────────────────────────────────────┐    │
│   │ ConstructorAgent    │───▶│      agent.cnstrc.com (Agent API)      │    │
│   │ Service             │    │  - AI Shopping Agent                   │    │
│   │ - askShoppingAgent()│    │  - Product Insights Agent              │    │
│   │ - askProductQuestion│    └────────────────────────────────────────┘    │
│   └─────────────────────┘                                                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- Constructor.io account ([constructor.io](https://constructor.io))

## Installation

### 1. Install via Composer

```bash
composer require timjohnbancroft/constructor-laravel
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="ConstructorIO\Laravel\ConstructorServiceProvider"
```

### 3. Configure Environment

Add to your `.env` file:

```env
CONSTRUCTOR_API_KEY=your-api-key
CONSTRUCTOR_API_TOKEN=your-api-token
CONSTRUCTOR_AGENT_DOMAIN=your-agent-domain  # Optional: for AI Shopping Agent

# Optional: Backend integration (for server-side calls on behalf of browser users)
CONSTRUCTOR_BACKEND_TOKEN=your-backend-token      # Falls back to API_TOKEN if not set
CONSTRUCTOR_CLIENT_IDENTIFIER=cio-be-laravel-your-company  # Auto-generates if not set
```

Get your credentials from the [Constructor.io Dashboard](https://app.constructor.io).

**Credential Types:**
- **API Key** (public): Identifies your index, used for search/browse/autocomplete requests
- **API Token** (secret): Used for authenticated operations like catalog uploads

## Quick Start

### Search Products

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Basic search
$results = Constructor::search('blue shoes');

// With filters and options
$results = Constructor::search('shoes', [
    'brand' => ['Nike', 'Adidas'],
    'color' => ['blue'],
], [
    'page' => 1,
    'per_page' => 24,
    'sort_by' => 'price',
    'sort_order' => 'ascending',
]);

// Access results
foreach ($results->products as $product) {
    echo $product['name'];
    echo $product['price'];
    echo $product['image_url'];
}

// Pagination
echo "Page {$results->page} of {$results->totalPages()}";
echo "Total: {$results->total} products";
```

### Browse by Category

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Browse by category
$results = Constructor::browse('group_id', 'mens-clothing');

// Browse by brand
$results = Constructor::browse('brand', 'Nike', [], [
    'page' => 1,
    'per_page' => 24,
]);

// Get category hierarchy
$categories = Constructor::getBrowseGroups([
    'max_items' => 10,
    'max_children' => 5,
    'with_images' => true,
]);
```

### Autocomplete

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Get autocomplete suggestions
$results = Constructor::autocomplete('blu', [
    'sections' => [
        'suggestions' => ['enabled' => true, 'limit' => 5],
        'products' => ['enabled' => true, 'limit' => 6],
    ],
]);

foreach ($results->suggestions as $suggestion) {
    echo $suggestion['term'];
}

// Zero-state (search box focused but empty)
$zeroState = Constructor::getZeroStateData([
    'show_top_categories' => true,
    'show_popular_products' => true,
    'recommendation_pod_id' => 'hp-bestsellers',
]);
```

### Recommendations

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Home page recommendations
$recs = Constructor::getRecommendations('home-page-1', [
    'num_results' => 8,
]);

// Product page recommendations (requires item_id)
$similar = Constructor::getItemRecommendations('pdp-similar', 'PRODUCT-123');
```

### Collections

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Get all collections
$collections = Constructor::getCollections(['max_items' => 10]);

// Get collection metadata
$collection = Constructor::getCollection('summer-essentials');

// Browse products in a collection
$results = Constructor::browseCollection('summer-essentials', [], [
    'page' => 1,
    'per_page' => 24,
]);
```

### AI Shopping Agent

```php
use ConstructorIO\Laravel\Services\ConstructorAgentService;

$agent = app(ConstructorAgentService::class);

// Natural language query
$response = $agent->askShoppingAgent('I need a gift for my mom who likes gardening');

echo $response['message'];
foreach ($response['products'] as $product) {
    echo $product['name'];
}

// Continue conversation
$followUp = $agent->askShoppingAgent(
    'Something under $50',
    $response['thread_id']
);
```

### Product Insights Agent

```php
use ConstructorIO\Laravel\Services\ConstructorAgentService;

$agent = app(ConstructorAgentService::class);

// Get suggested questions for a product
$questions = $agent->getProductQuestions('PRODUCT-123');

// Ask a question about a product
$answer = $agent->askProductQuestion(
    question: 'Is this true to size?',
    itemId: 'PRODUCT-123'
);

echo $answer['answer'];
```

### Catalog Management

```php
use ConstructorIO\Laravel\Services\ConstructorService;

$constructor = app(ConstructorService::class);

// Upload catalog file (creates or replaces all items)
$result = $constructor->uploadCatalog(
    storage_path('app/catalog/items.csv'),
    'create_or_replace'
);

// Or patch (update only specified items)
$result = $constructor->uploadCatalog(
    storage_path('app/catalog/updates.csv'),
    'patch'
);

// Wait for completion
$status = $constructor->waitForTaskCompletion($result['task_id']);

if ($status['successful']) {
    echo "Catalog uploaded!";
}
```

### Recipes (If Configured)

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Check if recipes are supported
if (Constructor::supportsRecipes()) {
    // Search recipes
    $results = Constructor::searchRecipes('chicken pasta');

    // Browse recipes by category
    $results = Constructor::browseRecipes('meal_type', 'dinner');

    // Get a single recipe
    $recipe = Constructor::getRecipe('recipe-123');
}
```

## Data Transfer Objects (DTOs)

All search operations return strongly-typed DTOs with convenient methods.

### SearchResults

Returned by `search()`, `browse()`, and `browseCollection()`.

```php
$results = Constructor::search('shoes');

// Properties
$results->products      // array - Product data
$results->total         // int - Total matching results
$results->page          // int - Current page (1-indexed)
$results->perPage       // int - Results per page
$results->facets        // array - Available filters with counts
$results->groups        // array - Category hierarchy (for browse)
$results->metadata      // array - Request ID, result ID, etc.

// Methods
$results->hasMore()        // bool - More pages available?
$results->totalPages()     // int - Calculate total pages
$results->isEmpty()        // bool - No products?
$results->count()          // int - Products on this page
$results->getOffset()      // int - Offset for "Showing X-Y of Z"
$results->nextPageNumber() // int - Next page number or 0
$results->toArray()        // array - For JSON serialization
```

**Facets Structure:**
```php
// $results->facets
[
    'brand' => [
        'name' => 'Brand',           // Display name
        'values' => [                 // Value => count
            'Nike' => 45,
            'Adidas' => 32,
        ],
        'type' => 'checkbox_list',   // single_select, checkbox_list, or range
    ],
    'price' => [
        'name' => 'Price',
        'values' => [...],
        'type' => 'range',
        'min' => 25.00,
        'max' => 299.99,
    ],
]
```

### AutocompleteResults

Returned by `autocomplete()` and `getZeroStateData()`.

```php
$results = Constructor::autocomplete('blu');

// Properties
$results->suggestions      // array - Search term suggestions
$results->products         // array - Product previews
$results->categories       // array - Category suggestions
$results->trending         // array - Trending searches (zero-state)
$results->popularProducts  // array - Popular products (zero-state)
$results->topCategories    // array - Top categories (zero-state)
$results->metadata         // array - Request metadata

// Methods
$results->hasSuggestions()     // bool
$results->hasProducts()        // bool
$results->hasCategories()      // bool
$results->hasZeroStateData()   // bool
$results->hasTrending()        // bool
$results->hasPopularProducts() // bool
$results->hasTopCategories()   // bool
$results->isEmpty()            // bool
$results->isAutocompleteEmpty() // bool - Ignores zero-state
$results->toArray()            // array
```

### RecommendationResults

Returned by `getRecommendations()` and `getItemRecommendations()`.

```php
$recs = Constructor::getRecommendations('home-bestsellers');

// Properties
$recs->podId       // string - The pod ID requested
$recs->title       // string - Pod display name
$recs->products    // array - Recommended products
$recs->total       // int - Total recommendations available
$recs->metadata    // array - Request metadata, pod info

// Methods
$recs->isEmpty()           // bool
$recs->hasRecommendations() // bool
$recs->count()             // int - Number of products returned
$recs->toArray()           // array
```

## Complete Facade Methods Reference

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Search & Browse
Constructor::search(string $query, array $filters = [], array $options = []): SearchResults;
Constructor::browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;
Constructor::autocomplete(string $query, array $options = []): AutocompleteResults;
Constructor::getZeroStateData(array $options = []): AutocompleteResults;

// Recommendations
Constructor::getRecommendations(string $podId, array $options = []): RecommendationResults;
Constructor::getItemRecommendations(string $podId, string $itemId, array $options = []): RecommendationResults;

// Categories & Groups
Constructor::getBrowseGroups(array $options = []): array;

// Collections
Constructor::getCollections(array $options = []): array;
Constructor::getCollection(string $collectionId): ?array;
Constructor::browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults;
Constructor::getFirstProductImageFromCollection(string $collectionId): ?string;

// Facets
Constructor::getFacets(string $query, array $filters = []): array;
Constructor::getAvailableFacets(): array;
Constructor::getFacetValuesWithImages(string $facetName, int $maxItems = 10): array;

// Recipes
Constructor::searchRecipes(string $query, array $filters = [], array $options = []): SearchResults;
Constructor::browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;
Constructor::getRecipe(string $recipeId): ?array;

// Feature Detection
Constructor::supportsZeroState(): bool;
Constructor::supportsRecommendations(): bool;
Constructor::supportsBrowseGroups(): bool;
Constructor::supportsCollections(): bool;
Constructor::supportsRecipes(): bool;
Constructor::getProviderName(): string;
```

## Search Options Reference

Options available for `search()` and `browse()`:

```php
$options = [
    // Pagination
    'page' => 1,              // Page number (1-indexed)
    'per_page' => 24,         // Results per page (max 100)

    // Sorting
    'sort_by' => 'price',     // Field to sort by
    'sort_order' => 'ascending', // 'ascending' or 'descending'

    // Section
    'section' => 'Products',  // Constructor section name

    // Range Filters
    'range_filters' => [
        'price' => ['min' => 50, 'max' => 200],
    ],

    // User Context (for personalization)
    'user_id' => 'user-123',
    'session_id' => 'session-abc',
];
```

## Laravel Scout Integration

### Register the Engine

The package automatically registers the `constructor` Scout driver. Configure in `config/scout.php`:

```php
return [
    'driver' => env('SCOUT_DRIVER', 'constructor'),

    'constructor' => [
        'api_key' => env('CONSTRUCTOR_API_KEY'),
        'api_token' => env('CONSTRUCTOR_API_TOKEN'),
    ],
];
```

### Make Models Searchable

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    /**
     * Get the Constructor section for this model.
     */
    public function getConstructorSection(): string
    {
        return 'Products';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->sku,
            'name' => $this->name,
            'url' => route('products.show', $this),
            'image_url' => $this->image_url,
            'price' => $this->price,
            'brand' => $this->brand,
            'categories' => $this->categories->pluck('name')->toArray(),
        ];
    }
}
```

### Search with Scout

```php
// Basic search
$products = Product::search('blue shirt')->get();

// With filters
$products = Product::search('shirt')
    ->where('brand', 'Nike')
    ->paginate(24);
```

## Configuration Reference

### `config/constructor.php`

```php
return [
    // API Credentials
    'api_key' => env('CONSTRUCTOR_API_KEY'),
    'api_token' => env('CONSTRUCTOR_API_TOKEN'),

    // API Endpoints
    'search_base_url' => env('CONSTRUCTOR_SEARCH_BASE_URL', 'https://ac.cnstrc.com'),
    'agent_base_url' => env('CONSTRUCTOR_AGENT_BASE_URL', 'https://agent.cnstrc.com'),

    // AI Agent Settings
    'agent_domain' => env('CONSTRUCTOR_AGENT_DOMAIN'),
    'agent_guard' => env('CONSTRUCTOR_AGENT_GUARD', true),
    'agent_num_result_events' => env('CONSTRUCTOR_AGENT_NUM_RESULT_EVENTS', 5),
    'agent_num_results_per_event' => env('CONSTRUCTOR_AGENT_NUM_RESULTS_PER_EVENT', 4),

    // Backend Integration (server-side calls on behalf of browser users)
    'backend_token' => env('CONSTRUCTOR_BACKEND_TOKEN'),       // Falls back to api_token
    'client_identifier' => env('CONSTRUCTOR_CLIENT_IDENTIFIER'), // Auto-generates if null

    // HTTP Client
    'timeout' => env('CONSTRUCTOR_TIMEOUT', 30),
    'retry_times' => env('CONSTRUCTOR_RETRY_TIMES', 2),
    'retry_sleep' => env('CONSTRUCTOR_RETRY_SLEEP', 100),
];
```

## Available Services

| Service | Description |
|---------|-------------|
| `ConstructorSandboxSearch` | Search, browse, autocomplete, recommendations, collections |
| `ConstructorAgentService` | AI Shopping Agent and Product Insights Agent |
| `ConstructorService` | Catalog uploads, task monitoring, admin operations |
| `ConstructorEngine` | Laravel Scout engine implementation |

## Error Handling

The package handles errors gracefully:

- **Search errors** return empty `SearchResults` (logged at error level)
- **Recommendation errors** return empty `RecommendationResults` (logged at error level)
- **Agent errors** throw exceptions (for UI error handling)
- **Catalog errors** throw exceptions (for background job handling)

```php
// Search - gracefully returns empty on error
$results = Constructor::search('query');
if ($results->isEmpty()) {
    // Handle no results (could be error or just no matches)
}

// Agent - throws on error
try {
    $response = $agent->askShoppingAgent($query);
} catch (\Exception $e) {
    // Handle error: rate limit, auth failure, network error
    Log::error('Shopping Agent error: ' . $e->getMessage());
}
```

## Troubleshooting

### "Constructor.io configuration not properly set"

Ensure all required environment variables are set:
```env
CONSTRUCTOR_API_KEY=your-api-key
CONSTRUCTOR_API_TOKEN=your-api-token
```

### Empty results when products should exist

1. **Check API key**: Verify the API key matches your Constructor index
2. **Check section name**: Default is 'Products', ensure your index uses this
3. **Check filters**: Some filters may be too restrictive
4. **View logs**: Check `storage/logs/laravel.log` for API errors

### Facets not returning

- Facets must be configured in the Constructor.io dashboard
- Not all indexes have facets enabled
- Try a broader search query to see available facets

### Recommendations returning empty

1. Verify the pod ID exists in your Constructor account
2. Some pods require `item_id` - use `getItemRecommendations()` instead
3. Check that the pod has been trained with data

### Agent authentication failures

```
Constructor Agent API authentication failed. Check your API key and domain configuration.
```

- Ensure `CONSTRUCTOR_AGENT_DOMAIN` is set correctly
- The agent domain is separate from the search API key

### Rate limit errors

```
Rate limit exceeded. Please try again later.
```

- Implement caching for repeated requests
- Contact Constructor.io to increase limits for production

## Testing Your Integration

### Artisan Test Command

```bash
# Test search functionality
php artisan tinker
>>> Constructor::search('test')->toArray()

# Test recommendations
>>> Constructor::getRecommendations('your-pod-id')->toArray()

# Test agent (if configured)
>>> app(ConstructorAgentService::class)->getProductQuestions('product-id')
```

### Unit Testing

Mock the facade for testing:

```php
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;

public function test_search_page_displays_products()
{
    Constructor::shouldReceive('search')
        ->once()
        ->with('shoes', [], \Mockery::any())
        ->andReturn(new SearchResults(
            products: [['id' => '1', 'name' => 'Nike Shoes']],
            total: 1,
            page: 1,
            perPage: 24
        ));

    $response = $this->get('/search?q=shoes');

    $response->assertSee('Nike Shoes');
}
```

## Best Practices

### Caching Recommendations

```php
use Illuminate\Support\Facades\Cache;

$recommendations = Cache::remember(
    "recs:home-page:{$userId}",
    now()->addMinutes(15),
    fn() => Constructor::getRecommendations('home-page-1')
);
```

### User Personalization

Pass user identifiers for personalized results:

```php
$results = Constructor::search('shoes', [], [
    'user_id' => auth()->id(),
    'session_id' => session()->getId(),
]);
```

### Handling Zero-State Gracefully

```php
public function autocomplete(Request $request)
{
    $query = trim($request->input('q', ''));

    if (empty($query)) {
        return Constructor::getZeroStateData([
            'show_top_categories' => true,
            'recommendation_pod_id' => 'autocomplete-bestsellers',
        ])->toArray();
    }

    return Constructor::autocomplete($query)->toArray();
}
```

## Documentation

- [Constructor.io Documentation](https://docs.constructor.io/)
- [Search API Reference](https://docs.constructor.com/reference/search-search-results)
- [Browse API Reference](https://docs.constructor.com/reference/browse-browse-results)
- [Autocomplete API Reference](https://docs.constructor.com/reference/autocomplete-autocomplete-results)
- [Recommendations API Reference](https://docs.constructor.com/reference/recommendations-recommendation-results)
- [AI Shopping Agent API Reference](https://docs.constructor.com/reference/v1-asa-retrieve-intent)

## License

MIT License. See [LICENSE](LICENSE) for details.
