# Constructor.io Laravel Integration - User Guide

A comprehensive guide to building e-commerce search experiences with Constructor.io and Laravel.

## Table of Contents

1. [Getting Started](#1-getting-started)
2. [Core Concepts](#2-core-concepts)
3. [Search Features](#3-search-features)
4. [Category & Browse Pages](#4-category--browse-pages)
5. [Autocomplete](#5-autocomplete)
6. [Facets & Filtering](#6-facets--filtering)
7. [Recommendations](#7-recommendations)
8. [Collections](#8-collections)
9. [AI Agents](#9-ai-agents)
10. [Catalog Management](#10-catalog-management)
11. [Recipes](#11-recipes)
12. [Laravel Scout Integration](#12-laravel-scout-integration)
13. [DTOs Reference](#13-dtos-reference)
14. [Error Handling](#14-error-handling)
15. [Testing Your Integration](#15-testing-your-integration)
16. [Best Practices](#16-best-practices)
17. [Backend Integration](#17-backend-integration)
18. [Troubleshooting](#18-troubleshooting)

---

## 1. Getting Started

### Installation

Install the package via Composer:

```bash
composer require timjohnbancroft/constructor-laravel
```

### Publish Configuration

```bash
php artisan vendor:publish --provider="ConstructorIO\Laravel\ConstructorServiceProvider"
```

This creates `config/constructor.php` with all available settings.

### Environment Configuration

Add your Constructor.io credentials to `.env`:

```env
# Required
CONSTRUCTOR_API_KEY=key_your_api_key
CONSTRUCTOR_API_TOKEN=tok_your_api_token

# Optional - for AI Shopping Agent
CONSTRUCTOR_AGENT_DOMAIN=domain-name (typically 'assistant' by default)

# Optional - API endpoints (defaults shown)
CONSTRUCTOR_SEARCH_BASE_URL=https://ac.cnstrc.com
CONSTRUCTOR_AGENT_BASE_URL=https://agent.cnstrc.com

# Optional - HTTP settings
CONSTRUCTOR_TIMEOUT=30
CONSTRUCTOR_RETRY_TIMES=2
```

### Quick Verification

Test your configuration in Tinker:

```bash
php artisan tinker
```

```php
>>> use ConstructorIO\Laravel\Facades\Constructor;
>>> Constructor::search('test')->total
// Should return a number (your total products matching 'test')
```

If you get an exception, check your API credentials.

---

## 2. Core Concepts

### Architecture Overview

This package provides three main services:

| Service | Purpose | API Endpoint |
|---------|---------|--------------|
| `ConstructorSandboxSearch` | Search, browse, autocomplete, recommendations | ac.cnstrc.com |
| `ConstructorAgentService` | AI Shopping Agent, Product Insights | agent.cnstrc.com |
| `ConstructorService` | Catalog uploads, admin operations | ac.cnstrc.com |

### API Credentials

**API Key (Public)**
- Identifies your Constructor index
- Safe to expose in client-side code
- Used for all search/browse/autocomplete requests
- Format: `key_xxxxxxxx`

**API Token (Secret)**
- Used for authenticated operations
- NEVER expose in client-side code
- Required for catalog uploads and admin endpoints
- Format: `tok_xxxxxxxx`

### Response DTOs

All search operations return Data Transfer Objects with consistent interfaces:

- `SearchResults` - For search, browse, and collection browsing
- `AutocompleteResults` - For autocomplete and zero-state
- `RecommendationResults` - For recommendation pods

These DTOs provide:
- Typed access to response data
- Helper methods for pagination
- Easy JSON serialization via `toArray()`
- Empty state handling

### Product Data Structure

Constructor returns products with a nested `data` object. This package normalizes the structure:

```php
// Original Constructor format:
{
    "value": "Blue Running Shoes",
    "data": {
        "id": "SKU-123",
        "url": "/products/blue-running-shoes",
        "price": 99.99,
        "image_url": "https://...",
        ...
    }
}

// Normalized by this package:
[
    'id' => 'SKU-123',
    'name' => 'Blue Running Shoes',
    'url' => '/products/blue-running-shoes',
    'price' => 99.99,
    'image_url' => 'https://...',
    'brand' => 'Nike',
    'categories' => [...],
    '_raw' => [...] // Original data for custom fields
]
```

Access custom fields via `_raw`:

```php
$product['_raw']['custom_field']
```

---

## 3. Search Features

### Basic Search

```php
use ConstructorIO\Laravel\Facades\Constructor;

$results = Constructor::search('blue shoes');

// Results
$results->products  // Array of products
$results->total     // Total matching results
$results->page      // Current page (1-indexed)
$results->perPage   // Results per page
$results->facets    // Available filters
```

### Search with Filters

Apply facet filters to narrow results:

```php
$results = Constructor::search('shoes', [
    'brand' => ['Nike', 'Adidas'],  // Match either brand
    'color' => ['blue'],
    'size' => ['10', '11'],
]);
```

### Search with Range Filters

For numeric ranges (price, rating, etc.):

```php
$results = Constructor::search('shoes', [], [
    'range_filters' => [
        'price' => ['min' => 50, 'max' => 200],
        'rating' => ['min' => 4],  // 4 and above
    ],
]);
```

### Pagination

```php
$results = Constructor::search('shoes', [], [
    'page' => 2,
    'per_page' => 24,
]);

// Pagination helpers
$results->totalPages();      // Total pages available
$results->hasMore();         // More pages after current?
$results->nextPageNumber();  // Next page number (or 0 if last)
$results->getOffset();       // Starting index for "Showing 25-48 of 156"
```

### Sorting

```php
$results = Constructor::search('shoes', [], [
    'sort_by' => 'price',
    'sort_order' => 'ascending',  // or 'descending'
]);

// Common sort fields
// - price
// - relevance (default)
// - created_at
// - name
// Check your Constructor index for available sort fields
```

### Full Search Example

Building a complete search results controller:

```php
<?php

namespace App\Http\Controllers;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim($request->input('q', ''));

        if (empty($query)) {
            return redirect('/');
        }

        // Parse filters from request
        $filters = $this->parseFilters($request);

        // Parse range filters
        $rangeFilters = $this->parseRangeFilters($request);

        // Build options
        $options = [
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => 24,
            'range_filters' => $rangeFilters,
        ];

        // Add sorting if specified
        if ($sort = $request->input('sort')) {
            [$field, $order] = $this->parseSortOption($sort);
            $options['sort_by'] = $field;
            $options['sort_order'] = $order;
        }

        // Add user context for personalization
        if (auth()->check()) {
            $options['user_id'] = auth()->id();
        }
        $options['session_id'] = session()->getId();

        // Execute search
        $results = Constructor::search($query, $filters, $options);

        return view('search.results', [
            'query' => $query,
            'results' => $results,
            'appliedFilters' => $filters,
            'appliedRangeFilters' => $rangeFilters,
            'currentSort' => $request->input('sort', 'relevance'),
        ]);
    }

    protected function parseFilters(Request $request): array
    {
        $filters = [];
        $facetParams = ['brand', 'color', 'size', 'category'];

        foreach ($facetParams as $facet) {
            $values = $request->input($facet);
            if (!empty($values)) {
                $filters[$facet] = is_array($values) ? $values : [$values];
            }
        }

        return $filters;
    }

    protected function parseRangeFilters(Request $request): array
    {
        $rangeFilters = [];

        // Price range
        $minPrice = $request->input('min_price');
        $maxPrice = $request->input('max_price');

        if ($minPrice !== null || $maxPrice !== null) {
            $rangeFilters['price'] = array_filter([
                'min' => $minPrice !== null ? (float) $minPrice : null,
                'max' => $maxPrice !== null ? (float) $maxPrice : null,
            ], fn($v) => $v !== null);
        }

        return $rangeFilters;
    }

    protected function parseSortOption(string $sort): array
    {
        return match($sort) {
            'price_asc' => ['price', 'ascending'],
            'price_desc' => ['price', 'descending'],
            'newest' => ['created_at', 'descending'],
            'name_asc' => ['name', 'ascending'],
            default => ['relevance', 'descending'],
        };
    }
}
```

**Blade View (search/results.blade.php):**

```blade
<div class="search-results">
    <h1>Search results for "{{ $query }}"</h1>

    @if($results->isEmpty())
        <p>No products found. Try a different search term.</p>
    @else
        <p>
            Showing {{ $results->getOffset() + 1 }}-{{ $results->getOffset() + $results->count() }}
            of {{ $results->total }} results
        </p>

        {{-- Facet filters sidebar --}}
        @include('search.partials.filters', ['facets' => $results->facets])

        {{-- Product grid --}}
        <div class="products-grid">
            @foreach($results->products as $product)
                <div class="product-card">
                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}">
                    <h3>{{ $product['name'] }}</h3>
                    <p class="price">${{ number_format($product['price'], 2) }}</p>
                    <a href="{{ $product['url'] }}">View Product</a>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($results->totalPages() > 1)
            <nav class="pagination">
                @for($i = 1; $i <= $results->totalPages(); $i++)
                    <a href="?q={{ urlencode($query) }}&page={{ $i }}"
                       class="{{ $i === $results->page ? 'active' : '' }}">
                        {{ $i }}
                    </a>
                @endfor
            </nav>
        @endif
    @endif
</div>
```

---

## 4. Category & Browse Pages

### Browse by Category (group_id)

Categories in Constructor are called "groups" and have `group_id` values:

```php
// Browse products in a category
$results = Constructor::browse('group_id', 'mens-shoes');

// With additional filters
$results = Constructor::browse('group_id', 'mens-shoes', [
    'brand' => ['Nike'],
], [
    'page' => 1,
    'per_page' => 24,
]);
```

### Browse by Facet Value

Browse products matching any facet value:

```php
// Browse all Nike products
$results = Constructor::browse('brand', 'Nike');

// Browse blue products
$results = Constructor::browse('color', 'Blue');
```

### Get Category Hierarchy

Retrieve the category tree for navigation:

```php
$categories = Constructor::getBrowseGroups([
    'max_items' => 10,      // Max top-level categories
    'max_children' => 5,    // Max children per category
    'with_images' => true,  // Fetch images (slower)
]);

// Result structure:
[
    [
        'id' => 'mens-clothing',
        'name' => 'Men\'s Clothing',
        'count' => 1250,
        'image' => 'https://...',  // If with_images=true
        'children' => [
            ['id' => 'mens-shirts', 'name' => 'Shirts', 'count' => 450],
            ['id' => 'mens-pants', 'name' => 'Pants', 'count' => 320],
            ...
        ],
    ],
    ...
]
```

### Full Category Page Example

```php
<?php

namespace App\Http\Controllers;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function show(Request $request, string $categoryId)
    {
        // Get category products
        $results = Constructor::browse('group_id', $categoryId,
            $this->parseFilters($request),
            [
                'page' => $request->input('page', 1),
                'per_page' => 24,
                'sort_by' => $request->input('sort_by'),
                'sort_order' => $request->input('sort_order', 'descending'),
            ]
        );

        // Get breadcrumb from groups
        $breadcrumb = $this->buildBreadcrumb($results->groups, $categoryId);

        return view('category.show', [
            'categoryId' => $categoryId,
            'categoryName' => $breadcrumb[count($breadcrumb) - 1]['name'] ?? $categoryId,
            'breadcrumb' => $breadcrumb,
            'results' => $results,
        ]);
    }

    protected function buildBreadcrumb(array $groups, string $targetId): array
    {
        $breadcrumb = [['id' => '', 'name' => 'Home']];

        foreach ($groups as $group) {
            if ($group['group_id'] === $targetId) {
                $breadcrumb[] = ['id' => $group['group_id'], 'name' => $group['name']];
                break;
            }
        }

        return $breadcrumb;
    }

    protected function parseFilters(Request $request): array
    {
        // Similar to search filter parsing
        return collect($request->except(['page', 'sort_by', 'sort_order']))
            ->filter()
            ->map(fn($v) => is_array($v) ? $v : [$v])
            ->toArray();
    }
}
```

---

## 5. Autocomplete

### Basic Autocomplete

```php
$results = Constructor::autocomplete('blu');

// Access suggestions
foreach ($results->suggestions as $suggestion) {
    echo $suggestion['term'];           // "blue shirt"
    echo $suggestion['matched_terms'];  // For highlighting
}

// Access product previews
foreach ($results->products as $product) {
    echo $product['name'];
    echo $product['image_url'];
    echo $product['price'];
}
```

### Configure Sections

Control what sections appear and their limits:

```php
$results = Constructor::autocomplete('blu', [
    'sections' => [
        'suggestions' => ['enabled' => true, 'limit' => 5],
        'products' => ['enabled' => true, 'limit' => 6],
    ],
]);
```

### Zero-State Data

When the search box is focused but empty, show trending/popular content:

```php
$zeroState = Constructor::getZeroStateData([
    'show_top_categories' => true,
    'show_popular_products' => true,
    'categories_limit' => 5,
    'products_limit' => 6,
    'recommendation_pod_id' => 'autocomplete-bestsellers',  // Optional pod
]);

// Access data
$zeroState->topCategories     // Array of popular categories
$zeroState->popularProducts   // Array of popular products
$zeroState->trending          // Array of trending searches (if available)
```

### Full Autocomplete API Endpoint

```php
<?php

namespace App\Http\Controllers\Api;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AutocompleteController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->input('q', ''));

        // Empty query = zero-state
        if (empty($query)) {
            return $this->zeroState();
        }

        // Minimum query length
        if (strlen($query) < 2) {
            return response()->json([
                'suggestions' => [],
                'products' => [],
            ]);
        }

        $results = Constructor::autocomplete($query, [
            'sections' => [
                'suggestions' => ['enabled' => true, 'limit' => 5],
                'products' => ['enabled' => true, 'limit' => 6],
            ],
        ]);

        return response()->json($results->toArray());
    }

    protected function zeroState(): JsonResponse
    {
        $results = Constructor::getZeroStateData([
            'show_top_categories' => true,
            'show_popular_products' => true,
            'categories_limit' => 5,
            'products_limit' => 6,
            'recommendation_pod_id' => config('app.autocomplete_pod_id'),
        ]);

        return response()->json([
            'isZeroState' => true,
            'topCategories' => $results->topCategories,
            'popularProducts' => $results->popularProducts,
        ]);
    }
}
```

### Livewire Autocomplete Component

```php
<?php

namespace App\Livewire;

use ConstructorIO\Laravel\Facades\Constructor;
use Livewire\Component;

class SearchBox extends Component
{
    public string $query = '';
    public array $suggestions = [];
    public array $products = [];
    public array $topCategories = [];
    public bool $isZeroState = false;
    public bool $showDropdown = false;

    public function updatedQuery(string $value): void
    {
        $value = trim($value);

        if (strlen($value) < 2) {
            $this->suggestions = [];
            $this->products = [];
            $this->isZeroState = empty($value);
            return;
        }

        $results = Constructor::autocomplete($value, [
            'sections' => [
                'suggestions' => ['enabled' => true, 'limit' => 5],
                'products' => ['enabled' => true, 'limit' => 4],
            ],
        ]);

        $this->suggestions = $results->suggestions;
        $this->products = $results->products;
        $this->isZeroState = false;
    }

    public function showZeroState(): void
    {
        $this->showDropdown = true;

        if (!empty($this->query)) {
            return;
        }

        $results = Constructor::getZeroStateData([
            'show_top_categories' => true,
            'categories_limit' => 5,
        ]);

        $this->topCategories = $results->topCategories;
        $this->isZeroState = true;
    }

    public function hideDropdown(): void
    {
        $this->showDropdown = false;
    }

    public function selectSuggestion(string $term): void
    {
        $this->redirect(route('search', ['q' => $term]));
    }

    public function render()
    {
        return view('livewire.search-box');
    }
}
```

**Blade View (livewire/search-box.blade.php):**

```blade
<div class="search-box-container" x-data="{ focused: false }">
    <div class="search-input-wrapper">
        <input
            type="text"
            wire:model.live.debounce.300ms="query"
            wire:focus="showZeroState"
            wire:blur="hideDropdown"
            @focus="focused = true"
            @blur="focused = false"
            placeholder="Search products..."
            class="search-input"
        >
        <button type="submit" wire:click="$dispatch('search', { query: $wire.query })">
            Search
        </button>
    </div>

    @if($showDropdown)
        <div class="autocomplete-dropdown" wire:transition>
            @if($isZeroState)
                {{-- Zero State --}}
                <div class="zero-state">
                    <h4>Popular Categories</h4>
                    <ul>
                        @foreach($topCategories as $category)
                            <li>
                                <a href="{{ route('category', $category['id']) }}">
                                    {{ $category['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                {{-- Suggestions --}}
                @if(!empty($suggestions))
                    <div class="suggestions-section">
                        <h4>Suggestions</h4>
                        <ul>
                            @foreach($suggestions as $suggestion)
                                <li wire:click="selectSuggestion('{{ $suggestion['term'] }}')">
                                    {{ $suggestion['term'] }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Products --}}
                @if(!empty($products))
                    <div class="products-section">
                        <h4>Products</h4>
                        <div class="product-previews">
                            @foreach($products as $product)
                                <a href="{{ $product['url'] }}" class="product-preview">
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}">
                                    <span>{{ $product['name'] }}</span>
                                    <span>${{ number_format($product['price'], 2) }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
```

---

## 6. Facets & Filtering

### Understanding Facets

Facets are returned with every search/browse response. They show available filter options with counts:

```php
$results = Constructor::search('shoes');

foreach ($results->facets as $facetKey => $facetData) {
    echo "Filter by: " . $facetData['name'];  // "Brand"

    foreach ($facetData['values'] as $value => $count) {
        echo "$value ($count)";  // "Nike (45)"
    }
}
```

### Facet Types

```php
$results->facets
[
    'brand' => [
        'name' => 'Brand',
        'type' => 'checkbox_list',  // Multiple selection
        'values' => ['Nike' => 45, 'Adidas' => 32],
    ],
    'color' => [
        'name' => 'Color',
        'type' => 'checkbox_list',
        'values' => ['Blue' => 28, 'Red' => 15],
    ],
    'price' => [
        'name' => 'Price',
        'type' => 'range',          // Numeric range
        'values' => [...],
        'min' => 25.00,
        'max' => 299.99,
    ],
]
```

### Getting Available Facets

Get all facets without performing a search:

```php
$facets = Constructor::getAvailableFacets();

// Returns array of facet names and types
[
    ['name' => 'brand', 'display_name' => 'Brand', 'type' => 'checkbox_list'],
    ['name' => 'color', 'display_name' => 'Color', 'type' => 'checkbox_list'],
    ...
]
```

### Get Facet Values with Images

For "Shop by Brand" or similar sections:

```php
$brands = Constructor::getFacetValuesWithImages('brand', 10);

// Returns:
[
    [
        'value' => 'Nike',
        'display_name' => 'Nike',
        'count' => 145,
        'image' => 'https://...', // Sample product image
    ],
    ...
]
```

### Building a Filter Sidebar

```php
<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class FilterSidebar extends Component
{
    public array $facets;
    public array $appliedFilters;
    public array $appliedRangeFilters;

    public function __construct(
        array $facets,
        array $appliedFilters = [],
        array $appliedRangeFilters = []
    ) {
        $this->facets = $facets;
        $this->appliedFilters = $appliedFilters;
        $this->appliedRangeFilters = $appliedRangeFilters;
    }

    public function isFilterApplied(string $facet, string $value): bool
    {
        return in_array($value, $this->appliedFilters[$facet] ?? []);
    }

    public function render(): View
    {
        return view('components.filter-sidebar');
    }
}
```

**Blade Component (components/filter-sidebar.blade.php):**

```blade
<aside class="filter-sidebar">
    <form method="GET" id="filter-form">
        {{-- Preserve existing query params --}}
        <input type="hidden" name="q" value="{{ request('q') }}">

        @foreach($facets as $facetKey => $facetData)
            <div class="filter-group">
                <h4>{{ $facetData['name'] }}</h4>

                @if($facetData['type'] === 'range')
                    {{-- Range Filter --}}
                    <div class="range-filter">
                        <label>
                            Min:
                            <input
                                type="number"
                                name="min_{{ $facetKey }}"
                                value="{{ $appliedRangeFilters[$facetKey]['min'] ?? '' }}"
                                min="{{ $facetData['min'] ?? 0 }}"
                                step="0.01"
                            >
                        </label>
                        <label>
                            Max:
                            <input
                                type="number"
                                name="max_{{ $facetKey }}"
                                value="{{ $appliedRangeFilters[$facetKey]['max'] ?? '' }}"
                                max="{{ $facetData['max'] ?? 9999 }}"
                                step="0.01"
                            >
                        </label>
                    </div>
                @else
                    {{-- Checkbox Filter --}}
                    <ul class="filter-options">
                        @foreach($facetData['values'] as $value => $count)
                            <li>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="{{ $facetKey }}[]"
                                        value="{{ $value }}"
                                        {{ $isFilterApplied($facetKey, $value) ? 'checked' : '' }}
                                        onchange="document.getElementById('filter-form').submit()"
                                    >
                                    {{ $value }}
                                    <span class="count">({{ $count }})</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        <button type="submit">Apply Filters</button>
        <a href="{{ url()->current() }}?q={{ request('q') }}">Clear All</a>
    </form>
</aside>
```

---

## 7. Recommendations

### Understanding Pods

Constructor uses "pods" to configure recommendation strategies. Pods are set up in the Constructor dashboard and have unique IDs.

Common pod types:
- **Home page** - Bestsellers, trending, personalized picks
- **PDP (Product Detail Page)** - Similar items, frequently bought together
- **Cart** - Cross-sell recommendations
- **Category** - Top products in category

### Home Page Recommendations

For pods that don't require an item context:

```php
$recommendations = Constructor::getRecommendations('home-bestsellers', [
    'num_results' => 8,
]);

if ($recommendations->hasRecommendations()) {
    echo "<h2>{$recommendations->title}</h2>";

    foreach ($recommendations->products as $product) {
        echo $product['name'];
    }
}
```

### Product Page Recommendations

For pods that need a product context (similar items, etc.):

```php
$similar = Constructor::getItemRecommendations(
    'pdp-similar-items',
    'PRODUCT-SKU-123',  // The current product ID
    ['num_results' => 4]
);
```

### Building a Recommendations Widget

```php
<?php

namespace App\Livewire;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class RecommendationsWidget extends Component
{
    public string $podId;
    public ?string $itemId = null;
    public int $limit = 4;
    public string $title = '';
    public array $products = [];
    public bool $loaded = false;

    public function mount(string $podId, ?string $itemId = null, int $limit = 4): void
    {
        $this->podId = $podId;
        $this->itemId = $itemId;
        $this->limit = $limit;
    }

    public function loadRecommendations(): void
    {
        // Cache key includes pod, item, and user for personalization
        $cacheKey = sprintf(
            'recs:%s:%s:%s',
            $this->podId,
            $this->itemId ?? 'none',
            auth()->id() ?? session()->getId()
        );

        $cached = Cache::get($cacheKey);

        if ($cached) {
            $this->title = $cached['title'];
            $this->products = $cached['products'];
            $this->loaded = true;
            return;
        }

        // Fetch recommendations
        if ($this->itemId) {
            $results = Constructor::getItemRecommendations(
                $this->podId,
                $this->itemId,
                ['num_results' => $this->limit]
            );
        } else {
            $results = Constructor::getRecommendations(
                $this->podId,
                ['num_results' => $this->limit]
            );
        }

        $this->title = $results->title;
        $this->products = $results->products;
        $this->loaded = true;

        // Cache for 15 minutes
        Cache::put($cacheKey, [
            'title' => $this->title,
            'products' => $this->products,
        ], now()->addMinutes(15));
    }

    public function render()
    {
        return view('livewire.recommendations-widget');
    }
}
```

**Blade View:**

```blade
<div
    x-data="{ show: false }"
    x-intersect.once="$wire.loadRecommendations(); show = true"
    class="recommendations-widget"
>
    @if($loaded && !empty($products))
        <h2>{{ $title }}</h2>
        <div class="products-carousel">
            @foreach($products as $product)
                <a href="{{ $product['url'] }}" class="product-card">
                    <img
                        src="{{ $product['image_url'] }}"
                        alt="{{ $product['name'] }}"
                        loading="lazy"
                    >
                    <h3>{{ $product['name'] }}</h3>
                    <span class="price">${{ number_format($product['price'], 2) }}</span>
                </a>
            @endforeach
        </div>
    @elseif(!$loaded)
        <div class="loading-placeholder">
            {{-- Skeleton loader --}}
            @for($i = 0; $i < $limit; $i++)
                <div class="skeleton-card"></div>
            @endfor
        </div>
    @endif
</div>
```

---

## 8. Collections

### Understanding Collections

Collections are curated groups of products (e.g., "Summer Essentials", "Gift Ideas"). They're managed in the Constructor dashboard.

### Get All Collections

```php
$collections = Constructor::getCollections(['max_items' => 10]);

// Returns:
[
    [
        'id' => 'summer-essentials',
        'name' => 'Summer Essentials',
        'description' => 'Beat the heat with...',
        'image' => 'https://...',
    ],
    ...
]
```

### Get Single Collection Metadata

```php
$collection = Constructor::getCollection('summer-essentials');

// Returns:
[
    'id' => 'summer-essentials',
    'name' => 'Summer Essentials',
    'description' => 'Beat the heat with our top picks',
    'image' => 'https://...',
]
```

### Browse Collection Products

```php
$results = Constructor::browseCollection('summer-essentials', [], [
    'page' => 1,
    'per_page' => 24,
]);

// Returns SearchResults with products, facets, etc.
```

### Get Collection Image from Product

If a collection doesn't have a configured image:

```php
$image = Constructor::getFirstProductImageFromCollection('summer-essentials');
```

### Full Collections Page

```php
<?php

namespace App\Http\Controllers;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index()
    {
        $collections = Constructor::getCollections(['max_items' => 20]);

        // Add fallback images
        foreach ($collections as &$collection) {
            if (empty($collection['image'])) {
                $collection['image'] = Constructor::getFirstProductImageFromCollection(
                    $collection['id']
                );
            }
        }

        return view('collections.index', compact('collections'));
    }

    public function show(Request $request, string $collectionId)
    {
        $collection = Constructor::getCollection($collectionId);

        if (!$collection) {
            abort(404);
        }

        $results = Constructor::browseCollection(
            $collectionId,
            $this->parseFilters($request),
            [
                'page' => $request->input('page', 1),
                'per_page' => 24,
            ]
        );

        return view('collections.show', [
            'collection' => $collection,
            'results' => $results,
        ]);
    }

    protected function parseFilters(Request $request): array
    {
        // Same as search filter parsing
        return [];
    }
}
```

---

## 9. AI Agents

Constructor offers two AI agents for enhanced shopping experiences.

### AI Shopping Agent

Natural language product discovery - customers can ask questions like "I need a gift for my mom who likes gardening."

**Requirements:**
- `CONSTRUCTOR_AGENT_DOMAIN` must be configured
- Agent feature enabled in your Constructor account

```php
use ConstructorIO\Laravel\Services\ConstructorAgentService;

$agent = app(ConstructorAgentService::class);

// Initial query
$response = $agent->askShoppingAgent(
    'I need a birthday gift for my teenage daughter'
);

echo $response['message'];  // AI response text
foreach ($response['products'] as $product) {
    echo $product['name'];
}

// Continue conversation with thread_id
$followUp = $agent->askShoppingAgent(
    'Something under $50 that she can use for school',
    $response['thread_id']
);
```

### Streaming Shopping Agent

For real-time UI updates as the AI responds:

```php
$response = $agent->askShoppingAgentStreaming(
    'Find me running shoes for trail running',
    function ($eventType, $data) {
        match($eventType) {
            'start' => $this->onStart($data['thread_id']),
            'message' => $this->onMessage($data['text']),
            'products' => $this->onProducts($data['products']),
            'end' => $this->onComplete(),
            default => null,
        };
    }
);
```

### Product Insights Agent

AI-powered Q&A for product detail pages:

```php
// Get suggested questions
$questions = $agent->getProductQuestions('PRODUCT-123');

// Returns:
['questions' => [
    'Is this true to size?',
    'What materials is it made from?',
    'Is it machine washable?',
]]

// Answer a question
$answer = $agent->askProductQuestion(
    question: 'Is this true to size?',
    itemId: 'PRODUCT-123'
);

echo $answer['answer'];
// "Based on customer reviews, this item runs slightly small..."

// Follow-up questions
$answer['follow_up_questions']
// ['What size should I order if I'm between sizes?', ...]
```

### Full Product Insights Implementation

```php
<?php

namespace App\Livewire;

use ConstructorIO\Laravel\Services\ConstructorAgentService;
use Livewire\Component;

class ProductQA extends Component
{
    public string $productId;
    public array $suggestedQuestions = [];
    public string $currentQuestion = '';
    public string $answer = '';
    public array $followUpQuestions = [];
    public ?string $threadId = null;
    public bool $loading = false;
    public ?string $error = null;

    protected ConstructorAgentService $agent;

    public function boot(ConstructorAgentService $agent): void
    {
        $this->agent = $agent;
    }

    public function mount(string $productId): void
    {
        $this->productId = $productId;
        $this->loadSuggestedQuestions();
    }

    public function loadSuggestedQuestions(): void
    {
        try {
            $response = $this->agent->getProductQuestions($this->productId);
            $this->suggestedQuestions = $response['questions'];
        } catch (\Exception $e) {
            // Silently fail - questions are optional
            $this->suggestedQuestions = [];
        }
    }

    public function askQuestion(string $question): void
    {
        $this->loading = true;
        $this->error = null;
        $this->currentQuestion = $question;
        $this->answer = '';
        $this->followUpQuestions = [];

        try {
            $response = $this->agent->askProductQuestion(
                question: $question,
                itemId: $this->productId,
                threadId: $this->threadId
            );

            $this->answer = $response['answer'];
            $this->followUpQuestions = $response['follow_up_questions'];
            $this->threadId = $response['thread_id'];
        } catch (\Exception $e) {
            $this->error = 'Sorry, we couldn\'t answer that question. Please try again.';
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.product-qa');
    }
}
```

---

## 10. Catalog Management

### Upload Catalog Files

Replace or update your entire product catalog:

```php
use ConstructorIO\Laravel\Services\ConstructorService;

$service = app(ConstructorService::class);

// Full replacement
$result = $service->uploadCatalog(
    storage_path('app/catalog/items.csv'),
    'create_or_replace'
);

// Partial update (patch)
$result = $service->uploadCatalog(
    storage_path('app/catalog/updates.csv'),
    'patch'
);

// With options
$result = $service->uploadCatalog(
    $filePath,
    'create_or_replace',
    [
        'force' => true,  // Skip validation warnings
        'section' => 'Products',
        'item_groups_file' => storage_path('app/catalog/categories.csv'),
    ]
);
```

### CSV Format

**Items CSV:**
```csv
id,name,url,image_url,description,price,brand,categories
SKU-001,"Blue Running Shoes","/products/blue-shoes","https://...",Running shoes...,99.99,Nike,"Shoes|Running"
SKU-002,"Red T-Shirt","/products/red-shirt","https://...",Cotton t-shirt...,29.99,Adidas,"Clothing|Tops"
```

**Categories CSV (item_groups):**
```csv
id,name,parent_id
shoes,Shoes,
running,Running,shoes
clothing,Clothing,
tops,Tops,clothing
```

### Monitor Upload Status

```php
$result = $service->uploadCatalog($filePath, 'create_or_replace');
$taskId = $result['task_id'];

// Check status
$status = $service->getTaskStatus($taskId);
echo $status['status'];  // PENDING, PROCESSING, DONE, FAILED

// Wait for completion (blocking)
$finalStatus = $service->waitForTaskCompletion(
    $taskId,
    maxAttempts: 60,
    delaySeconds: 10
);

if ($finalStatus['successful']) {
    echo "Upload complete!";
} else {
    echo "Upload failed: " . ($finalStatus['error'] ?? 'Unknown error');
}
```

### Background Job for Catalog Sync

```php
<?php

namespace App\Jobs;

use App\Models\Product;
use ConstructorIO\Laravel\Services\ConstructorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncCatalogToConstructor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;  // 10 minutes
    public int $tries = 1;

    public function handle(ConstructorService $constructor): void
    {
        Log::info('Starting Constructor catalog sync');

        // Generate CSV
        $csvPath = $this->generateCatalogCsv();

        try {
            // Upload to Constructor
            $result = $constructor->uploadCatalog(
                $csvPath,
                'create_or_replace'
            );

            // Wait for completion
            $status = $constructor->waitForTaskCompletion(
                $result['task_id'],
                maxAttempts: 30,
                delaySeconds: 20
            );

            if ($status['successful']) {
                Log::info('Constructor catalog sync completed', [
                    'task_id' => $result['task_id'],
                ]);
            } else {
                Log::error('Constructor catalog sync failed', [
                    'task_id' => $result['task_id'],
                    'status' => $status,
                ]);
            }
        } finally {
            // Clean up temp file
            unlink($csvPath);
        }
    }

    protected function generateCatalogCsv(): string
    {
        $path = storage_path('app/temp/catalog_' . time() . '.csv');

        $handle = fopen($path, 'w');

        // Header
        fputcsv($handle, [
            'id', 'name', 'url', 'image_url', 'description',
            'price', 'brand', 'categories', 'in_stock'
        ]);

        // Products in chunks
        Product::where('active', true)
            ->chunk(1000, function ($products) use ($handle) {
                foreach ($products as $product) {
                    fputcsv($handle, [
                        $product->sku,
                        $product->name,
                        route('products.show', $product),
                        $product->primary_image_url,
                        strip_tags($product->description),
                        $product->price,
                        $product->brand->name ?? '',
                        $product->categories->pluck('name')->implode('|'),
                        $product->in_stock ? 'true' : 'false',
                    ]);
                }
            });

        fclose($handle);

        return $path;
    }
}
```

---

## 11. Recipes

If your Constructor index includes a "Recipes" section:

### Check Support

```php
if (Constructor::supportsRecipes()) {
    // Recipes are available
}
```

### Search Recipes

```php
$results = Constructor::searchRecipes('chicken pasta', [], [
    'page' => 1,
    'per_page' => 12,
]);

foreach ($results->products as $recipe) {
    echo $recipe['name'];
    echo $recipe['_raw']['cook_time'];  // Custom fields in _raw
}
```

### Browse Recipes by Category

```php
$results = Constructor::browseRecipes('meal_type', 'dinner');
$results = Constructor::browseRecipes('cuisine', 'Italian');
```

### Get Single Recipe

```php
$recipe = Constructor::getRecipe('recipe-123');

if ($recipe) {
    echo $recipe['name'];
    echo $recipe['_raw']['ingredients'];
    echo $recipe['_raw']['instructions'];
}
```

---

## 12. Laravel Scout Integration

### Configuration

In `config/scout.php`:

```php
return [
    'driver' => env('SCOUT_DRIVER', 'constructor'),

    'constructor' => [
        'api_key' => env('CONSTRUCTOR_API_KEY'),
        'api_token' => env('CONSTRUCTOR_API_TOKEN'),
    ],
];
```

### Making Models Searchable

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    /**
     * Get the Constructor section name for this model.
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
            'id' => $this->sku,  // Constructor requires 'id'
            'name' => $this->name,
            'description' => $this->description,
            'url' => route('products.show', $this),
            'image_url' => $this->primary_image_url,
            'price' => $this->price,
            'brand' => $this->brand?->name,
            'categories' => $this->categories->pluck('name')->toArray(),
            'in_stock' => $this->in_stock,
            'created_at' => $this->created_at->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->active && $this->price > 0;
    }
}
```

### Searching with Scout

```php
// Basic search
$products = Product::search('blue shoes')->get();

// With filters
$products = Product::search('shoes')
    ->where('brand', 'Nike')
    ->where('in_stock', true)
    ->get();

// Paginated
$products = Product::search('shoes')->paginate(24);

// Get raw results (includes facets)
$results = Product::search('shoes')->raw();
$facets = $results['facets'] ?? [];
```

### Indexing Commands

```bash
# Import all models
php artisan scout:import "App\Models\Product"

# Flush and re-import
php artisan scout:flush "App\Models\Product"
php artisan scout:import "App\Models\Product"
```

---

## 13. DTOs Reference

### SearchResults

```php
class SearchResults
{
    // Properties
    public readonly array $products;   // Product data arrays
    public readonly int $total;        // Total matching results
    public readonly int $page;         // Current page (1-indexed)
    public readonly int $perPage;      // Results per page
    public readonly array $facets;     // Facet data
    public readonly array $groups;     // Category groups
    public readonly array $metadata;   // Request/result IDs

    // Methods
    public function hasMore(): bool;        // More pages?
    public function totalPages(): int;      // Total page count
    public function isEmpty(): bool;        // No products?
    public function count(): int;           // Products this page
    public function getOffset(): int;       // Start index
    public function nextPageNumber(): int;  // Next page or 0
    public function toArray(): array;       // JSON serializable

    // Static constructors
    public static function empty(): self;   // Empty results
}
```

### AutocompleteResults

```php
class AutocompleteResults
{
    // Properties
    public readonly array $suggestions;     // Search suggestions
    public readonly array $products;        // Product previews
    public readonly array $categories;      // Category matches
    public readonly array $trending;        // Trending (zero-state)
    public readonly array $popularProducts; // Popular (zero-state)
    public readonly array $topCategories;   // Categories (zero-state)
    public readonly array $metadata;

    // Methods
    public function hasSuggestions(): bool;
    public function hasProducts(): bool;
    public function hasCategories(): bool;
    public function hasZeroStateData(): bool;
    public function hasTrending(): bool;
    public function hasPopularProducts(): bool;
    public function hasTopCategories(): bool;
    public function isEmpty(): bool;
    public function isAutocompleteEmpty(): bool;  // Ignores zero-state
    public function toArray(): array;

    // Static constructors
    public static function empty(): self;
    public static function zeroState(
        array $trending = [],
        array $popularProducts = [],
        array $topCategories = [],
        array $metadata = []
    ): self;
}
```

### RecommendationResults

```php
class RecommendationResults
{
    // Properties
    public readonly string $podId;
    public readonly string $title;
    public readonly array $products;
    public readonly int $total;
    public readonly array $metadata;

    // Methods
    public function isEmpty(): bool;
    public function hasRecommendations(): bool;
    public function count(): int;
    public function toArray(): array;

    // Static constructors
    public static function empty(string $podId = '', string $title = ''): self;
}
```

---

## 14. Error Handling

### Search/Browse Errors

Search operations return empty results on error (logged internally):

```php
$results = Constructor::search('query');

if ($results->isEmpty()) {
    // Could be no matches OR an error
    // Check logs for actual errors
}
```

### Agent Errors

Agent operations throw exceptions for error handling:

```php
try {
    $response = $agent->askShoppingAgent($query);
} catch (\Exception $e) {
    // Common exceptions:
    // - "Agent domain is required..."
    // - "Constructor Agent API rate limit exceeded..."
    // - "Constructor Agent API authentication failed..."

    Log::error('Shopping Agent error', [
        'message' => $e->getMessage(),
        'query' => $query,
    ]);

    // Show user-friendly message
    return response()->json([
        'error' => 'Unable to process your request. Please try again.',
    ], 503);
}
```

### Catalog Upload Errors

```php
try {
    $result = $service->uploadCatalog($path, 'create_or_replace');
} catch (\Exception $e) {
    // Common exceptions:
    // - "Catalog file not found or not readable..."
    // - "Rate limit exceeded..."
    // - "Authentication failed..."
    // - "Constructor.io API request failed..."
}
```

### Graceful Degradation

```php
// Recommendations with fallback
public function getHomeRecommendations(): array
{
    try {
        $recs = Constructor::getRecommendations('home-bestsellers');
        if ($recs->hasRecommendations()) {
            return $recs->products;
        }
    } catch (\Exception $e) {
        Log::warning('Recommendations failed', ['error' => $e->getMessage()]);
    }

    // Fallback to database
    return Product::bestsellers()->limit(8)->get()->toArray();
}
```

---

## 15. Testing Your Integration

### Manual Testing with Tinker

```bash
php artisan tinker
```

```php
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\ConstructorAgentService;

// Test search
Constructor::search('test')->total

// Test browse
Constructor::browse('group_id', 'your-category')->total

// Test autocomplete
Constructor::autocomplete('blu')->suggestions

// Test recommendations
Constructor::getRecommendations('your-pod-id')->products

// Test agent (if configured)
app(ConstructorAgentService::class)->getProductQuestions('product-id')
```

### Unit Testing with Mocks

```php
<?php

namespace Tests\Feature;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Facades\Constructor;
use Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_search_page_shows_results()
    {
        Constructor::shouldReceive('search')
            ->once()
            ->with('shoes', [], \Mockery::any())
            ->andReturn(new SearchResults(
                products: [
                    ['id' => '1', 'name' => 'Nike Shoes', 'price' => 99.99],
                    ['id' => '2', 'name' => 'Adidas Shoes', 'price' => 89.99],
                ],
                total: 2,
                page: 1,
                perPage: 24,
                facets: [
                    'brand' => [
                        'name' => 'Brand',
                        'type' => 'checkbox_list',
                        'values' => ['Nike' => 1, 'Adidas' => 1],
                    ],
                ],
            ));

        $response = $this->get('/search?q=shoes');

        $response->assertStatus(200);
        $response->assertSee('Nike Shoes');
        $response->assertSee('$99.99');
    }

    public function test_search_handles_empty_results()
    {
        Constructor::shouldReceive('search')
            ->once()
            ->andReturn(SearchResults::empty());

        $response = $this->get('/search?q=nonexistent');

        $response->assertStatus(200);
        $response->assertSee('No products found');
    }
}
```

### Testing Agent Service

```php
<?php

namespace Tests\Feature;

use ConstructorIO\Laravel\Services\ConstructorAgentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function test_shopping_agent_returns_products()
    {
        Http::fake([
            'agent.cnstrc.com/*' => Http::response([
                'thread_id' => 'thread-123',
                'text' => 'Here are some suggestions...',
                'products' => [
                    ['value' => 'Product 1', 'data' => ['id' => '1']],
                ],
            ]),
        ]);

        $agent = app(ConstructorAgentService::class);
        $response = $agent->askShoppingAgent('test query');

        $this->assertNotEmpty($response['products']);
        $this->assertNotEmpty($response['message']);
    }
}
```

---

## 16. Best Practices

### Caching

Cache recommendations and category navigation:

```php
use Illuminate\Support\Facades\Cache;

// Cache categories (changes infrequently)
$categories = Cache::remember(
    'constructor:categories',
    now()->addHours(1),
    fn() => Constructor::getBrowseGroups(['max_items' => 20])
);

// Cache recommendations per user (shorter TTL)
$recs = Cache::remember(
    "recs:{$podId}:" . auth()->id(),
    now()->addMinutes(15),
    fn() => Constructor::getRecommendations($podId)
);
```

### User Personalization

Always pass user context for better results:

```php
$options = [
    'user_id' => auth()->id(),
    'session_id' => session()->getId(),
];

$results = Constructor::search($query, $filters, $options);
```

### Lazy Loading Recommendations

Load recommendations after page render:

```blade
<div
    x-data="{ loaded: false }"
    x-intersect.once="$wire.loadRecommendations()"
>
    @if($loaded)
        {{-- Show recommendations --}}
    @else
        {{-- Skeleton loader --}}
    @endif
</div>
```

### Error Boundaries

Wrap external calls in try-catch with fallbacks:

```php
public function getSearchResults(string $query): SearchResults
{
    try {
        return Constructor::search($query);
    } catch (\Exception $e) {
        Log::error('Search failed', ['error' => $e->getMessage()]);
        return SearchResults::empty();
    }
}
```

### Request Deduplication

Prevent duplicate API calls on rapid input:

```php
// Livewire with debounce
<input wire:model.live.debounce.300ms="query">

// Alpine.js with debounce
<input x-model.debounce.300ms="query">
```

### Structured Logging

Log Constructor requests for debugging:

```php
Log::info('Constructor search', [
    'query' => $query,
    'filters' => $filters,
    'results' => $results->total,
    'time_ms' => $elapsed,
]);
```

---

## 17. Backend Integration

When your Laravel application makes Constructor API calls server-side on behalf of browser users, Constructor requires specific HTTP headers and query parameters to properly track sessions, personalize results, and attribute analytics.

This package handles this automatically via the `BackendRequestContext` trait, which is used by both `ConstructorSandboxSearch` and `ConstructorAgentService`.

### Configuration

Add these optional environment variables to your `.env`:

```env
# Backend authentication token (sent as x-cnstrc-token header)
# Falls back to CONSTRUCTOR_API_TOKEN if not set
CONSTRUCTOR_BACKEND_TOKEN=tok_your_backend_token

# Client identifier for the 'c' query parameter
# Format: cio-be-laravel-{company-name}
# Auto-generates from app.name if not set
CONSTRUCTOR_CLIENT_IDENTIFIER=cio-be-laravel-your-company
```

### What Gets Forwarded Automatically

**HTTP Headers** (added to every API request):

| Header | Source | Purpose |
|--------|--------|---------|
| `X-Forwarded-For` | `request()->ip()` | Geolocation & fraud detection |
| `User-Agent` | `request()->userAgent()` | Device/browser analytics |
| `x-cnstrc-token` | Config `backend_token` or `api_token` | Backend authentication |

**Query Parameters** (added to search, browse, autocomplete, recommendations, and agent requests):

| Param | Source | Purpose |
|-------|--------|---------|
| `c` | Config `client_identifier` or auto-generated | Client identification |
| `i` | `ConstructorioID_client_id` cookie | Browser instance tracking |
| `s` | `ConstructorioID_session_id` cookie | Session tracking |
| `_dt` | `microtime(true) * 1000` | Request timestamp |
| `origin_referrer` | `Referer` header | Referrer attribution |

### Cookie Reading

Constructor's JavaScript SDK sets `ConstructorioID_client_id` and `ConstructorioID_session_id` cookies in the browser. These are read directly from `$_COOKIE` (bypassing Laravel's `EncryptCookies` middleware) because they are not encrypted by Laravel.

### Non-HTTP Contexts

All backend integration methods are safe to use in non-HTTP contexts (CLI commands, queue workers, `php artisan tinker`, tests). When no HTTP request is available:

- Headers that depend on `request()` are simply omitted
- Cookie values return `null` and are skipped
- The `_dt` timestamp and `c` client identifier are always included
- No exceptions are thrown

```php
// This works fine in tinker or a queue job — params are gracefully omitted
$results = Constructor::search('shoes');
```

### Backward Compatibility

- Existing deployments without `CONSTRUCTOR_BACKEND_TOKEN` will use `CONSTRUCTOR_API_TOKEN` as fallback
- Existing deployments without `CONSTRUCTOR_CLIENT_IDENTIFIER` will auto-generate from `app.name`
- Parameters explicitly passed via `$options` (e.g., `user_id`, `session_id`, `client`) take precedence over auto-detected values

---

## 18. Troubleshooting

### Configuration Issues

**"Constructor.io configuration not properly set"**

Ensure all environment variables are set:
```env
CONSTRUCTOR_API_KEY=key_xxxxx
CONSTRUCTOR_API_TOKEN=tok_xxxxx
```

And config is published:
```bash
php artisan config:clear
php artisan vendor:publish --provider="ConstructorIO\Laravel\ConstructorServiceProvider"
```

### Search Issues

**Empty results when products should exist**

1. Verify API key in Constructor dashboard
2. Check the section name (default: 'Products')
3. Test in Constructor's playground first
4. Check Laravel logs for API errors

**Facets not appearing**

- Facets must be configured in Constructor dashboard
- Check that products have the facet data populated
- Some facets only appear with enough diverse values

**Wrong products returned**

- Check if filters are being applied incorrectly
- Verify the `group_id` matches your Constructor setup
- Category IDs in Constructor may be URL-encoded

### Recommendation Issues

**Empty recommendations**

1. Verify pod ID exists in Constructor dashboard
2. Some pods need `item_id` - use `getItemRecommendations()`
3. Pod may not have enough training data yet
4. Check if pod is enabled for your environment

**Wrong recommendation type**

- Use `getRecommendations()` for home page pods
- Use `getItemRecommendations()` for PDP pods
- Check pod configuration in dashboard

### Agent Issues

**"Agent domain is required"**

Set the agent domain:
```env
CONSTRUCTOR_AGENT_DOMAIN=your-store
```

**"Authentication failed"**

- Verify API key is correct
- Check agent domain matches your Constructor account
- Ensure agent feature is enabled

**Rate limit errors**

- Implement caching
- Add retry logic with backoff
- Contact Constructor for limit increases

### Catalog Upload Issues

**"Catalog file not found"**

- Use absolute path: `storage_path('app/catalog/items.csv')`
- Check file permissions

**Upload fails silently**

- Check task status: `$service->getTaskStatus($taskId)`
- Look for validation errors in task details
- Verify CSV format matches Constructor requirements

**"Authentication failed" on upload**

- Catalog uploads use API token (not API key)
- Token should start with `tok_`
- Check token has write permissions

### Performance Issues

**Slow search responses**

- Enable caching for repeated queries
- Reduce `per_page` for faster initial load
- Use lazy loading for below-fold content

**High API usage**

- Cache category navigation
- Cache recommendations (15 min TTL)
- Debounce autocomplete requests

### Debugging Tips

1. **Check Laravel logs**: `tail -f storage/logs/laravel.log`
2. **Enable debug logging in Constructor service** (check source code for log calls)
3. **Test API directly**: Use Constructor's API playground
4. **Verify config**: `php artisan config:show constructor`
5. **Test in Tinker**: Quick API verification

---

## Need Help?

- [Constructor.io Documentation](https://docs.constructor.io/)
- [Constructor.io API Reference](https://docs.constructor.com/reference/)
- [Package Issues](https://github.com/your-repo/issues)
