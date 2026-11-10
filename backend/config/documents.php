<?php

declare(strict_types=1);

return [
    // Private disk; never web-accessible. On shared hosting this folder is outside public_html.
    'disk' => env('DOCUMENTS_DISK', 'private'),

    // Client question Q18 (design file size) is open; 200 MB is the working assumption.
    'design_source_max_mb' => (int) env('DOCUMENTS_DESIGN_SOURCE_MAX_MB', 200),

    // Hosts accepted for shared design links (stored only; the server never fetches them).
    'design_link_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOCUMENTS_DESIGN_LINK_HOSTS', 'drive.google.com,docs.google.com'))))),

    // Hosts accepted for the WeTransfer link of the final internal bulk submission (stored only, never fetched).
    'bulk_transfer_link_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOCUMENTS_BULK_TRANSFER_LINK_HOSTS', 'wetransfer.com,www.wetransfer.com,we.tl'))))),

    // Signed download URL lifetime.
    'download_url_ttl_minutes' => 5,

    // ClamAV is not available on shared hosting (scan_status = skipped).
    'virus_scan' => (bool) env('DOCUMENTS_VIRUS_SCAN', false),
];
