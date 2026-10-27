<?php

/**
 * ASSAB ERP — Wave 1 Deterministic Feature Suite Sharding Runner & Reconciler
 *
 * Implements authoritative, memory-safe, deterministic file-based PHPUnit sharding.
 * Guarantees zero duplicate test cases, zero omissions, and exact 1-to-1 mapping
 * between partitioned test files and generated JUnit XML evidence.
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
        $normPath = str_replace('\\', '/', $file->getPathname());
        $normRoot = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
        $relativePath = str_replace($normRoot, '', $normPath);
        $files[] = $relativePath;
    }
}

sort($files);
$totalFiles = count($files);

$options = getopt('', ['shards::', 'shard::', 'all', 'dry-run', 'reconcile', 'partial', 'test-fail-closed', 'help']);
$numShards = isset($options['shards']) ? max(1, (int)$options['shards']) : 10;
$singleShard = isset($options['shard']) ? (int)$options['shard'] : null;
$runAll = isset($options['all']);
$isDryRun = isset($options['dry-run']);
$isReconcile = isset($options['reconcile']);
$isPartial = isset($options['partial']) || ($singleShard !== null);
$isTestFailClosed = isset($options['test-fail-closed']);

if (isset($options['help']) || (!$runAll && $singleShard === null && !$isDryRun && !$isReconcile && !$isTestFailClosed)) {
    echo "Usage:\n";
    echo "  php scripts/run_sharded_feature_suite.php --dry-run [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --shard=1 [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --all [--shards=10]\n";
    echo "  php scripts/run_sharded_feature_suite.php --reconcile [--shards=10] [--partial]\n";
    echo "  php scripts/run_sharded_feature_suite.php --test-fail-closed\n";
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

// Partition Validation: Disjoint and exhaustive
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
echo "ASSAB ERP — Authoritative Deterministic PHPUnit File-Based Sharder\n";
echo "========================================================================\n";
echo "Total Discovered Test Files: $totalFiles\n";
echo "Total Configured Shards:     $numShards\n";
echo "Execution Engine:            PHPUnit File-Based (Authoritative)\n";
foreach ($shards as $idx => $sFiles) {
    echo sprintf("  Shard %02d: %3d files\n", $idx, count($sFiles));
}
echo "Partition Validation:        100% EXHAUSTIVE, 0 DUPLICATES, 0 OMISSIONS\n";
echo "========================================================================\n\n";

if ($isDryRun) {
    echo "Dry run completed successfully.\n";
    exit(0);
}

// Helper: Parse JUnit XML with XPath
function parseShardXml(string $xmlPath): array {
    $data = [
        'xml_valid' => false,
        'tests' => 0,
        'assertions' => 0,
        'failures' => 0,
        'errors' => 0,
        'skipped' => 0,
        'test_cases' => [],
        'skipped_cases' => [],
        'failed_cases' => [],
        'source_files' => [],
    ];

    if (!file_exists($xmlPath) || filesize($xmlPath) === 0) {
        return $data;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        return $data;
    }

    $data['xml_valid'] = true;
    $caseNodes = $xml->xpath('//testcase');
    if ($caseNodes !== false) {
        $data['tests'] = count($caseNodes);
        foreach ($caseNodes as $case) {
            $cName = (string)($case['name'] ?? '');
            $cClass = (string)($case['class'] ?? '');
            $cFile = (string)($case['file'] ?? '');
            $identity = "$cClass::$cName";

            $data['test_cases'][] = $identity;
            if ($cFile !== '') {
                $relFile = str_replace('\\', '/', $cFile);
                $relFile = preg_replace('#^.*tests/#', 'tests/', $relFile);
                $data['source_files'][$relFile] = true;
            }

            $data['assertions'] += (int)($case['assertions'] ?? 1);
            if (count($case->xpath('failure')) > 0) {
                $data['failures']++;
                $data['failed_cases'][] = $identity;
            }
            if (count($case->xpath('error')) > 0) {
                $data['errors']++;
                $data['failed_cases'][] = $identity;
            }
            if (count($case->xpath('skipped')) > 0) {
                $data['skipped']++;
                $data['skipped_cases'][] = $identity;
            }
        }
        $data['source_files'] = array_keys($data['source_files']);
    }

    return $data;
}

// 3. Execution Phase
// Self-Test Fail-Closed Behavior
if ($isTestFailClosed) {
    echo "========================================================================\n";
    echo "Testing Fail-Closed Reconciliation Behavior in Isolated Sandbox...\n";
    echo "========================================================================\n";

    $sandboxDir = $rootDir . '/storage/test-results/fail_closed_sandbox_' . uniqid();
    mkdir($sandboxDir, 0777, true);

    $testMockShards = [
        1 => ['tests/Feature/Example1Test.php'],
        2 => ['tests/Feature/Example2Test.php'],
    ];

    // Subtest 1: Missing shards
    $res1 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res1['is_full_pass'] !== false || count($res1['missing_shard_list']) !== 2) {
        fwrite(STDERR, "FAIL: Missing shards did not fail closed!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 1: Missing shards trigger fail-closed rejection.\n";

    // Create valid shard 1
    $xmlValid = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="Tests"><testcase name="test_one" class="Tests\Feature\Example1Test" file="' . $rootDir . '/tests/Feature/Example1Test.php" assertions="1"/></testsuite></testsuites>';
    file_put_contents($sandboxDir . '/shard_1.xml', $xmlValid);
    file_put_contents($sandboxDir . '/shard_1.log', "OK (1 test, 1 assertion)\n");
    file_put_contents($sandboxDir . '/shard_1.meta.json', json_encode(['shard' => 1, 'exit_code' => 0]));

    // Subtest 2: Partial run (1 of 2 shards present)
    $res2 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res2['is_full_pass'] !== false || $res2['is_complete'] !== false) {
        fwrite(STDERR, "FAIL: Incomplete shards reported complete!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 2: Incomplete shard set rejected under standard reconcile.\n";

    // Subtest 3: Shard with failure
    $xmlFail = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="Tests"><testcase name="test_two" class="Tests\Feature\Example2Test" file="' . $rootDir . '/tests/Feature/Example2Test.php" assertions="1"><failure message="Failed assertion"/></testcase></testsuite></testsuites>';
    file_put_contents($sandboxDir . '/shard_2.xml', $xmlFail);
    file_put_contents($sandboxDir . '/shard_2.log', "FAILURES!\n");
    file_put_contents($sandboxDir . '/shard_2.meta.json', json_encode(['shard' => 2, 'exit_code' => 1]));

    $res3 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res3['is_full_pass'] !== false || $res3['has_failures'] !== true) {
        fwrite(STDERR, "FAIL: Test failure did not trigger fail-closed!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 3: Test failure triggers fail-closed rejection.\n";

    // Subtest 4: Exit code != 0
    file_put_contents($sandboxDir . '/shard_2.meta.json', json_encode(['shard' => 2, 'exit_code' => 255]));
    $res4 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res4['has_exit_code_errors'] !== true) {
        fwrite(STDERR, "FAIL: Nonzero process exit code did not trigger fail-closed!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 4: Nonzero process exit code triggers fail-closed rejection.\n";

    // Subtest 5: File mismatch (declared file missing from XML)
    $xmlValid2 = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="Tests"><testcase name="test_diff" class="Tests\Feature\DifferentTest" file="' . $rootDir . '/tests/Feature/DifferentTest.php" assertions="1"/></testsuite></testsuites>';
    file_put_contents($sandboxDir . '/shard_2.xml', $xmlValid2);
    file_put_contents($sandboxDir . '/shard_2.meta.json', json_encode(['shard' => 2, 'exit_code' => 0]));
    $res5 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res5['has_file_mismatches'] !== true) {
        fwrite(STDERR, "FAIL: Declared vs executed file mismatch did not trigger fail-closed!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 5: Declared vs executed file mismatch triggers fail-closed rejection.\n";

    // Subtest 6: Duplicate test across shards
    $xmlDup = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="Tests"><testcase name="test_one" class="Tests\Feature\Example1Test" file="' . $rootDir . '/tests/Feature/Example2Test.php" assertions="1"/></testsuite></testsuites>';
    file_put_contents($sandboxDir . '/shard_2.xml', $xmlDup);
    $res6 = reconcileShards(2, $testMockShards, $sandboxDir, false);
    if ($res6['has_duplicates'] !== true) {
        fwrite(STDERR, "FAIL: Duplicate test case across shards did not trigger fail-closed!\n");
        exit(1);
    }
    echo "  [PASS] Subtest 6: Duplicate test case identity triggers fail-closed rejection.\n";

    // Clean up sandbox
    foreach (glob($sandboxDir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($sandboxDir);

    echo "\nAll 6 fail-closed verification subtests PASSED successfully.\n";
    echo "========================================================================\n";
    exit(0);
}

// 3. Execution Phase
if (!$isReconcile) {
    $shardsToRun = $singleShard !== null ? [$singleShard => $shards[$singleShard]] : $shards;
    $overallSuccess = true;

    foreach ($shardsToRun as $shardNum => $shardFiles) {
        echo sprintf(">>> Executing Shard %02d/%02d (%d files)...\n", $shardNum, $numShards, count($shardFiles));

        $xmlOutput = $testResultsDir . "/shard_{$shardNum}.xml";
        $logOutput = $testResultsDir . "/shard_{$shardNum}.log";
        $metaOutput = $testResultsDir . "/shard_{$shardNum}.meta.json";

        if (file_exists($xmlOutput)) {
            @unlink($xmlOutput);
        }
        if (file_exists($logOutput)) {
            @unlink($logOutput);
        }
        if (file_exists($metaOutput)) {
            @unlink($metaOutput);
        }

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
            fwrite(STDERR, "ERROR: Failed to start PHPUnit process for Shard $shardNum\n");
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

        $parsed = parseShardXml($xmlOutput);
        $passed = ($exitCode === 0 && $parsed['failures'] === 0 && $parsed['errors'] === 0 && $parsed['xml_valid'] && $parsed['tests'] > 0);

        // Write authoritative shard metadata
        $metaData = [
            'shard' => $shardNum,
            'exit_code' => $exitCode,
            'duration' => round($duration, 2),
            'command' => $cmd,
            'files_declared' => count($shardFiles),
            'files_executed' => count($parsed['source_files']),
            'tests' => $parsed['tests'],
            'assertions' => $parsed['assertions'],
            'failures' => $parsed['failures'],
            'errors' => $parsed['errors'],
            'skipped' => $parsed['skipped'],
            'timestamp' => date('c'),
        ];
        file_put_contents($metaOutput, json_encode($metaData, JSON_PRETTY_PRINT));

        if (!$passed) {
            $overallSuccess = false;
        }

        $statusStr = $passed ? "PASS" : "FAIL";
        echo sprintf(
            "    Shard %02d: %s | Exit: %d | Time: %.1fs | Tests: %d | Assertions: %d | Failures: %d | Errors: %d | Skipped: %d\n\n",
            $shardNum, $statusStr, $exitCode, $duration, $parsed['tests'], $parsed['assertions'], $parsed['failures'], $parsed['errors'], $parsed['skipped']
        );
    }
}

// 4. Reconciliation Helper Function
function reconcileShards(int $numShards, array $shards, string $resultsDir, bool $isPartial): array {
    $reconciledShards = [];
    $allCaseIdentities = [];
    $allSkippedIdentities = [];
    $duplicateCases = [];
    $totalUniqueAssertions = 0;
    $totalFailures = 0;
    $totalErrors = 0;
    $allShardsPresent = true;
    $missingShardList = [];
    $shardsWithFileMismatches = [];
    $shardsWithExitCodeErrors = [];

    for ($s = 1; $s <= $numShards; $s++) {
        $xmlPath = $resultsDir . "/shard_{$s}.xml";
        $logPath = $resultsDir . "/shard_{$s}.log";
        $metaPath = $resultsDir . "/shard_{$s}.meta.json";

        if (!file_exists($xmlPath) || filesize($xmlPath) === 0) {
            $allShardsPresent = false;
            $missingShardList[] = "Shard $s: XML evidence missing or empty";
            continue;
        }

        $parsed = parseShardXml($xmlPath);
        if (!$parsed['xml_valid']) {
            $allShardsPresent = false;
            $missingShardList[] = "Shard $s: XML evidence malformed";
            continue;
        }

        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : null;
        $exitCode = $meta['exit_code'] ?? ($parsed['failures'] === 0 && $parsed['errors'] === 0 ? 0 : 1);
        if ($exitCode !== 0) {
            $shardsWithExitCodeErrors[] = "Shard $s: Nonzero exit code ($exitCode)";
        }

        // Validate declared vs executed files
        $declared = $shards[$s];
        $executed = $parsed['source_files'];
        sort($declared);
        sort($executed);
        $missingFiles = array_diff($declared, $executed);
        if (!empty($missingFiles)) {
            $shardsWithFileMismatches[] = "Shard $s: Missing executed files: " . implode(', ', $missingFiles);
        }

        foreach ($parsed['test_cases'] as $caseId) {
            if (isset($allCaseIdentities[$caseId])) {
                $duplicateCases[] = $caseId;
            } else {
                $allCaseIdentities[$caseId] = $s;
            }
        }

        foreach ($parsed['skipped_cases'] as $skipId) {
            $allSkippedIdentities[$skipId] = $s;
        }

        $totalUniqueAssertions += $parsed['assertions'];
        $totalFailures += $parsed['failures'];
        $totalErrors += $parsed['errors'];

        $reconciledShards[$s] = [
            'shard' => $s,
            'runner' => 'phpunit_file_based',
            'exit_code' => $exitCode,
            'files_declared' => count($shards[$s]),
            'files_executed' => count($parsed['source_files']),
            'files' => $shards[$s],
            'tests' => $parsed['tests'],
            'assertions' => $parsed['assertions'],
            'failures' => $parsed['failures'],
            'errors' => $parsed['errors'],
            'skipped' => $parsed['skipped'],
            'xml_valid' => $parsed['xml_valid'],
            'files_match' => empty($missingFiles),
            'passed' => ($exitCode === 0 && $parsed['failures'] === 0 && $parsed['errors'] === 0 && $parsed['tests'] > 0 && empty($missingFiles)),
            'xml_path' => "storage/test-results/shard_{$s}.xml",
            'log_path' => "storage/test-results/shard_{$s}.log",
            'meta_path' => "storage/test-results/shard_{$s}.meta.json",
        ];
    }

    $duplicateCases = array_unique($duplicateCases);
    $totalUniqueTests = count($allCaseIdentities);
    $totalUniqueSkipped = count($allSkippedIdentities);

    $isComplete = ($allShardsPresent && count($reconciledShards) === $numShards && empty($missingShardList));
    $hasFailures = ($totalFailures > 0 || $totalErrors > 0);
    $hasDuplicates = (count($duplicateCases) > 0);
    $hasFileMismatches = !empty($shardsWithFileMismatches);
    $hasExitCodeErrors = !empty($shardsWithExitCodeErrors);

    $isFullPass = ($isComplete && !$hasFailures && !$hasDuplicates && !$hasFileMismatches && !$hasExitCodeErrors);

    return [
        'is_full_pass' => $isFullPass,
        'is_complete' => $isComplete,
        'has_failures' => $hasFailures,
        'has_duplicates' => $hasDuplicates,
        'has_file_mismatches' => $hasFileMismatches,
        'has_exit_code_errors' => $hasExitCodeErrors,
        'missing_shard_list' => $missingShardList,
        'shards_with_file_mismatches' => $shardsWithFileMismatches,
        'shards_with_exit_code_errors' => $shardsWithExitCodeErrors,
        'reconciled_shards' => $reconciledShards,
        'total_unique_tests' => $totalUniqueTests,
        'total_unique_assertions' => $totalUniqueAssertions,
        'total_failures' => $totalFailures,
        'total_errors' => $totalErrors,
        'total_unique_skipped' => $totalUniqueSkipped,
        'all_skipped_identities' => array_keys($allSkippedIdentities),
        'duplicate_cases' => $duplicateCases,
    ];
}

// 5. Reconciliation & Manifest Generation Phase
echo "========================================================================\n";
echo "Reconciling All Shard Evidence Files...\n";
echo "========================================================================\n";

$reconcileResult = reconcileShards($numShards, $shards, $testResultsDir, $isPartial);

$manifestData = [
    'timestamp' => date('c'),
    'git_head' => trim(shell_exec('git rev-parse HEAD 2>nul')),
    'git_branch' => trim(shell_exec('git rev-parse --abbrev-ref HEAD 2>nul')),
    'sharding_strategy' => 'deterministic_phpunit_file_based',
    'total_discovered_files' => $totalFiles,
    'total_configured_shards' => $numShards,
    'shards_reconciled' => count($reconcileResult['reconciled_shards']),
    'all_shards_complete' => $reconcileResult['is_complete'],
    'full_suite_passed' => $reconcileResult['is_full_pass'],
    'audit_reconciliation' => [
        'total_unique_test_cases' => $reconcileResult['total_unique_tests'],
        'total_unique_assertions' => $reconcileResult['total_unique_assertions'],
        'total_failures' => $reconcileResult['total_failures'],
        'total_errors' => $reconcileResult['total_errors'],
        'total_unique_skipped' => $reconcileResult['total_unique_skipped'],
        'skipped_test_identities' => $reconcileResult['all_skipped_identities'],
        'duplicate_test_identities_count' => count($reconcileResult['duplicate_cases']),
        'duplicate_test_identities' => $reconcileResult['duplicate_cases'],
        'zero_omissions' => $reconcileResult['is_complete'],
        'zero_duplicates' => (!$reconcileResult['has_duplicates']),
        'zero_file_mismatches' => (!$reconcileResult['has_file_mismatches']),
        'zero_exit_code_errors' => (!$reconcileResult['has_exit_code_errors']),
    ],
    'shard_details' => $reconcileResult['reconciled_shards'],
];

$manifestPath = $testResultsDir . "/feature_suite_manifest.json";
file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT));

// Mirror into docs/testing
file_put_contents($rootDir . "/docs/testing/feature_suite_shards_manifest.json", json_encode($manifestData, JSON_PRETTY_PRINT));

echo "Reconciliation Summary:\n";
echo "  Shards Reconciled:       " . count($reconcileResult['reconciled_shards']) . " / $numShards\n";
echo "  Total Unique Test Cases: " . $reconcileResult['total_unique_tests'] . "\n";
echo "  Total Assertions:        " . $reconcileResult['total_unique_assertions'] . "\n";
echo "  Total Failures:          " . $reconcileResult['total_failures'] . "\n";
echo "  Total Errors:            " . $reconcileResult['total_errors'] . "\n";
echo "  Total Skipped:           " . $reconcileResult['total_unique_skipped'] . "\n";
echo "  Duplicate Test Cases:    " . count($reconcileResult['duplicate_cases']) . "\n";
echo "Manifest written to: storage/test-results/feature_suite_manifest.json\n";
echo "========================================================================\n";

if ($reconcileResult['is_full_pass']) {
    echo "AUDIT VERIFICATION DECISION: FULL_SUITE_PASS (Exit Code 0)\n";
    exit(0);
} elseif ($isPartial && !$reconcileResult['has_failures'] && !$reconcileResult['has_duplicates'] && !$reconcileResult['has_file_mismatches'] && !$reconcileResult['has_exit_code_errors']) {
    echo "AUDIT VERIFICATION DECISION: PARTIAL_PROGRESS_ONLY (Exit Code 0 under --partial or single shard)\n";
    echo "  NOTE: Incomplete run permitted only for inspection. NOT ACCEPTED as suite pass.\n";
    exit(0);
} else {
    fwrite(STDERR, "\nRECONCILIATION FAILED (FAIL-CLOSED GUARD ENGAGED):\n");
    if (!$reconcileResult['is_complete']) {
        fwrite(STDERR, "  - Missing shards: " . implode('; ', $reconcileResult['missing_shard_list']) . "\n");
    }
    if ($reconcileResult['has_failures']) {
        fwrite(STDERR, "  - Failures: {$reconcileResult['total_failures']}, Errors: {$reconcileResult['total_errors']}\n");
    }
    if ($reconcileResult['has_duplicates']) {
        fwrite(STDERR, "  - Duplicate tests found: " . implode(', ', $reconcileResult['duplicate_cases']) . "\n");
    }
    if ($reconcileResult['has_file_mismatches']) {
        fwrite(STDERR, "  - File mismatches: " . implode('; ', $reconcileResult['shards_with_file_mismatches']) . "\n");
    }
    if ($reconcileResult['has_exit_code_errors']) {
        fwrite(STDERR, "  - Exit code errors: " . implode('; ', $reconcileResult['shards_with_exit_code_errors']) . "\n");
    }
    exit(1);
}
