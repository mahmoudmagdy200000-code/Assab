# S1-01 Windows environment proposal — archived

**Current disposition: environment prepared; S1-01 Ready for review, not Accepted.** The approved portable PHP 8.4.26, Composer 2.10.3, pnpm 10.34.6 and MySQL 8.4.11 setup and locked dependencies are already present. Current database work uses isolated port 3310 and separate baseline/test schemas. See [baseline.md](baseline.md) and [verification.md](verification.md) for executed evidence.

The proposal below preserves the original alternatives, official-source references and approval boundaries. Versions, free-space observations, absent-file assumptions and paths were recorded at drafting time. It is not an installation script, current authorization, or claim that every proposed action ran. In particular, historical `.local` and 3307 paths are superseded by the existing `.tools/s1-01/mysql-local` environment. No setup, uninstall or database operation is part of final delivery review.

## CURRENT TEST SAFETY GUARD

For the current MySQL test workflow, use the fail-fast Laravel connection guard in [verification.md](verification.md#current-test-safety-guard) before any focused test using `RefreshDatabase`. It permits destructive refresh only on `127.0.0.1:3310/assab_s1_test`; it rejects `assab_s1_local`, non-loopback hosts, URL overrides, alternate ports, and unexpected schema names. `assab_s1_local` is the migrated baseline and must never be refreshed. Test credentials stay process-local and are never printed. The portable MySQL 8.4.11 instance and separate baseline/test schemas are under `.tools/s1-01/mysql-local`; historical setup/recovery commands below are non-current and must not be used.

If starting the Dashboard for a separately authorized local check, run from the Dashboard workspace root with the package's current script: `pnpm --filter @workspace/mockup-sandbox dev`. This documents the command only; no Dashboard server was started for S1-01.

<details>
<summary>Original setup proposal — historical; do not execute</summary>


Prepared 2026-10-07 for `D:\claude\AssabERP`. **This is a proposal, not an executed setup script.** Only repository/runtime inspection, official-source research, and this Markdown document were performed. No software archive was downloaded to the workspace, no installation/configuration/initialization command below was run, and no application code, lockfile, database, service, PATH, Registry, or persistent environment setting was changed. No migration, commit, or push occurred.

## Recommendation and alternatives

Use **PHP 8.4.26 x64 NTS + Composer 2.10.3 PHAR + pnpm 10.34.6 through existing Corepack 0.34.6 + MySQL Community 8.4.11 LTS ZIP**. Install only beneath `.tools\s1-01`; keep disposable DB data beneath `.local\s1-01`; use absolute executable paths. All proposed operations run as the current standard Windows user, with no service registration. This matches the repository's MySQL default more closely than choosing MariaDB without knowing the deployment engine/version. It does not establish production equivalence: obtain deployment engine/version metadata from the owner later, without connecting to production.

| Alternative | Practical tradeoff | Decision |
|---|---|---|
| Official portable ZIP/PHAR and existing Corepack | Few components; removable directories; no Windows integration; local process must be explicitly started/stopped | Recommended |
| PHP 8.3.35 x64 NTS | Also satisfies the inspected PHP constraints; useful if deployment is on 8.3; separate extraction needed | Valid alternate, install only one PHP initially |
| MariaDB 11.4.13 Windows ZIP | Official portable option; MySQL protocol compatibility, but JSON/collation/SQL/locking differences can affect verification | Comparison only, not proposed for installation alongside MySQL |
| Docker/WSL + MySQL | Convenient repeatable isolation when already configured; Docker is unavailable here; introducing virtualization/services/features is a machine change | Not recommended on this laptop for S1-01 |
| XAMPP/Laragon/MSI/global Composer/global pnpm | Adds unneeded web-server/control-panel/global integration; broader changes and rollback | Not proposed |
| SQLite in-memory | Existing PHPUnit configuration; no server; useful narrow test option | Not a MySQL-compatible concurrency baseline |

Official references: [PHP Windows builds](https://www.php.net/downloads.php?os=windows&version=8.4), [pinned PHP release manifest](https://downloads.php.net/~windows/releases/releases.json), [Composer downloads](https://getcomposer.org/download/), [Corepack 0.34.6 usage](https://github.com/nodejs/corepack/blob/v0.34.6/README.md), [pnpm 10.34.6](https://github.com/pnpm/pnpm/releases/tag/v10.34.6), [MySQL 8.4.11 download](https://dev.mysql.com/downloads/mysql/8.4.html), [MySQL ZIP installation](https://dev.mysql.com/doc/refman/8.4/en/windows-install-archive.html), [MariaDB ZIP documentation](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-windows-zip-packages), [MariaDB Q3 releases](https://mariadb.org/mariadb-server-12-3-11-8-11-4-and-10-11-q3-2026-maintenance-releases-and-goodbye-10-6/).

## Verified local prerequisites and compatibility

Windows is 64-bit, NT build 10.0.26300. Existing x64 Visual C++ runtime registry value is v14.51.36247.00, with vcruntime140.dll/vcruntime140_1.dll/msvcp140.dll present. PHP's documented VC++ prerequisite appears satisfied; executable launch remains untested. If it fails, STOP: do not install a runtime silently. No VC++ redistributable installation is proposed.

Node v22.22.2, npm 10.9.7 and Corepack 0.34.6 are already installed under `C:\Program Files\nodejs`. PHP, Composer, pnpm, MySQL/MariaDB and Docker remain absent from PATH. Git's bundled `C:\Program Files\Git\usr\bin\gpg.exe` exists and can be used with a workspace-only keyring after approval; it was not invoked in this run. Existing curl.exe/tar.exe are available. No new archive/signature utility is required. Read-only port inventory found no listener on 3307,3000,8000; check again immediately before any startup. CIM/Get-Volume inspection was unavailable in the restricted session; .NET/Get-PSDrive supplied OS/disk data instead, without escalation.

Drive D: free space observed: **20,049,854,464 bytes, approximately 18.7 GiB**. Disk figures below are planning allowances, not measured installed footprints: no archive was extracted. Reserve **8 GiB** for tools, both dependency trees/caches, scratch files, and a small disposable DB. Downloads/extraction/cache duplication can raise peak use. Recheck free space before installs and stop if the reserve is insufficient.

Lock evidence: Laravel 12.33.0 requires ^8.2; Pest 4.1.2 requires ^8.3.0; PHPUnit 12.4.0 requires >=8.3; openspout 5.3.0 allows ~8.3.0 / ~8.4.0 / ~8.5.0. PHP 8.3.35 and 8.4.26 satisfy these constraints. No compatibility PASS is claimed before composer check-platform-reqs. pnpm lock format is 9.0, with no packageManager version pin. Choose the maintained 10.x patch to avoid a pnpm-major configuration migration. Keep minimumReleaseAge=1440 and the existing build permissions unchanged. The workspace contains both onlyBuiltDependencies and allowBuilds; if the pinned CLI rejects that combination, stop and report the exact error rather than rewrite it in environment preparation.

## Versions, destinations, disk and reversal

In this document `$ToolRoot` is `D:\claude\AssabERP\.tools\s1-01`; `$LocalRoot` is `D:\claude\AssabERP\.local\s1-01`.

| Component | Exact selected version / official artifact | Destination | Disk allowance | Administrator / reversal |
|---|---|---|---|---|
| PHP | 8.4.26 NTS VS17 x64, php-8.4.26-nts-Win32-vs17-x64.zip, official downloads.php.net | $ToolRoot\php-8.4.26 | 33.48 MB download; reserve 250 MiB extracted/config/temp | No admin; stop PHP and remove that owned directory/archive |
| Alternate PHP | 8.3.35 NTS VS16 x64, php-8.3.35-nts-Win32-vs16-x64.zip, same official host | $ToolRoot\php-8.3.35 | 32.3 MB download; reserve 250 MiB | No admin; same removal; not both by default |
| Composer | 2.10.3 composer.phar, getcomposer.org | $ToolRoot\composer-2.10.3 | Reserve 10 MiB tool, 1.5 GiB backend vendor + download cache | No admin; remove PHAR/cache; vendor only if created by this setup and no user work |
| pnpm | 10.34.6, official pnpm package via npm registry and Corepack | $ToolRoot\corepack (Corepack cache-managed layout) | Reserve 100 MiB manager, 3 GiB dependency/store/cache/build allowance | No admin; remove dedicated Corepack/pnpm caches and newly created node_modules after review |
| MySQL | 8.4.11 LTS winx64 ZIP, dev.mysql.com → Oracle CDN | $ToolRoot\mysql-8.4.11-winx64; DB in $LocalRoot\mysql-data | Published ZIP 268.2 MB; reserve 1.5 GiB tools/extraction and 1 GiB initial data/logs | No admin/service; graceful shutdown, then remove owned tools/data if disposable data may be discarded |
| Configuration | Proposal-specific local files, no software version | PHP ini under tool root; Assab\.env; dashboard\artifacts\mockup-sandbox\.env.local; MySQL ini under local root | Under 10 MiB initially, excluding runtime logs | No admin; remove only files created by this proposal; never overwrite pre-existing files |

## Approval boundaries

**Stage A:** Acquire/check/extract tools and prepare non-secret local configuration. This includes workspace cache/temp settings in a dedicated PowerShell process. No change to PATH, `$PROFILE`, system/user environment, Windows certificate stores, or Git configuration. No `corepack enable`, `corepack use`, global install, installer executable, service or elevated command. Close the dedicated shell to discard process environment changes.

**Stage B:** Install locked dependencies initially with scripts/plugins disabled. This does not yet make the Laravel module application runnable. Review locked Composer plugins (wikimedia/composer-merge-plugin 2.1.0 and pestphp/pest-plugin 4.0.0), module autoloading, and bootstrap before approving any later script execution. Do not bypass advisories, platform constraints or frozen-lockfile errors. A complete build may require separately reviewed lifecycle scripts.

**Stage C:** Optional, separately explicit approval for initializing the new disposable MySQL instance and local-only credentials. MySQL initialization creates system tables and an initial root credential; it is distinct from application migrations, but the earlier instruction prohibiting secret creation still applies until explicitly changed. User privately provisions credentials/APP_KEY; none is shown in chat, passed in process arguments, or copied from existing environments. No Laravel migrations, seeders, fixture creation, scheduler, queue workers, financial requests or application startup are included in Stage A/B approval.

All PowerShell blocks below are proposed commands only. Use a fresh non-administrator PowerShell session. Do not paste/run the entire document; approve and execute the selected stage, stop on any failed check, and preserve evidence.

## Stage A — shared workspace-only setup

```powershell
$ErrorActionPreference = 'Stop'
$Workspace = 'D:\claude\AssabERP'
$ToolRoot = Join-Path $Workspace '.tools\s1-01'
$LocalRoot = Join-Path $Workspace '.local\s1-01'
$Downloads = Join-Path $ToolRoot 'downloads'
$CorepackExe = 'C:\Program Files\nodejs\corepack.cmd'
$GpgExe = 'C:\Program Files\Git\usr\bin\gpg.exe'

# Refuse to overwrite an earlier setup; a partial rerun requires inspection.
if ((Test-Path -LiteralPath $ToolRoot) -or (Test-Path -LiteralPath $LocalRoot)) {
    throw 'Setup directory already exists. Inspect it before continuing.'
}
if ((Get-PSDrive -Name D).Free -lt 8GB) { throw 'Reserve at least 8 GiB.' }
$setupDirectories = @(
    $ToolRoot,$LocalRoot,$Downloads,
    "$ToolRoot\temp","$ToolRoot\certs","$ToolRoot\gnupg",
    "$ToolRoot\composer-home","$ToolRoot\composer-cache",
    "$ToolRoot\corepack","$ToolRoot\pnpm-store",
    "$ToolRoot\pnpm-cache","$ToolRoot\pnpm-state",
    "$ToolRoot\npm-cache","$ToolRoot\xdg-config"
)
foreach ($setupDirectory in $setupDirectories) {
    New-Item -ItemType Directory -Path $setupDirectory | Out-Null
}

# Process-only environment: never setx or persistent environment setters.
$env:TEMP = "$ToolRoot\temp"
$env:TMP = "$ToolRoot\temp"
$env:COMPOSER_HOME = "$ToolRoot\composer-home"
$env:COMPOSER_CACHE_DIR = "$ToolRoot\composer-cache"
$env:COREPACK_HOME = "$ToolRoot\corepack"
$env:COREPACK_DEFAULT_TO_LATEST = '0'
$env:COREPACK_ENABLE_AUTO_PIN = '0'
$env:npm_config_cache = "$ToolRoot\npm-cache"
$env:XDG_CACHE_HOME = "$ToolRoot\pnpm-cache"
$env:XDG_STATE_HOME = "$ToolRoot\pnpm-state"
$env:XDG_CONFIG_HOME = "$ToolRoot\xdg-config"

function Assert-Sha256([string]$File, [string]$Expected) {
    if ((Get-FileHash -LiteralPath $File -Algorithm SHA256).Hash -ne $Expected) {
        throw "Checksum mismatch: $File"
    }
}
function Write-NewText([string]$File, [string]$Text) {
    if (Test-Path -LiteralPath $File) { throw "Refusing to overwrite $File" }
    [IO.File]::WriteAllText($File,$Text,[Text.UTF8Encoding]::new($false))
}
```

Process-local temp/cache redirection covers these tools' documented/common locations, not an OS sandbox. Third-party executable code can still access files/network with the user's privileges. Disabling install scripts initially reduces that exposure; do not promise portable software is a security container.

## Stage A — PHP

Use NTS because this baseline uses CLI/built-in development serving, without Apache module integration. Verify the archive against the official SHA-256 before extracting. No debug pack or SDK needed. [PHP source and checksums](https://downloads.php.net/~windows/releases/releases.json).

```powershell
$PhpVersion = '8.4.26'
$PhpArchiveName = 'php-8.4.26-nts-Win32-vs17-x64.zip'
$PhpExpectedSha = 'da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7'
$PhpDir = Join-Path $ToolRoot "php-$PhpVersion"
$PhpZip = Join-Path $Downloads $PhpArchiveName
Invoke-WebRequest -Uri "https://downloads.php.net/~windows/releases/$PhpArchiveName" -OutFile $PhpZip
Assert-Sha256 $PhpZip $PhpExpectedSha
Expand-Archive -LiteralPath $PhpZip -DestinationPath $PhpDir
$PhpExe = Join-Path $PhpDir 'php.exe'
& $PhpExe -n -v
if ($LASTEXITCODE -ne 0) { throw 'PHP failed; do not install system prerequisites automatically.' }
```

If PHP 8.3 is selected instead, replace only the three assignments before downloading, then run the same block from `$PhpDir` onward:

```powershell
$PhpVersion = '8.3.35'
$PhpArchiveName = 'php-8.3.35-nts-Win32-vs16-x64.zip'
$PhpExpectedSha = '25a8e2ac9ff30f1d768d1447c09a600617fa6e6082729f6e95f008b59c91fe45'
```

Project-only PHP configuration: dynamic extensions curl, fileinfo, gd, mbstring, openssl, pdo_mysql, pdo_sqlite, zip. Core/XML extensions shipped built into the selected PHP build must also pass platform checks. Export public certificates from the existing Windows trusted root stores to a local PEM file for PHP TLS; this reads certificates, never private keys, and neither adds nor removes Windows trust. Never disable TLS verification to resolve a download problem.

```powershell
$trustedRoots = Get-ChildItem Cert:\LocalMachine\Root,Cert:\CurrentUser\Root |
    Sort-Object Thumbprint -Unique
$pemBlocks = foreach ($trustedRoot in $trustedRoots) {
    '-----BEGIN CERTIFICATE-----'
    [Convert]::ToBase64String($trustedRoot.RawData,[Base64FormattingOptions]::InsertLineBreaks)
    '-----END CERTIFICATE-----'
}
Write-NewText "$ToolRoot\certs\windows-roots.pem" (($pemBlocks -join "`r`n") + "`r`n")
$phpIni = @"
[PHP]
extension_dir="$PhpDir/ext"
extension=curl
extension=fileinfo
extension=gd
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=zip
date.timezone=Asia/Riyadh
memory_limit=512M
upload_max_filesize=10M
post_max_size=16M
sys_temp_dir="$ToolRoot/temp"
upload_tmp_dir="$ToolRoot/temp"
session.save_path="$ToolRoot/temp"
log_errors=On
error_log="$ToolRoot/php-errors.log"
curl.cainfo="$ToolRoot/certs/windows-roots.pem"
openssl.cafile="$ToolRoot/certs/windows-roots.pem"
"@
Write-NewText "$PhpDir\php.ini" $phpIni
# Prevent inheriting another installation's INI directory in this process.
$env:PHPRC = "$PhpDir\php.ini"
$env:PHP_INI_SCAN_DIR = "$ToolRoot\empty-php-ini-scan"
New-Item -ItemType Directory -Path $env:PHP_INI_SCAN_DIR | Out-Null
& $PhpExe -c "$PhpDir\php.ini" --ini
if ($LASTEXITCODE -ne 0) { throw 'PHP INI check failed.' }
& $PhpExe -c "$PhpDir\php.ini" -m
if ($LASTEXITCODE -ne 0) { throw 'PHP extension check failed.' }
```

Review startup warnings too; a zero exit code alone is insufficient. This plan installs no certificates or VC++ runtime. Reversal: close any PHP process using this directory; remove only the selected PHP directory and its downloaded ZIP using the guarded removal procedure below.

## Stage A — Composer PHAR

Pinned [Composer 2.10.3](https://getcomposer.org/download/) is invoked through the selected PHP executable. Do not run the Windows installer, composer setup/update, or any global Composer command. Official SHA-256 is pinned below.

```powershell
$ComposerDir = Join-Path $ToolRoot 'composer-2.10.3'
New-Item -ItemType Directory -Path $ComposerDir | Out-Null
$ComposerPhar = Join-Path $ComposerDir 'composer.phar'
Invoke-WebRequest -Uri 'https://getcomposer.org/download/2.10.3/composer.phar' -OutFile $ComposerPhar
Assert-Sha256 $ComposerPhar '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
& $PhpExe -c "$PhpDir\php.ini" $ComposerPhar --version
if ($LASTEXITCODE -ne 0) { throw 'Composer launch failed.' }
```

Security: PHAR checksum checked before execution; no installer scripts; dedicated empty home avoids inheriting global plugins/auth files. Do not supply GitHub/database credentials or suppress security checks. Composer package scripts/plugins remain disabled for the initial install. Reversal: remove composer-2.10.3, composer-home and composer-cache created here. Removing vendor is separate and only safe if this setup created it and it contains no user changes.

## Stage A — pnpm through existing Corepack

Official package identity: `pnpm@10.34.6`, published by pnpm at the [official release](https://github.com/pnpm/pnpm/releases/tag/v10.34.6); registry artifact `https://registry.npmjs.org/pnpm/-/pnpm-10.34.6.tgz`. Corepack performs its registry integrity/signature checks. Do not fetch/execute the tarball directly, disable integrity keys, run corepack enable/use/install -g, or change packageManager metadata. [Corepack documents direct versioned invocation and COREPACK_HOME](https://github.com/nodejs/corepack/blob/v0.34.6/README.md).

```powershell
Push-Location $Workspace
try {
    & $CorepackExe 'pnpm@10.34.6' --version
    if ($LASTEXITCODE -ne 0) { throw 'pnpm acquisition/verification failed; do not bypass signature checks.' }
} finally { Pop-Location }
```

This version command **downloads pnpm on first use**, so it is an installation step requiring approval; it was not run during discovery. Using the absolute Corepack path avoids changing global shims or PATH. A signature/key mismatch stops the proposal; any Corepack upgrade would need a separately reviewed workspace-local plan. Reversal: delete only dedicated corepack/pnpm-store/pnpm-cache/pnpm-state/npm-cache directories after stopping their processes; existing system Node/Corepack remains intact.

## Stage A — MySQL portable binaries and verification

Acquire Oracle's [MySQL Community 8.4.11 ZIP](https://dev.mysql.com/downloads/mysql/8.4.html), not the MSI/Configurator or debug/test ZIP. Existing Git-bundled GPG uses an isolated workspace keyring. The Oracle release-key fingerprint is pinned from [Oracle's signature-verification documentation](https://dev.mysql.com/doc/refman/8.4/en/checking-gpg-signature.html). Package MD5 alone is not authentication; detached GPG signature verification is the execution gate. No archive or signature was downloaded locally in this run.

```powershell
$MysqlZip = Join-Path $Downloads 'mysql-8.4.11-winx64.zip'
$MysqlSig = "$MysqlZip.asc"
$MysqlKey = Join-Path $Downloads 'mysql-release-key.asc'
$MysqlFingerprint = 'BCA43417C3B485DD128EC6D4B7B3B788A8D3785C'
Invoke-WebRequest -Uri 'https://dev.mysql.com/get/Downloads/MySQL-8.4/mysql-8.4.11-winx64.zip' -OutFile $MysqlZip
Invoke-WebRequest -Uri 'https://dev.mysql.com/downloads/gpg/?file=mysql-8.4.11-winx64.zip&p=23' -OutFile $MysqlSig
Invoke-WebRequest -Uri 'https://repo.mysql.com/RPM-GPG-KEY-mysql-2025' -OutFile $MysqlKey
$keyInfo = & $GpgExe --homedir "$ToolRoot\gnupg" --no-options --batch --with-colons --import-options show-only --import $MysqlKey
if ($LASTEXITCODE -ne 0) { throw 'Could not inspect MySQL signing key.' }
$keyFingerprints = @($keyInfo | Where-Object { $_ -like 'fpr:*' } | ForEach-Object { ($_ -split ':')[9] })
if ($keyFingerprints.Count -eq 0 -or $keyFingerprints[0] -ne $MysqlFingerprint) {
    throw 'Unexpected MySQL release-key fingerprint.'
}
& $GpgExe --homedir "$ToolRoot\gnupg" --no-options --batch --import $MysqlKey
if ($LASTEXITCODE -ne 0) { throw 'MySQL key import failed.' }
& $GpgExe --homedir "$ToolRoot\gnupg" --no-options --batch --no-auto-key-retrieve --verify $MysqlSig $MysqlZip
if ($LASTEXITCODE -ne 0) { throw 'MySQL archive signature failed; do not extract or run it.' }
Get-FileHash -LiteralPath $MysqlZip -Algorithm SHA256
Expand-Archive -LiteralPath $MysqlZip -DestinationPath $ToolRoot
$MysqlDir = Join-Path $ToolRoot 'mysql-8.4.11-winx64'
$MysqldExe = Join-Path $MysqlDir 'bin\mysqld.exe'
& $MysqldExe --no-defaults --version
if ($LASTEXITCODE -ne 0) { throw 'MySQL binary launch failed.' }
```

Stop if the signing key is expired/revoked/unexpected, or the existing GPG cannot verify it; do not import an arbitrary replacement key or install a verifier globally. Record the archive SHA-256 for reproducibility after signature verification. No service, database initialization or listening process is created by --version. Public keys in the dedicated GPG directory do not change Windows certificate trust or the user's existing keyring.

## Stage A — non-secret project configuration

Create only missing ignored local files, from an allowlisted selection of repository defaults. Do not blindly copy example files: dashboard examples contain a hosted API base, and backend test configuration includes credentials outside this proposal. Empty APP_KEY/DB_PASSWORD are intentional placeholders; they are not usable authentication and do not authorize secret generation. The user privately completes local credentials later. No external service keys are copied.

```powershell
$BackendEnv = Join-Path $Workspace 'Assab\.env'
$DashboardEnv = Join-Path $Workspace 'dashboard\artifacts\mockup-sandbox\.env.local'
if ((Test-Path -LiteralPath $BackendEnv) -or (Test-Path -LiteralPath $DashboardEnv)) {
    throw 'Local env file already exists; preserve it and review without printing secrets.'
}
git -c safe.directory=D:/claude/AssabERP/Assab -C "$Workspace\Assab" check-ignore --quiet .env
if ($LASTEXITCODE -ne 0) { throw 'Backend .env is not ignored.' }
git -c safe.directory=D:/claude/AssabERP/dashboard -C "$Workspace\dashboard" check-ignore --quiet artifacts/mockup-sandbox/.env.local
if ($LASTEXITCODE -ne 0) { throw 'Dashboard .env.local is not ignored.' }
$backendEnvText = @'
APP_NAME=AssabERP-S1-01-Local
APP_ENV=local
APP_KEY=
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
APP_MAINTENANCE_DRIVER=file
LOG_CHANNEL=single
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_URL=
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=assab_s1_local
DB_USERNAME=assab_s1_local
DB_PASSWORD=
DB_SOCKET=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
MAIL_MAILER=array
BROADCAST_CONNECTION=null
FCM_DRIVER=null
FILESYSTEM_DISK=local
'@
Write-NewText $BackendEnv ($backendEnvText + "`r`n")
$dashboardEnvText = @'
API_PROXY_TARGET=http://127.0.0.1:8000
VITE_API_BASE_URL=http://127.0.0.1:3000
VITE_PUSHER_APP_KEY=
VITE_PUSHER_APP_CLUSTER=
'@
Write-NewText $DashboardEnv ($dashboardEnvText + "`r`n")
```

Do not run the backend yet. QUEUE_CONNECTION=database intentionally leaves work queued until a later reviewed worker/test configuration exists; that table does not exist before migrations. FCM null is explicitly supported in the repository. Broadcasting config is absent from the repository's config directory; validate effective null driver after dependencies/bootstrap rather than assume this setting alone prevents every integration. Review inherited process APP/DB/service variables without printing their values; cached configuration and process variables can override .env. Do not delete a cached config file or run config:clear until its provenance is inspected. No credentials/global env are read into this template.

Later testing needs a separate local `assab_s1_test` database/user with grants only on that database. Never point RefreshDatabase at assab_s1_local. Existing phpunit.xml selects SQLite :memory:, whereas tracked .env.testing names MySQL; verify effective settings and prepare a separate ignored/local test configuration in a later approved baseline step without changing tracked files. Neither tests nor migrations are authorized by this proposal.

Vite currently binds 0.0.0.0 in source. When startup is separately authorized, use explicit CLI host/port overrides and local process URL overrides rather than edit application code. Proposed later commands (not part of Stage A/B):

```powershell
$env:API_PROXY_TARGET = 'http://127.0.0.1:8000'
$env:VITE_API_BASE_URL = 'http://127.0.0.1:3000'
Push-Location "$Workspace\dashboard"
try {
    & $CorepackExe 'pnpm@10.34.6' --filter '@workspace/mockup-sandbox' dev --host 127.0.0.1 --port 3000 --strictPort
} finally { Pop-Location }
# Separate terminal, only after approved local DB/config/credentials/fixtures:
# & $PhpExe -c "$PhpDir\php.ini" "$Workspace\Assab\artisan" serve --host=127.0.0.1 --port=8000
```

Check actual listeners and the browser's request base before financial use. Do not connect to the examples' hosted backend. Local configuration is not a firewall; external integration code must also be disabled/faked before synthetic tests.

## Stage B — optional locked dependency installation

Exact dependency versions are those already in composer.lock and pnpm-lock.yaml; do not regenerate either. Workspace-only caches above must be active. No missing credential prompt is an instruction to read another repository's credentials.

```powershell
Push-Location "$Workspace\Assab"
try {
    if (Test-Path -LiteralPath 'vendor') { throw 'vendor already exists; inspect before installing.' }
    $composerLockBefore = (Get-FileHash composer.lock -Algorithm SHA256).Hash
    & $PhpExe -c "$PhpDir\php.ini" $ComposerPhar install --prefer-dist --no-interaction --no-scripts --no-plugins
    if ($LASTEXITCODE -ne 0) { throw 'Locked Composer install failed; preserve evidence and stop.' }
    if ((Get-FileHash composer.lock -Algorithm SHA256).Hash -ne $composerLockBefore) { throw 'composer.lock changed; stop.' }
    & $PhpExe -c "$PhpDir\php.ini" $ComposerPhar check-platform-reqs --no-plugins
    if ($LASTEXITCODE -ne 0) { throw 'Platform requirements failed.' }
} finally { Pop-Location }

Push-Location "$Workspace\dashboard"
try {
    if (Test-Path -LiteralPath 'node_modules') { throw 'node_modules already exists; inspect first.' }
    $pnpmLockBefore = (Get-FileHash pnpm-lock.yaml -Algorithm SHA256).Hash
    & $CorepackExe 'pnpm@10.34.6' install --frozen-lockfile --ignore-scripts --store-dir "$ToolRoot\pnpm-store" --cache-dir "$ToolRoot\pnpm-cache" --state-dir "$ToolRoot\pnpm-state"
    if ($LASTEXITCODE -ne 0) { throw 'Frozen pnpm install failed; do not update the lockfile or relax controls.' }
    if ((Get-FileHash pnpm-lock.yaml -Algorithm SHA256).Hash -ne $pnpmLockBefore) { throw 'pnpm-lock.yaml changed; stop.' }
} finally { Pop-Location }
```

This conservative first pass can leave required plugin autoload/build effects absent. It is preparation, not a successful build/test baseline. In particular, module Composer metadata must be merged before route boot; installing with --no-plugins alone does not prove routes are available. Review exact plugin/script behavior and any blocked advisories before the next baseline step. Do not silently turn on all scripts or suppress minimumReleaseAge.

## Stage C — disposable database, only after explicit additional approval

The initialization directory must be new, verified empty and confined to the workspace. Bind only 127.0.0.1:3307, disable the X protocol listener and binary logging, retain normal InnoDB durability, and keep logs/temp/data local. Do not install a Windows service. Deny/stop if Windows requests a firewall change; do not click Allow.

The following commands initialize **MySQL system tables**, generate an initial root credential, and optionally start a local process. They do not run Laravel migrations. Because secret creation was previously prohibited, these require approval specifically covering local database credential initialization. No password is printed by the agent: initialization output goes to an owner-restricted local directory, which the user reviews privately. If that credential handling is not approved, stop after extracting/version-checking binaries.

```powershell
# Restrict only the newly created workspace-local directory, not machine ACLs.
$currentUserSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $LocalRoot /inheritance:r /grant:r ('*' + $currentUserSid + ':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F'
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict the local DB/credential directory.' }
$MysqlData = Join-Path $LocalRoot 'mysql-data'
if (Test-Path -LiteralPath $MysqlData) { throw 'Refuse to initialize an existing data directory.' }
$initProcess = Start-Process -FilePath $MysqldExe -ArgumentList @(
    '--no-defaults','--initialize',"--basedir=$MysqlDir","--datadir=$MysqlData"
) -WorkingDirectory $LocalRoot -WindowStyle Hidden -Wait -PassThru `
  -RedirectStandardOutput "$LocalRoot\mysql-init.stdout.log" `
  -RedirectStandardError "$LocalRoot\mysql-init.stderr.log"
if ($initProcess.ExitCode -ne 0) { throw 'Initialization failed; retain logs without printing their contents.' }

New-Item -ItemType Directory -Path "$LocalRoot\mysql-temp" | Out-Null
$mysqlConfig = @"
[mysqld]
basedir=$($MysqlDir.Replace('\','/'))
datadir=$($MysqlData.Replace('\','/'))
tmpdir=$($LocalRoot.Replace('\','/'))/mysql-temp
log-error=$($LocalRoot.Replace('\','/'))/mysql-runtime.log
pid-file=$($LocalRoot.Replace('\','/'))/mysql.pid
bind-address=127.0.0.1
port=3307
mysqlx=0
skip-log-bin
local-infile=0
secure-file-priv=NULL
innodb-buffer-pool-size=256M
innodb-redo-log-capacity=128M
max-connections=30
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
"@
Write-NewText "$LocalRoot\my.ini" $mysqlConfig
$portCheck = Get-NetTCPConnection -State Listen -ErrorAction Stop |
    Where-Object { $_.LocalPort -eq 3307 }
if ($portCheck) { throw 'Port 3307 already belongs to another listener; do not stop it.' }
$mysqlProcess = Start-Process -FilePath $MysqldExe `
    -ArgumentList @("--defaults-file=$LocalRoot\my.ini") `
    -WorkingDirectory $LocalRoot -WindowStyle Hidden -PassThru
```

MySQL's official initialization instructions specify secure --initialize and warn that its initial password goes to diagnostic output. The command above keeps that output local, without --console. [Initialization behavior](https://dev.mysql.com/doc/refman/8.4/en/data-directory-initialization.html). --defaults-file is first in normal startup to avoid loading another installation's defaults. No recovery/replication/remote source is configured. Parent process TEMP/TMP must still point inside the tool root.

After startup, verify the new process path/PID and listener, then let the user privately reset root and provision least-privilege local/test accounts. Use an interactive client password prompt, never a password on the command line, and never expose the initialization log in tool output:

```powershell
# User-operated private session only; do not run through logged agent output.
& "$MysqlDir\bin\mysql.exe" --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --connect-expired-password
```

Account policy: root only for local bootstrap; app user restricted to assab_s1_local, test user restricted to assab_s1_test; no wildcard-host account, no global FILE/SUPER/GRANT privilege, no production imports. Credentials and APP_KEY are supplied privately by the user; commands embedding them are deliberately excluded. App schema creation/migrations and role fixtures require the next explicit S1-01 database operation review. DB initialization alone does not mean the application is ready.

## Reversal / uninstall

No administrator privileges or machine uninstallers are needed. First shut down only this MySQL instance using its explicit endpoint and an interactive password prompt; do not Stop-Service, taskkill or terminate unrelated processes. Close the dedicated PHP/Node terminals. Closing the setup PowerShell session discards its process environment variables; there are no persistent settings to undo.

```powershell
# User-operated prompt. Verify this is the dedicated instance before shutdown.
& 'D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqladmin.exe' --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password shutdown
if ($LASTEXITCODE -ne 0) { throw 'Graceful shutdown not confirmed; do not delete data.' }
```

After confirming all setup processes have exited and disposable data may be discarded, use this guarded removal only on the exact owned setup roots. This is an uninstall proposal, not authorization to delete existing work. It rejects reparse points to avoid traversing a junction into another directory. Dry-run first; review the actual paths/output; removing -WhatIf is a separate explicit action.

```powershell
$allowedRemovalRoots = @(
    'D:\claude\AssabERP\.tools\s1-01',
    'D:\claude\AssabERP\.local\s1-01'
)
foreach ($removalRoot in $allowedRemovalRoots) {
    if (-not (Test-Path -LiteralPath $removalRoot)) { continue }
    $resolvedRemovalRoot = (Resolve-Path -LiteralPath $removalRoot).Path
    if ($resolvedRemovalRoot -notin $allowedRemovalRoots) { throw 'Unexpected resolved removal target.' }
    $rootItem = Get-Item -LiteralPath $resolvedRemovalRoot -Force
    if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Refuse reparse-point root.' }
    $linkedItems = Get-ChildItem -LiteralPath $resolvedRemovalRoot -Recurse -Force -Attributes ReparsePoint
    if ($linkedItems) { throw 'Review reparse points manually before removal.' }
    Remove-Item -LiteralPath $resolvedRemovalRoot -Recurse -WhatIf
}
```

Project files are separate: delete Assab/.env and dashboard/artifacts/mockup-sandbox/.env.local only if created by this setup and no longer needed. Preserve any privately added credentials appropriately; do not print them. vendor/node_modules/build output must be inspected before removal, because pnpm uses links and generated directories may contain later work. Never use git clean/reset or a blanket recursive workspace deletion. Existing Node, Corepack, VC++ runtime and Git/GPG remain installed and unchanged.

## Status and requested decision

Recommended first approval scope: **Stage A only**, including verified portable tools and non-secret ignored env templates. Stage B locked dependency downloads can be approved separately or explicitly together with A. Keep Stage C initialization/credential handling separate because of the earlier secrets restriction. No stage includes migrations, synthetic financial calls, application-code edits, commits/pushes or machine changes.

Historical proposal note: at drafting time, extraction sizes, executable compatibility, signature verification, dependency advisories, module boot and database behavior were not yet verified. The subsequent installation and baseline results are recorded in baseline.md and verification.md.

</details>
