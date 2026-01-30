# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-01-30

### Added
- Initial release of Constructor.io Laravel integration
- **Search Service** (`ConstructorSandboxSearch`)
  - Full-text product search with filters, sorting, and pagination
  - Category/facet browsing via `browse()` method
  - Autocomplete with search suggestions and product previews
  - Zero-state data (trending searches, popular products, top categories)
  - Recommendations from pods (home page and item-based)
  - Collection browsing and metadata
  - Facet discovery and values with images
  - Recipe search support
- **AI Agent Service** (`ConstructorAgentService`)
  - AI Shopping Agent for natural language product discovery
  - Streaming support for real-time UI updates
  - Product Insights Agent for product Q&A
  - Complementary product search
- **Catalog Service** (`ConstructorService`)
  - Bulk catalog file uploads (CSV, JSONL)
  - Task status monitoring with polling
  - API credential verification
- **Laravel Scout Integration** (`ConstructorEngine`)
  - Full Scout engine implementation
  - Model indexing and searching
  - Facet support in search results
- **Data Transfer Objects**
  - `SearchResults` for search/browse responses
  - `AutocompleteResults` for autocomplete/zero-state
  - `RecommendationResults` for recommendation pods
- **Supporting Files**
  - `SandboxSearchContract` interface for swappable search backends
  - `SearchResultCollection` for Scout results with metadata
- **Configuration**
  - `constructor.php` for API and agent settings
  - `constructor-catalog.php` for catalog upload settings
- **Facade** for convenient static access

### Dependencies
- PHP 8.1+
- Laravel 10.x or 11.x
- Guzzle HTTP 7.x
- Optional: Laravel Scout 10.x for Scout integration
