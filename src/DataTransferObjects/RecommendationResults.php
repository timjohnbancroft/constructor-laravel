<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Data Transfer Object for recommendation results from any search provider.
 *
 * This DTO provides a consistent structure for recommendation results regardless
 * of which search backend (Meilisearch, Constructor, Algolia) is used.
 *
 * @example
 * // Get recommendations for a product page
 * $recommendations = $search->getItemRecommendations('pdp-similar', 'PRODUCT-123');
 *
 * if ($recommendations->hasRecommendations()) {
 *     echo "<h2>{$recommendations->title}</h2>";
 *     foreach ($recommendations->products as $product) {
 *         echo $product['name'];
 *         echo $product['price'];
 *     }
 * }
 *
 * // Home page recommendations
 * $featured = $search->getRecommendations('home-bestsellers');
 * echo "Showing {$featured->count()} of {$featured->total} products";
 *
 * // JSON serialization
 * return response()->json($recommendations->toArray());
 */
class RecommendationResults
{
    /**
     * @param string $podId The recommendation pod ID
     * @param string $title The display title of the recommendation pod
     * @param array $products Array of recommended products
     * @param int $total Total number of recommendations available
     * @param array $metadata Provider-specific metadata
     */
    public function __construct(
        public readonly string $podId,
        public readonly string $title,
        public readonly array $products,
        public readonly int $total = 0,
        public readonly array $metadata = [],
    ) {}

    /**
     * Check if there are any recommendations.
     */
    public function isEmpty(): bool
    {
        return empty($this->products);
    }

    /**
     * Check if there are recommendations (inverse of isEmpty for semantic clarity).
     */
    public function hasRecommendations(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the number of recommendations.
     */
    public function count(): int
    {
        return count($this->products);
    }

    /**
     * Get all results as an array for JSON serialization.
     *
     * @return array{
     *     pod_id: string,
     *     title: string,
     *     products: array,
     *     total: int,
     *     metadata: array
     * }
     */
    public function toArray(): array
    {
        return [
            'pod_id' => $this->podId,
            'title' => $this->title,
            'products' => $this->products,
            'total' => $this->total,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create an empty RecommendationResults instance.
     */
    public static function empty(string $podId = '', string $title = ''): self
    {
        return new self(
            podId: $podId,
            title: $title,
            products: [],
            total: 0,
        );
    }
}
