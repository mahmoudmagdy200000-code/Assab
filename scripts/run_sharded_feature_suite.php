<?php

/**
 * ASSAB ERP — Wave 1 Deterministic Feature Suite Sharding Runner
 *
 * Implements memory-safe, deterministic execution of Feature tests with rigorous
 * verification, stale-file cleanup, XPath-based JUnit XML parsing, and strict exit code gating.
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

$options = getopt('', ['shards::', 'shard::', 'all', 'dry-run', 'help', 'runner::']);
$numShards = isset($options['shards']) ? max(1, (int)$options['shards']) : 10;
$singleShard = isset($options['shard']) ? (int)$options['shard'] : null;
$runAll = isset($options['all']);
$isDryRun = isset($options['dry-run']);
$runner = isset($options['runner']) ? strtolower($options['runner']) : 'phpunit'; // 'phpunit' or 'paratest'

if (isset($options['help']) || (!$runAll && $singleShard === null && !$isDryRun)) {
    echo "Usage:\n";
    echo "  php scripts/run_sharded_feature_suite.php --dry-run [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --shard=1 [--shards=10] [--runner=phpunit|paratest]\n";
    echo "  php scripts/run_sharded_feature_suite.php --all [--shards=10] [--runner=phpunit|paratest]\n";
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

// Partition Validation
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
echo "Execution Engine:            " . strtoupper($runner) . "\n";
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
$overallSuccess = true;

foreach ($shardsToRun as $shardNum => $shardFiles) {
    echo sprintf(">>> Running Shard %02d/%02d (%d files)...\n", $shardNum, $numShards, count($shardFiles));

    $xmlOutput = $testResultsDir . "/shard_{$shardNum}.xml";
    $logOutput = $testResultsDir . "/shard_{$shardNum}.log";

    // Clean stale artifacts before execution
    if (file_exists($xmlOutput)) {
        @unlink($xmlOutput);
    }
    if (file_exists($logOutput)) {
        @unlink($logOutput);
    }

    if ($runner === 'paratest') {
        $cmd = "php -d memory_limit=1G vendor/bin/paratest -p2 -m 10 --passthru-php=\"-dmemory_limit=1G\" --testsuite=Feature --shard={$shardNum}/{$numShards} --log-junit " . escapeshellarg($xmlOutput);
    } else {
        $fileArgs = implode(' ', array_map('escapeshellarg', $shardFiles));
        $cmd = "php -d memory_limit=1G vendor/bin/phpunit --log-junit " . escapeshellarg($xmlOutput) . " " . $fileArgs;
    }

    $startTime = microtime(true);
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, $rootDir);
    if (!is_resource($process)) {
        fwrite(STDERR, "ERROR: Failed to start test process for Shard $shardNum\n");
        $overallSuccess = false;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $duration = microtime(true) - $startTime;

    file_put_contents($logOutput, $stdout . "\n" . $stderr);

    // Parse and validate JUnit XML via XPath
    $tests = 0;
    $assertions = 0;
    $failures = 0;
    $errors = 0;
    $skipped = 0;
    $xmlValid = false;
    $testCaseNames = [];

    if (file_exists($xmlOutput) && filesize($xmlOutput) > 0) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlOutput);
        if ($xml !== false) {
            $xmlValid = true;
            $caseNodes = $xml->xpath('//testcase');
            if ($caseNodes !== false) {
                $tests = count($caseNodes);
                foreach ($caseNodes as $case) {
                    $cName = (string)($case['name'] ?? '');
                    $cClass = (string)($case['class'] ?? '');
                    $testCaseNames[] = "$cClass::$cName";

                    $assertions += (int)($case['assertions'] ?? 1);
                    if (isset($case->failure)) {
                        $failures++;
                    }
                    if (isset($case->error)) {
                        $errors++;
                    }
                    if (isset($case->skipped)) {
                        $skipped++;
                    }
                }
            }
        }
    }

    $shardPassed = ($exitCode === 0 && $failures === 0 && $errors === 0 && $xmlValid && $tests > 0);
    if (!$shardPassed) {
        $overallSuccess = false;
    }

    $shardResult = [
        'shard' => $shardNum,
        'runner' => $runner,
        'files_count' => count($shardFiles),
        'files' => $shardFiles,
        'exit_code' => $exitCode,
        'duration_seconds' => round($duration, 2),
        'tests' => $tests,
        'assertions' => $assertions,
        'failures' => $failures,
        'errors' => $errors,
        'skipped' => $skipped,
        'xml_valid' => $xmlValid,
        'passed' => $shardPassed,
        'xml_path' => $xmlOutput,
        'log_path' => $logOutput,
    ];

    $results[$shardNum] = $shardResult;

    $statusStr = $shardPassed ? "PASS" : "FAIL";
    echo sprintf(
        "    Shard %02d: %s | Exit: %d | Time: %.1fs | Tests: %d | Assertions: %d | Failures: %d | Errors: %d | Skipped: %d\n\n",
        $shardNum, $statusStr, $exitCode, $duration, $tests, $assertions, $failures, $errors, $skipped
    );
}

// 4. Output Summary Manifest
$manifestPath = $testResultsDir . "/feature_suite_manifest.json";
$manifestData = [
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
        'overall_success' => $overallSuccess,
    ],
    'shard_details' => $results,
];

file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT));

echo "========================================================================\n";
echo "Sharded Execution Manifest saved to: storage/test-results/feature_suite_manifest.json\n";
echo "Overall Status: " . ($overallSuccess ? "SUCCESS (ALL EXECUTED SHARDS PASSED)" : "FAILED") . "\n";
echo "========================================================================\n";

if (!$overallSuccess) {
    fwrite(STDERR, "ERROR: Sharded Feature test suite execution failed or encountered errors!\n");
    exit(1);
}

exit(0);
