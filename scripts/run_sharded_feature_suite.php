<?php

/**
 * ASSAB ERP — Wave 1 Deterministic Feature Suite Sharding Runner
 *
 * Discovers all Feature test files, partitions them deterministically into non-overlapping shards,
 * executes each shard in an isolated low-memory PHP process, and aggregates JUnit evidence.
 */

$rootDir = realpath(__DIR__ . '/..');
$testResultsDir = $rootDir . '/storage/test-results';
if (!is_dir($testResultsDir)) {
    mkdir($testResultsDir, 0777, true);
}

// 1. Discover all Feature test files
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir . '/tests/Feature'));
foreach ($iterator as $file) {
    if ($file->isFile() && preg_match('/Test\.php$/', $file->getFilename())) {
        $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relativePath = str_replace('\\', '/', $relativePath);
        $files[] = $relativePath;
    }
}

sort($files);
$totalFiles = count($files);

$options = getopt('', ['shards::', 'shard::', 'all', 'dry-run', 'help']);
$numShards = isset($options['shards']) ? (int)$options['shards'] : 10;
$singleShard = isset($options['shard']) ? (int)$options['shard'] : null;
$runAll = isset($options['all']);
$isDryRun = isset($options['dry-run']);

if (isset($options['help']) || (!$runAll && $singleShard === null && !$isDryRun)) {
    echo "Usage:\n";
    echo "  php scripts/run_sharded_feature_suite.php --dry-run [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --shard=1 [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --all [--shards=10]\n";
    exit(0);
}

// 2. Partition files deterministically into non-overlapping shards
$shards = [];
for ($i = 1; $i <= $numShards; $i++) {
    $shards[$i] = [];
}

foreach ($files as $idx => $file) {
    $shardIndex = ($idx % $numShards) + 1;
    $shards[$shardIndex][] = $file;
}

// Validation: zero overlap, complete coverage
$allPartitioned = [];
foreach ($shards as $sFiles) {
    $allPartitioned = array_merge($allPartitioned, $sFiles);
}

if (count($allPartitioned) !== $totalFiles) {
    fwrite(STDERR, "ERROR: Partitioned files count (" . count($allPartitioned) . ") does not match total files ($totalFiles)!\n");
    exit(1);
}

if (count(array_unique($allPartitioned)) !== $totalFiles) {
    fwrite(STDERR, "ERROR: Duplicate test files detected across shards!\n");
    exit(1);
}

echo "========================================================================\n";
echo "ASSAB ERP — Feature Test Suite Sharding Plan\n";
echo "========================================================================\n";
echo "Total Discovered Test Files: $totalFiles\n";
echo "Total Configured Shards:     $numShards\n";
foreach ($shards as $idx => $sFiles) {
    echo sprintf("  Shard %02d: %3d files\n", $idx, count($sFiles));
}
echo "Partition Validation:        100% EXHAUSTIVE, 0 DUPLICATES, 0 OMISSIONS\n";
echo "========================================================================\n\n";

if ($isDryRun) {
    echo "Dry run completed successfully.\n";
    exit(0);
}

// 3. Execution
$shardsToRun = $singleShard !== null ? [$singleShard => $shards[$singleShard]] : $shards;
$results = [];

foreach ($shardsToRun as $shardNum => $shardFiles) {
    echo sprintf(">>> Running Shard %02d/%02d (%d files)...\n", $shardNum, $numShards, count($shardFiles));

    $xmlOutput = $testResultsDir . "/shard_{$shardNum}.xml";
    $logOutput = $testResultsDir . "/shard_{$shardNum}.log";

    $fileArgs = implode(' ', array_map('escapeshellarg', $shardFiles));
    $cmd = "php -d memory_limit=1G vendor/bin/phpunit --log-junit " . escapeshellarg($xmlOutput) . " " . $fileArgs;

    $startTime = microtime(true);
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, $rootDir);
    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start PHPUnit process for Shard $shardNum\n");
        exit(1);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $duration = microtime(true) - $startTime;

    file_put_contents($logOutput, $stdout . "\n" . $stderr);

    // Parse XML metrics
    $tests = 0;
    $assertions = 0;
    $failures = 0;
    $errors = 0;
    $skipped = 0;

    if (file_exists($xmlOutput)) {
        $xml = simplexml_load_file($xmlOutput);
        if ($xml) {
            $rootSuite = $xml->testsuite;
            $tests = (int)$rootSuite['tests'];
            $assertions = (int)$rootSuite['assertions'];
            $failures = (int)$rootSuite['failures'];
            $errors = (int)$rootSuite['errors'];
            $skipped = (int)$rootSuite['skipped'];
        }
    }

    $shardResult = [
        'shard' => $shardNum,
        'files_count' => count($shardFiles),
        'files' => $shardFiles,
        'exit_code' => $exitCode,
        'duration_seconds' => round($duration, 2),
        'tests' => $tests,
        'assertions' => $assertions,
        'failures' => $failures,
        'errors' => $errors,
        'skipped' => $skipped,
        'xml_path' => $xmlOutput,
        'log_path' => $logOutput,
    ];

    $results[$shardNum] = $shardResult;

    $statusStr = ($exitCode === 0 && $failures === 0 && $errors === 0) ? "PASS" : "FAIL/WARN";
    echo sprintf(
        "    Shard %02d: %s | Exit: %d | Time: %.1fs | Tests: %d | Assertions: %d | Failures: %d | Errors: %d | Skipped: %d\n\n",
        $shardNum, $statusStr, $exitCode, $duration, $tests, $assertions, $failures, $errors, $skipped
    );
}

// 4. Output Summary Manifest
$manifestPath = $testResultsDir . "/feature_suite_manifest.json";
file_put_contents($manifestPath, json_encode([
    'timestamp' => date('c'),
    'git_head' => trim(shell_exec('git rev-parse HEAD 2>nul')),
    'git_branch' => trim(shell_exec('git rev-parse --abbrev-ref HEAD 2>nul')),
    'total_discovered_files' => $totalFiles,
    'total_shards' => $numShards,
    'shards_executed' => array_keys($results),
    'summary' => [
        'total_tests' => array_sum(array_column($results, 'tests')),
        'total_assertions' => array_sum(array_column($results, 'assertions')),
        'total_failures' => array_sum(array_column($results, 'failures')),
        'total_errors' => array_sum(array_column($results, 'errors')),
        'total_skipped' => array_sum(array_column($results, 'skipped')),
        'all_exit_zero' => count(array_filter(array_column($results, 'exit_code'), fn($c) => $c !== 0)) === 0,
    ],
    'shard_details' => $results,
], JSON_PRETTY_PRINT));

echo "========================================================================\n";
echo "Sharded Execution Manifest saved to: storage/test-results/feature_suite_manifest.json\n";
echo "========================================================================\n";
