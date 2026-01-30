<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for exporting products to catalog files (CSV or JSONL).
    |
    | chunk_size: Number of products to process at once. Recommended values:
    |   - 500-1000 for products with many attributes/facets
    |   - 1000-2000 for products with simple data
    |   - Higher values use more memory but process faster
    |
    | default_format: 'csv' for simpler data, 'jsonl' for complex nested data
    |   - CSV: Easier to inspect, limited to flat data structures
    |   - JSONL: Supports nested arrays (facets, categories, metadata)
    |
    */

    'export' => [
        'storage_path' => env('CONSTRUCTOR_EXPORT_PATH', 'constructor-exports'),
        'chunk_size' => env('CONSTRUCTOR_EXPORT_CHUNK_SIZE', 1000),
        'default_format' => env('CONSTRUCTOR_DEFAULT_FORMAT', 'csv'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sample Push Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for sample/test catalog pushes. Use this to validate your
    | catalog structure before pushing the full catalog.
    |
    */

    'sample' => [
        'enabled' => env('CONSTRUCTOR_SAMPLE_ENABLED', true),
        'default_limit' => env('CONSTRUCTOR_SAMPLE_DEFAULT_LIMIT', 1000),
        'max_limit' => env('CONSTRUCTOR_SAMPLE_MAX_LIMIT', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings for catalog uploads to Constructor.
    |
    | default_operation: How to handle existing items in the catalog:
    |   - 'create_or_replace': Full sync - removes items not in upload file
    |     Use for: Daily full catalog syncs, initial setup
    |   - 'patch': Partial update - only updates items in upload file
    |     Use for: Incremental updates, inventory/price changes
    |
    | force: Whether to push even if validation warnings occur (true recommended
    |        for automated pipelines, false for initial testing)
    |
    | section: Constructor section name ('Products', 'Recipes', etc.)
    |
    */

    'upload' => [
        'default_operation' => env('CONSTRUCTOR_DEFAULT_OPERATION', 'create_or_replace'),
        'force' => env('CONSTRUCTOR_FORCE_UPLOAD', true),
        'section' => env('CONSTRUCTOR_SECTION', 'Products'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Monitoring
    |--------------------------------------------------------------------------
    |
    | Settings for monitoring Constructor task status after upload.
    |
    | Constructor processes uploads asynchronously. These settings control
    | how the waitForTaskCompletion() method polls for completion.
    |
    | poll_interval: Seconds between status checks. Recommended: 10-30 seconds
    |   - Too low: Unnecessary API calls, may hit rate limits
    |   - Too high: Delayed awareness of completion/failure
    |
    | max_attempts: Maximum number of polling attempts before giving up
    |   - With 10s interval: 60 attempts = 10 minutes max wait
    |   - Adjust based on your typical catalog size and processing time
    |
    | timeout: Overall timeout in seconds (fallback if max_attempts not reached)
    |
    */

    'task_monitoring' => [
        'poll_interval' => env('CONSTRUCTOR_TASK_POLL_INTERVAL', 10),
        'max_attempts' => env('CONSTRUCTOR_TASK_MAX_ATTEMPTS', 60),
        'timeout' => env('CONSTRUCTOR_TASK_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Cleanup
    |--------------------------------------------------------------------------
    |
    | Settings for cleaning up export files after push.
    |
    | Enable cleanup to avoid accumulating large export files on disk.
    | Disable if you need to keep files for debugging or auditing.
    |
    */

    'cleanup' => [
        'files' => env('CONSTRUCTOR_CATALOG_CLEANUP_FILES', true),
        'retention_days' => env('CONSTRUCTOR_FILE_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Notification settings for push operations.
    |
    | Configure Slack webhook to receive alerts on catalog push success/failure.
    | Useful for monitoring automated/scheduled catalog syncs.
    |
    */

    'notifications' => [
        'slack_webhook' => env('CONSTRUCTOR_SLACK_WEBHOOK'),
        'notify_on_success' => env('CONSTRUCTOR_NOTIFY_SUCCESS', false),
        'notify_on_failure' => env('CONSTRUCTOR_NOTIFY_FAILURE', true),
    ],

];
