# Serial PHPUnit workflow

Run the full suites in order with the repository helper:

```powershell
.\scripts\run-serial-tests.ps1
```

The helper runs Unit, Feature, then NFR serially. Each stage uses the portable PHP 8.4.26 runtime by default, `memory_limit=-1`, `--do-not-cache-result`, and a fresh timestamped JUnit file under ignored `storage/logs/`. PHPUnit's default progress renderer remains enabled, so test progress appears in the console during long Feature and NFR stages. The helper prints each stage start, live command output, exit code, JUnit validity, aggregate counts, and completion. A nonzero stage does not prevent later stages from running; the helper returns nonzero if any stage failed or did not produce parseable JUnit.

Console progress is for operator feedback. It does **not** affect JUnit validity; the XML report is the authoritative machine-readable evidence. Do not use `--no-progress` for normal long-suite runs. Do not use `--testdox` by default for the full Feature or NFR suite; it creates excessive console output. `--testdox` remains useful for focused debugging.

The equivalent command for a single stage is:

```powershell
D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe `
  -d memory_limit=-1 `
  vendor/bin/phpunit `
  --do-not-cache-result `
  --log-junit storage/logs/<fresh-report.xml> `
  tests/Feature
```

Use a new output filename for every run. Never add generated JUnit files to Git; `storage/logs/` is ignored.
