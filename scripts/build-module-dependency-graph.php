<?php

declare(strict_types=1);

// Scans Modules/**/*.php for cross-module `use Modules\...` edges (for CODE_REVIEW_GRAPH.md).

$root = dirname(__DIR__);
$modulesDir = $root.DIRECTORY_SEPARATOR.'Modules';
$statusFile = $root.DIRECTORY_SEPARATOR.'modules_statuses.json';

if (! is_dir($modulesDir)) {
    fwrite(STDERR, "Modules directory not found.\n");
    exit(1);
}

$allowedModules = [];
if (is_readable($statusFile)) {
    $json = json_decode((string) file_get_contents($statusFile), true);
    if (is_array($json)) {
        foreach (array_keys($json) as $name) {
            if ($json[$name] === true) {
                $allowedModules[$name] = true;
            }
        }
    }
}

$edges = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (! preg_match('#^.*?/Modules/([^/]+)/#', $path, $m)) {
        continue;
    }
    $from = $m[1];
    $content = @file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }
    if (preg_match_all('/use Modules\\\\([A-Za-z]+)\\\\/', $content, $mm)) {
        foreach ($mm[1] as $to) {
            if ($to === $from) {
                continue;
            }
            if ($allowedModules !== [] && (! isset($allowedModules[$from]) || ! isset($allowedModules[$to]))) {
                continue;
            }
            $edges["{$from}=>{$to}"] = true;
        }
    }
}

ksort($edges);
foreach (array_keys($edges) as $edge) {
    echo $edge, PHP_EOL;
}
