[CmdletBinding()]
param(
    [string] $PhpExecutable
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$erpRoot = Split-Path -Parent $repoRoot

if (-not $PhpExecutable) {
    $PhpExecutable = Join-Path $erpRoot '.tools\s1-01\php-8.4.26\php.exe'
}

$phpunit = Join-Path $repoRoot 'vendor\bin\phpunit'
$logDirectory = Join-Path $repoRoot 'storage\logs'
if (-not (Test-Path -LiteralPath $PhpExecutable -PathType Leaf)) {
    throw "PHP executable not found: $PhpExecutable"
}
if (-not (Test-Path -LiteralPath $phpunit -PathType Leaf)) {
    throw "PHPUnit launcher not found: $phpunit"
}
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

$runId = Get-Date -Format 'yyyyMMdd-HHmmss'
$stages = @(
    @{ Name = 'Unit'; Path = 'tests/Unit' },
    @{ Name = 'Feature'; Path = 'tests/Feature' },
    @{ Name = 'NFR'; Path = 'tests/NFR' }
)
$failedStages = [System.Collections.Generic.List[string]]::new()

foreach ($stage in $stages) {
    $junitPath = Join-Path $logDirectory "phpunit-serial-$runId-$($stage.Name.ToLowerInvariant()).xml"
    Write-Host "=== $($stage.Name) stage started: $($stage.Path) ===" -ForegroundColor Cyan
    Write-Host "JUnit: $junitPath"
    Write-Host 'PHPUnit default progress is live console feedback; JUnit remains the authoritative report.'

    & $PhpExecutable -d memory_limit=-1 $phpunit --do-not-cache-result --log-junit $junitPath $stage.Path
    $exitCode = $LASTEXITCODE
    Write-Host "PHPUnit exit code: $exitCode"

    $validJUnit = $false
    if (Test-Path -LiteralPath $junitPath -PathType Leaf) {
        try {
            [xml] $report = Get-Content -LiteralPath $junitPath -Raw
            $suiteNodes = @($report.SelectNodes('/testsuites/testsuite | /testsuite'))
            if ($suiteNodes.Count -gt 0) {
                $validJUnit = $true
                $tests = 0
                $assertions = 0
                $errors = 0
                $failures = 0
                $skipped = 0
                foreach ($suite in $suiteNodes) {
                    $tests += [int] $suite.GetAttribute('tests')
                    $assertions += [int] $suite.GetAttribute('assertions')
                    $errors += [int] $suite.GetAttribute('errors')
                    $failures += [int] $suite.GetAttribute('failures')
                    $skipped += [int] $suite.GetAttribute('skipped')
                }

                Write-Host "JUnit validity: PASS ($((Get-Item -LiteralPath $junitPath).Length) bytes)" -ForegroundColor Green
                Write-Host "Totals: tests=$tests assertions=$assertions errors=$errors failures=$failures skipped=$skipped"
            }
        } catch {
            Write-Host "JUnit validity: FAIL ($($_.Exception.Message))" -ForegroundColor Red
        }
    } else {
        Write-Host 'JUnit validity: FAIL (file was not produced)' -ForegroundColor Red
    }

    if ($exitCode -ne 0 -or -not $validJUnit) {
        $failedStages.Add($stage.Name)
    }
    Write-Host "=== $($stage.Name) stage completed ===" -ForegroundColor Cyan
}

if ($failedStages.Count -gt 0) {
    Write-Host "Stages with nonzero exit or invalid JUnit: $($failedStages -join ', ')" -ForegroundColor Yellow
    exit 1
}

Write-Host 'All serial stages exited successfully and produced valid JUnit reports.' -ForegroundColor Green
