<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Data Transfer Object for search results from any search provider.
 *
 * This DTO provides a consistent structure for search results regardless
 * of which search backend (Meilisearch, Constructor, Algolia) is used.
 *
 * @example
 * // Basic usage
 * $results = $search->search('shoes');
 * foreach ($results->products as $product) {
 *     echo $product['name'];
 *     echo $product['price'];
 * }
 *
 * // Pagination
 * echo "Page {$results->page} of {$results->totalPages()}";
 * if ($results->hasMore()) {
 *     $nextPage = $results->nextPageNumber();
 * }
 *
 * // Access facets for filtering UI
 * foreach ($results->facets as $facetName => $facetData) {
 *     echo $facetData['name']; // Display name
 *     foreach ($facetData['values'] as $value => $count) {
 *         echo "{$value} ({$count})";
 *     }
 * }
 *
 * // JSON serialization
 * return response()->json($results->toArray());
 */
class SearchResults
{
    /**
     * @param array $products Array of product data
     * @param int $total Total number of matching results
     * @param int $page Current page number (1-indexed)
     * @param int $perPage Number of results per page
     * @param array $facets Available facets/filters with counts
     * @param array $metadata Provider-specific metadata
     * @param array $groups Category groups with hierarchy (for browse responses)
     */
    public function __construct(
        public readonly array $products,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $facets = [],
        public readonly array $metadata = [],
        public readonly array $groups = [],
    ) {}

    /**
     * Check if there are more results available.
     */
    public function hasMore(): bool
    {
        return ($this->page * $this->perPage) < $this->total;
    }

    /**
     * Get the total number of pages.
     */
    public function totalPages(): int
    {
        if ($this->perPage <= 0) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * Check if results are empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->products);
    }

    /**
     * Get the number of results in this page.
     */
    public function count(): int
    {
        return count($this->products);
    }

    /**
     * Get the offset (number of items before this page).
     *
     * Useful for displaying "Showing 25-48 of 156 results".
     */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Get the next page number, or 0 if no more pages.
     */
    public function nextPageNumber(): int
    {
        return $this->hasMore() ? $this->page + 1 : 0;
    }

    /**
     * Get all results as an array for JSON serialization.
     *
     * @return array{
     *     products: array,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     facets: array,
     *     groups: array,
     *     metadata: array
     * }
     */
    public function toArray(): array
    {
        return [
            'products' => $this->products,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'facets' => $this->facets,
            'groups' => $this->groups,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create an empty SearchResults instance.
     */
    public static function empty(): self
    {
        return new self(
            products: [],
            total: 0,
            page: 1,
            perPage: 24,
        );
    }
}
