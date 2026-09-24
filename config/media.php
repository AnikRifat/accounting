<?php

return [
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
    'max_size_kb' => 8192,
    'allowed_types' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'csv'],
    'url_lifetime_minutes' => 5,
];
