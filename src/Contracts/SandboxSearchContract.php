<?php

namespace ConstructorIO\Laravel\Contracts;

use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;

/**
 * Contract for search providers.
 *
 * This interface defines the methods that all search providers must implement.
 * This allows swapping between different search backends (Meilisearch, Constructor,
 * Algolia, etc.) without changing the consuming code.
 */
interface SandboxSearchContract
{
    /**
     * Search products by query string.
     *
     * @param string $query The search query
     * @param array $filters Key-value pairs of filters to apply
     * @param array $options Additional options (page, per_page, sort_by, sort_order)
     * @return SearchResults
     */
    public function search(string $query, array $filters = [], array $options = []): SearchResults;

    /**
     * Browse products by a specific filter/facet value.
     *
     * Used for category/group browsing where you want all products
     * matching a specific facet value.
     *
     * @param string $filterName The filter/facet name (e.g., 'group_ids', 'brand')
     * @param string $filterValue The filter value to match
     * @param array $filters Additional filters to apply
     * @param array $options Additional options (page, per_page, sort_by, sort_order)
     * @return SearchResults
     */
    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;

    /**
     * Get autocomplete suggestions for a query.
     *
     * @param string $query The partial query to get suggestions for
     * @param array $options Additional options including:
     *   - num_results: Number of results per section
     *   - sections: Array of section configs (suggestions, products, categories)
     *   - filters: Additional filters to apply
     * @return AutocompleteResults
     */
    public function autocomplete(string $query, array $options = []): AutocompleteResults;

    /**
     * Get zero-state data for autocomplete (trending, popular items, top categories).
     *
     * Used when autocomplete is opened but no query has been entered yet.
     *
     * @param array $options Additional options including:
     *   - show_trending: Whether to include trending searches
     *   - show_popular_products: Whether to include popular products
     *   - show_top_categories: Whether to include top categories
     *   - trending_limit: Max trending searches to return
     *   - products_limit: Max popular products to return
     *   - categories_limit: Max categories to return
     * @return AutocompleteResults Contains trending, popularProducts, and topCategories
     */
    public function getZeroStateData(array $options = []): AutocompleteResults;

    /**
     * Check if this provider supports zero-state autocomplete data.
     *
     * @return bool True if zero-state data is supported
     */
    public function supportsZeroState(): bool;

    /**
     * Get available facets/filters for the current search context.
     *
     * @param string $query The search query (empty for all products)
     * @param array $filters Currently applied filters
     * @return array Facet distribution data
     */
    public function getFacets(string $query, array $filters = []): array;

    /**
     * Get the display name of this search provider.
     *
     * @return string Provider name (e.g., 'Meilisearch', 'Constructor', 'Algolia')
     */
    public function getProviderName(): string;

    /**
     * Get recommendations from a recommendation pod.
     *
     * Used for home page recommendations or general recommendation pods
     * that don't require an item context.
     *
     * @param string $podId The recommendation pod ID (e.g., 'home_page_1')
     * @param array $options Additional options (num_results, filters)
     * @return RecommendationResults
     */
    public function getRecommendations(string $podId, array $options = []): RecommendationResults;

    /**
     * Get item-based recommendations from a recommendation pod.
     *
     * Used for product page recommendations where you want products
     * similar to or complementary to a specific item.
     *
     * @param string $podId The recommendation pod ID (e.g., 'item_page_2')
     * @param string $itemId The item ID to get recommendations for
     * @param array $options Additional options (num_results, filters)
     * @return RecommendationResults
     */
    public function getItemRecommendations(string $podId, string $itemId, array $options = []): RecommendationResults;

    /**
     * Check if this provider supports recommendations.
     *
     * @return bool True if recommendations are supported
     */
    public function supportsRecommendations(): bool;

    /**
     * Get browse groups (categories) from the search provider.
     *
     * Used for displaying category navigation on home pages.
     *
     * @param array $options Additional options (max_items, parent_id)
     * @return array Array of category/group objects with id, name, and optional image
     */
    public function getBrowseGroups(array $options = []): array;

    /**
     * Get collections from the search provider.
     *
     * @param array $options Additional options (max_items)
     * @return array Array of collection objects with id, name, and optional image
     */
    public function getCollections(array $options = []): array;

    /**
     * Check if this provider supports browse groups.
     *
     * @return bool True if browse groups are supported
     */
    public function supportsBrowseGroups(): bool;

    /**
     * Check if this provider supports collections.
     *
     * @return bool True if collections are supported
     */
    public function supportsCollections(): bool;

    /**
     * Browse products within a collection.
     *
     * Used for displaying products in a curated collection.
     * Collections are different from browse groups - they are
     * separately managed curated product lists.
     *
     * @param string $collectionId The collection ID
     * @param array $filters Additional filters to apply
     * @param array $options Additional options (page, per_page)
     * @return SearchResults
     */
    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults;

    /**
     * Get a single collection's metadata.
     *
     * @param string $collectionId The collection ID
     * @return array|null Collection data (id, name, description, image) or null if not found
     */
    public function getCollection(string $collectionId): ?array;

    /**
     * Get the first product image from a collection.
     *
     * Used as a fallback when no collection tile image is configured.
     *
     * @param string $collectionId The collection ID
     * @return string|null The image URL or null if no image found
     */
    public function getFirstProductImageFromCollection(string $collectionId): ?string;

    /**
     * Get available facets for configuration.
     *
     * Returns a list of all available facets that can be used for
     * "Shop by Facet" sections on the home page.
     *
     * @return array Array of ['name' => string, 'display_name' => string, 'type' => string]
     */
    public function getAvailableFacets(): array;

    /**
     * Get facet values with sample product images.
     *
     * Used for displaying facet values as tiles on the home page.
     * Returns the top N facet values sorted by product count, along
     * with a sample product image for each value.
     *
     * @param string $facetName The facet to get values for (e.g., 'brand', 'product_type')
     * @param int $maxItems Maximum number of values to return
     * @return array Array of ['value' => string, 'display_name' => string, 'count' => int, 'image' => ?string]
     */
    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10): array;

    /**
     * Search recipes using the Recipes section.
     *
     * @param string $query The search query
     * @param array $filters Key-value pairs of filters to apply
     * @param array $options Additional options (page, per_page, sort_by, sort_order)
     * @return SearchResults
     */
    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults;

    /**
     * Browse recipes by a specific filter/facet value.
     *
     * @param string $filterName The filter/facet name (e.g., 'group_ids', 'category')
     * @param string $filterValue The filter value to match
     * @param array $filters Additional filters to apply
     * @param array $options Additional options (page, per_page, sort_by, sort_order)
     * @return SearchResults
     */
    public function browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;

    /**
     * Get a single recipe by ID.
     *
     * @param string $recipeId The recipe ID
     * @return array|null Recipe data or null if not found
     */
    public function getRecipe(string $recipeId): ?array;

    /**
     * Check if this provider supports recipes.
     *
     * @return bool True if recipes are supported
     */
    public function supportsRecipes(): bool;
}
