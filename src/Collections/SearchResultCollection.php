<?php

namespace ConstructorIO\Laravel\Collections;

use Illuminate\Support\Collection;

/**
 * Custom collection for search results from Constructor.io.
 *
 * This collection extends Laravel's base Collection class and adds
 * methods for tracking search metadata like total results, facets,
 * and sort options. It provides a fluent interface for setting and
 * retrieving search metadata alongside the actual result items.
 *
 * Used by the ConstructorEngine for Laravel Scout integration.
 *
 * @example
 * // Create from search results
 * $collection = new SearchResultCollection($products);
 * $collection->setTotal(156)->setFacets($facets);
 *
 * // Access metadata
 * $total = $collection->getTotal();
 * $facets = $collection->getFacets();
 *
 * // Iterate items (inherited from Collection)
 * foreach ($collection as $product) {
 *     echo $product['name'];
 * }
 */
class SearchResultCollection extends Collection
{
    /**
     * The total number of results across all pages.
     */
    protected int $total = 0;

    /**
     * The facets from the search results.
     *
     * @var array<string, array{name: string, values: array<string, int>, type: string}>
     */
    protected array $facets = [];

    /**
     * The sort options from the search results.
     *
     * @var array<int, array{sort_by: string, sort_order: string, display_name: string}>
     */
    protected array $sortOptions = [];

    /**
     * Set the total number of results.
     *
     * @param  int  $total  Total number of results across all pages
     */
    public function setTotal(int $total): self
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Get the total number of results.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Set the facets.
     *
     * @param  array<string, array{name: string, values: array<string, int>, type: string}>  $facets  Facets with their options and counts
     */
    public function setFacets(array $facets): self
    {
        $this->facets = $facets;

        return $this;
    }

    /**
     * Get the facets.
     *
     * @return array<string, array{name: string, values: array<string, int>, type: string}>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    /**
     * Set the sort options.
     *
     * @param  array<int, array{sort_by: string, sort_order: string, display_name: string}>  $sortOptions  Available sort options
     */
    public function setSortOptions(array $sortOptions): self
    {
        $this->sortOptions = $sortOptions;

        return $this;
    }

    /**
     * Get the sort options.
     *
     * @return array<int, array{sort_by: string, sort_order: string, display_name: string}>
     */
    public function getSortOptions(): array
    {
        return $this->sortOptions;
    }
}
