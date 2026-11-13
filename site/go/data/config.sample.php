<?php
// Copy this file to data/config.php on the server (it is never uploaded from the repo).
// Make a password hash at mbc.mw/go/admin/ (it shows a setup screen until a user exists),
// then paste the line it gives you into 'users'.
return [
    // Address printed inside every QR code. Must end with /go/
    'base_url' => 'https://mbc.mw/go/',

    // Where mbc.mw/go/ (with no name) and the "link ended" page send people.
    'fallback_url' => 'https://mbc.mw/',

    // username => password hash. One line per person.
    'users' => [
        // 'marketing' => '$2y$10$...',
    ],
];
