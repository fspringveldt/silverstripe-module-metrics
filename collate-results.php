#!/usr/bin/env php
<?php

$basePath = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;
$output = isset($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : null;

// Argument parsing
if (empty($basePath)) {
    echo "Usage: {$_SERVER['argv'][0]} (input-path)" . PHP_EOL;
    exit(1);
}

if (!is_dir($basePath)) {
    echo "$basePath is not a directory" . PHP_EOL;
    exit(1);
}

$result = [];

$dir = new DirectoryIterator($basePath);
/** @var SplFileInfo $fileInfo */
foreach ($dir as $fileInfo) {
    if (!$fileInfo->isDot() && strtolower($fileInfo->getExtension()) == 'json') {
        $fileName = $fileInfo->getFilename();

        // Skip UAT and test sites
        if (preg_match('(uat|test)', $fileName) === 1) {
            continue;
        }

        $contents = file_get_contents($fileInfo->getRealPath());
        $jsonArray = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }
        if (!count($jsonArray)) {
            continue;
        }
        $siteName = preg_replace('(moduleMetrics\-|\.json)', '', $fileName);
        foreach ($jsonArray as $item) {
            // Update site name
            $item['Site'] = $siteName;
            $result[] = $item;
        }
    }
}

echo json_encode($result);


