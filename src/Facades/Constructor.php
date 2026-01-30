<?php

namespace ConstructorIO\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for Constructor.io search service.
 *
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults search(string $query, array $filters = [], array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browse(string $filterName, string $filterValue, array $filters = [], array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults autocomplete(string $query, array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults getZeroStateData(array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getRecommendations(string $podId, array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getItemRecommendations(string $podId, string $itemId, array $options = [])
 * @method static array getBrowseGroups(array $options = [])
 * @method static array getCollections(array $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseCollection(string $collectionId, array $filters = [], array $options = [])
 * @method static array|null getCollection(string $collectionId)
 * @method static array getFacets(string $query, array $filters = [])
 * @method static array getAvailableFacets()
 * @method static array getFacetValuesWithImages(string $facetName, int $maxItems = 10)
 * @method static bool supportsZeroState()
 * @method static bool supportsRecommendations()
 * @method static bool supportsBrowseGroups()
 * @method static bool supportsCollections()
 * @method static string getProviderName()
 *
 * @see \ConstructorIO\Laravel\Services\Search\ConstructorSandboxSearch
 */
class Constructor extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'constructor';
    }
}
