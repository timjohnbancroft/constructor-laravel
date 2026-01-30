<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Data Transfer Object for autocomplete results from any search provider.
 *
 * This DTO provides a consistent structure for autocomplete suggestions
 * regardless of which search backend (Meilisearch, Constructor, Algolia) is used.
 *
 * @example
 * // Autocomplete as user types
 * $results = $search->autocomplete('blu');
 *
 * // Show search suggestions
 * if ($results->hasSuggestions()) {
 *     foreach ($results->suggestions as $suggestion) {
 *         echo $suggestion['term']; // "blue shirt", "blue jeans"
 *     }
 * }
 *
 * // Show product previews
 * if ($results->hasProducts()) {
 *     foreach ($results->products as $product) {
 *         echo $product['name'];
 *         echo $product['image_url'];
 *     }
 * }
 *
 * // Zero-state (search box focused but empty)
 * $zeroState = $search->getZeroStateData();
 * if ($zeroState->hasZeroStateData()) {
 *     foreach ($zeroState->topCategories as $category) {
 *         echo $category['name'];
 *     }
 *     foreach ($zeroState->popularProducts as $product) {
 *         echo $product['name'];
 *     }
 * }
 *
 * // JSON serialization
 * return response()->json($results->toArray());
 */
class AutocompleteResults
{
    /**
     * @param  array  $suggestions  Array of search term suggestions
     * @param  array  $products  Array of product suggestions
     * @param  array  $categories  Array of category suggestions
     * @param  array  $trending  Array of trending searches (for zero-state)
     * @param  array  $popularProducts  Array of popular products (for zero-state)
     * @param  array  $topCategories  Array of top categories (for zero-state)
     * @param  array  $metadata  Provider-specific metadata
     */
    public function __construct(
        public readonly array $suggestions = [],
        public readonly array $products = [],
        public readonly array $categories = [],
        public readonly array $trending = [],
        public readonly array $popularProducts = [],
        public readonly array $topCategories = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Check if there are any suggestions.
     */
    public function hasSuggestions(): bool
    {
        return ! empty($this->suggestions);
    }

    /**
     * Check if there are any product suggestions.
     */
    public function hasProducts(): bool
    {
        return ! empty($this->products);
    }

    /**
     * Check if there are any category suggestions.
     */
    public function hasCategories(): bool
    {
        return ! empty($this->categories);
    }

    /**
     * Check if there is any zero-state data.
     */
    public function hasZeroStateData(): bool
    {
        return ! empty($this->trending)
            || ! empty($this->popularProducts)
            || ! empty($this->topCategories);
    }

    /**
     * Check if there are any trending searches.
     */
    public function hasTrending(): bool
    {
        return ! empty($this->trending);
    }

    /**
     * Check if there are any popular products.
     */
    public function hasPopularProducts(): bool
    {
        return ! empty($this->popularProducts);
    }

    /**
     * Check if there are any top categories.
     */
    public function hasTopCategories(): bool
    {
        return ! empty($this->topCategories);
    }

    /**
     * Check if all results are empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->suggestions)
            && empty($this->products)
            && empty($this->categories)
            && ! $this->hasZeroStateData();
    }

    /**
     * Check if autocomplete results (non-zero-state) are empty.
     */
    public function isAutocompleteEmpty(): bool
    {
        return empty($this->suggestions)
            && empty($this->products)
            && empty($this->categories);
    }

    /**
     * Get all results as an array for easy JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'suggestions' => $this->suggestions,
            'products' => $this->products,
            'categories' => $this->categories,
            'trending' => $this->trending,
            'popularProducts' => $this->popularProducts,
            'topCategories' => $this->topCategories,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create an empty AutocompleteResults instance.
     */
    public static function empty(): self
    {
        return new self(
            suggestions: [],
            products: [],
            categories: [],
            trending: [],
            popularProducts: [],
            topCategories: [],
        );
    }

    /**
     * Create a zero-state AutocompleteResults instance.
     */
    public static function zeroState(
        array $trending = [],
        array $popularProducts = [],
        array $topCategories = [],
        array $metadata = []
    ): self {
        return new self(
            suggestions: [],
            products: [],
            categories: [],
            trending: $trending,
            popularProducts: $popularProducts,
            topCategories: $topCategories,
            metadata: $metadata,
        );
    }
}
