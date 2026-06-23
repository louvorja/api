<?php
require __DIR__ . '/vendor/autoload.php';

$g = new \OpenApi\Generator();
$openapi = $g->scan(['app']);

$json = $openapi->toJson();
file_put_contents(__DIR__ . '/storage/openapi.json', $json);

echo "Generated openapi.json: " . strlen($json) . " bytes\n";
echo "Paths: " . count($openapi->paths ?? []) . "\n";

// List all paths
foreach ($openapi->paths as $path => $item) {
    $methods = [];
    foreach ($item as $method => $op) {
        if (is_string($method) && preg_match('/^(get|post|put|delete|patch)$/', $method)) {
            $methods[] = strtoupper($method);
        }
    }
    echo "  " . implode(',', $methods) . " " . $path . "\n";
}
