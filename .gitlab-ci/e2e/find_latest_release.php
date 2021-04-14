#!/usr/bin/env php
<?php

$versionPrefix = ltrim(trim($argv[1] ?? "6"), 'v');
$channel = strtolower(trim($argv[2] ?? 'stable'));

$updateApiUrl = 'https://update-api.shopware.com/v1/releases/install?major=6&channel=' . $channel;

$releases = json_decode(file_get_contents($updateApiUrl), true);

foreach ($releases as $release) {
    // skip releases not matching version prefix
    if (strpos($release['version'], $versionPrefix) !== 0) {
        continue;
    }

    echo $release['uri'];
    exit(0);
}

exit(1);
