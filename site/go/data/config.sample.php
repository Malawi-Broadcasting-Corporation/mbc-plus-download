<?php
// Copy this file to data/config.php on the server. It is never uploaded from the repo.
return [
    // Needed once, to create the first admin at mbc.mw/go/admin. Use something long and random,
    // give it to that person privately, and you may blank it again afterwards.
    'setup_code' => '',

    // Address printed inside every QR code. Must end with /go/
    'base_url' => 'https://mbc.mw/go/',

    // Where mbc.mw/go/ (with no name) and the "link ended" page send people.
    'fallback_url' => 'https://mbc.mw/',
];
