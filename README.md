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
- **Catalog Management** - Bulk catalog uploads (CSV, JSONL)
- **Laravel Scout** - Full Scout engine integration

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x
- Constructor.io account ((https://constructor.io))

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
```

Get your credentials from the [Constructor.io Dashboard](https://app.constructor.io).

## Quick Start

### Search Products

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Basic search
$results = Constructor::search('blue shoes');

// With filters
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

// Product page recommendations
$similar = Constructor::getItemRecommendations('pdp-similar', 'PRODUCT-123');
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

// Get suggested questions
$questions = $agent->getProductQuestions('PRODUCT-123');

// Answer a question
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

// Upload catalog file
$result = $constructor->uploadCatalog(
    storage_path('app/catalog/items.csv'),
    'create_or_replace'
);

// Wait for completion
$status = $constructor->waitForTaskCompletion($result['task_id']);

if ($status['successful']) {
    echo "Catalog uploaded!";
}
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

    public function getConstructorSection(): string
    {
        return 'Products';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->sku,
            'name' => $this->name,
            'url' => route('products.show', $this),
            'image_url' => $this->image_url,
            'price' => $this->price,
            'brand' => $this->brand,
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

## Configuration

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

    // HTTP Client
    'timeout' => env('CONSTRUCTOR_TIMEOUT', 30),
    'retry_times' => env('CONSTRUCTOR_RETRY_TIMES', 2),
];
```

## Available Services

| Service | Description |
|---------|-------------|
| `ConstructorSandboxSearch` | Search, browse, autocomplete, recommendations |
| `ConstructorAgentService` | AI Shopping Agent and Product Insights |
| `ConstructorService` | Catalog uploads and admin operations |
| `ConstructorEngine` | Laravel Scout engine |

## Facade Methods

```php
use ConstructorIO\Laravel\Facades\Constructor;

// Search & Browse
Constructor::search($query, $filters, $options);
Constructor::browse($filterName, $filterValue, $filters, $options);
Constructor::autocomplete($query, $options);
Constructor::getZeroStateData($options);

// Recommendations
Constructor::getRecommendations($podId, $options);
Constructor::getItemRecommendations($podId, $itemId, $options);

// Categories & Collections
Constructor::getBrowseGroups($options);
Constructor::getCollections($options);
Constructor::browseCollection($collectionId, $filters, $options);

// Facets
Constructor::getFacets($query, $filters);
Constructor::getAvailableFacets();
Constructor::getFacetValuesWithImages($facetName, $maxItems);
```

## Documentation

- [Constructor.io Documentation](https://docs.constructor.io/)
- [Search API Reference](https://docs.constructor.io/reference/search-api)
- [Recommendations API](https://docs.constructor.io/reference/recommendations-api)
- [AI Agents](https://docs.constructor.io/reference/agents)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

MIT License. See [LICENSE](LICENSE) for details.
