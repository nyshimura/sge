<?php
$url = "https://raw.githubusercontent.com/nyshimura/sge/main/package.json";
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => [
            'User-Agent: PHP-Updater',
            'Cache-Control: no-cache'
        ]
    ]
];
$ctx = stream_context_create($opts);
$c = file_get_contents($url, false, $ctx);
if ($c === false) {
    echo "Error: ";
    print_r(error_get_last());
} else {
    echo "Success: " . $c;
}
