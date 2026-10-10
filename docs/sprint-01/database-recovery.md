# S1-01 local MySQL recovery evidence and archived planning

**Current S1-01 status: Ready for review; not Accepted.** The former 3307/3308 infrastructure blockers below are historical. The working environment is the separately approved MySQL 8.4.11 instance at `127.0.0.1:3310`, rooted at `.tools/s1-01/mysql-local`. The final six-file test baseline passed on its separate `assab_s1_test` schema. This delivery review did not connect to, start, stop, back up or restore any database.

The earlier 3308-to-3309 cold-backup/restore test was reported passed in the task history after removal of an unsupported mysqlcheck option. That is historical recovery evidence, not a claim of present 3308 access or a newly repeated 3310 restore test. The separately verified 3310 pre-migration checkpoint is `.tools/s1-01/mysql-local/backups/pre-migration-20261007-093624979`: 173 data/config files, 195,632,367 bytes, matching source/backup manifests with SHA-256 `ABBE5884FC2B1BA0AC933904673EB30157F5324ACD2267643C8714CCE12430AB`. It predates application migrations and is not a backup of the final migrated baseline. The original 3310 restart, identity, empty pre-migration schema and account checks were verified at creation; later migrations succeeded. See [migration-preflight.md](migration-preflight.md) and [verification.md](verification.md).

Current credentials and recovery artifacts are protected local files outside this repository. No credential contents are included here. Personal workstation/account identifiers in the archive are replaced with role placeholders; old process IDs and disk figures are point-in-time diagnostics. **No archived command is a current execution instruction.** Any future recovery operation requires fresh identity/path checks and separate authorization, and must not affect 3307/3308 or overwrite an original datadir.

<details>
<summary>Archived 3307/3308 diagnostics and proposals — superseded; do not execute</summary>

All present-tense statements and GO/NO-GO decisions in this archive refer to their historical investigation stage. They do not override the current disposition above.

## Findings from read-only discovery

The portable server binary is `D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqld.exe`. The active server previously identified by the authenticated local SQL check reports MySQL `8.4.11`, `@@hostname=<local-host>`, `@@port=3307`, `@@bind_address=127.0.0.1`, `@@datadir=D:\claude\AssabERP\.tools\s1-01\mysql-data\`, and current schema `assab_s1_local`. Its option file is `D:\claude\AssabERP\.tools\s1-01\mysql-s1-01.ini`; it sets that datadir, loopback bind, port 3307, `mysqlx=0`, `local-infile=0`, and `secure-file-priv=NULL`.

The listener inventory maps TCP `127.0.0.1:3307` to PID 21908, with the portable `mysqld.exe` image. A second process, PID 18784, uses the same image and start time; its parent/role could not be established because Windows process details were access-denied. Neither process should be stopped by PID or force-terminated until its role is resolved. Before a future copy, both processes must be re-enumerated and the listener-to-image mapping confirmed again.

The data directory has MySQL system schemas and the `assab_s1_local` schema directory. The earlier migration preflight reported zero application tables/views in `assab_s1_local`; this recovery-readiness task did not write to the database. A fresh authenticated empty-schema check is required immediately before any approved backup. The data directory size observed during prior discovery was about 209,042,312 bytes; remeasure before reserving backup space.

The datadir and portable binaries are owned by `<previous-sandbox-identity>`. Their inherited ACLs include `<historical-sandbox-group>`, an additional sandbox SID, `Authenticated Users` with Modify, Administrators and SYSTEM with Full Control, and BUILTIN Users with Read/Execute. That is broader than a private backup ACL. The root option file is restricted to the current sandbox identity with Full Control. Do not assume a copied directory is private: inspect and tighten ACLs on the *new backup/recovery copy only* after separate approval, and verify the resulting ACL. No source ACL or Windows-wide setting should be changed.

## Root authentication and shutdown limitation

The existing `mysql-root.cnf` authentication attempt over `127.0.0.1:3307` failed with MySQL error 1045. The server has `skip-name-resolve=ON`, `named_pipe=OFF`, and `shared_memory=OFF`; the prior `localhost` attempt also used TCP and failed. The migration account is scoped to `assab_s1_local.*` and has no global `SHUTDOWN` privilege. MySQL documents that `SHUTDOWN`/`mysqladmin shutdown` requires the global `SHUTDOWN` privilege. No account, grant, or authentication setting was changed.

Therefore, **there is no currently verified graceful shutdown channel**. Do not run `Stop-Process`, `taskkill`, kill a PID, stop an unverified process, or copy live database files. A database/schema-scoped application connection is not a shutdown mechanism. A future local root connection may be used only after it has been independently verified and explicitly approved. A foreground `mysqld --console` process is useful for a *separately prepared recovery instance*, but it does not safely stop the existing hidden/background instance. MySQL documents `--console` as console/error-log behavior; it is not itself proof that interrupting a process is a graceful shutdown method.

## A. Shutdown procedure (proposed; not executed)

1. Record server version, effective datadir, schema, process IDs, executable paths, listener, and a read-only schema inventory. Confirm only the isolated local instance is in scope.
2. Resolve PID 18784's role and parent without terminating it. If either process cannot be positively distinguished, stop here.
3. Use a verified, authorized local administrative connection to issue `SHUTDOWN`; wait for the client success response. Do not add grants or change root authentication as part of this procedure.
4. Confirm TCP 3307 has no listener and that no `mysqld.exe` process using the portable binary/datadir remains. Recheck after a short interval. A missing listener alone is insufficient if an instance is still opening files.
5. If no authorized graceful path exists, leave MySQL running and mark the operation **NO-GO**. Do not substitute process termination.

Candidate command, only after separate approval and only if root authentication has first been confirmed without changing privileges:

```powershell
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$mysqlAdmin = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqladmin.exe'
$rootDefaults = Join-Path $toolRoot 'mysql-root.cnf'
& $mysqlAdmin "--defaults-extra-file=$rootDefaults" --protocol=TCP --host=127.0.0.1 --port=3307 shutdown
if ($LASTEXITCODE -ne 0) { throw "MySQL graceful shutdown failed: $LASTEXITCODE" }
netstat.exe -ano -p tcp | Select-String ':3307\s'
Get-Process -Name mysqld -ErrorAction SilentlyContinue | Select-Object Id,Path,StartTime
```

Current expected result is authentication failure (1045), so this command is **not presently executable as a safe shutdown procedure**. It is recorded to make the limitation concrete, not as an instruction to try repeatedly. It references the existing private root option file and does not print its contents.

## B. Cold-backup procedure (proposed; not executed)

Use an offline physical copy of the entire MySQL datadir, not a live file copy. MySQL describes physical backups as copies of the database files and permits offline copying while the server is stopped. Include the active option file and a record of the exact MySQL binary version. Keep the backup below the workspace tool root and outside the source datadir.

Proposed paths (timestamp must be unique; every destination must be absent):

- Source datadir: `D:\claude\AssabERP\.tools\s1-01\mysql-data`
- Backup root: `D:\claude\AssabERP\.tools\s1-01\backups\mysql-cold-YYYYMMDD-HHMMSS`
- Backup datadir: `<backup root>\mysql-data`
- Config copy: `<backup root>\mysql-s1-01.ini`
- File manifest: `<backup root>\sha256-manifest.csv`
- ACL evidence: `<backup root>\acl-source.txt` and `<backup root>\acl-backup.txt`

Before copying, prove source and destination are distinct canonical paths, both under `.tools\s1-01`, destination does not exist, neither path is a reparse point, free space exceeds source bytes plus a reasonable margin, MySQL is fully stopped, and the schema check/identity record was captured. Do not use `/MIR`, `/PURGE`, or a destination that already exists. A failed/partial copy is retained for diagnosis and must not be treated as a valid backup.

Example commands for a future approved run (set a fresh timestamp; **not executed**):

```powershell
$ErrorActionPreference = 'Stop'
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$source = Join-Path $toolRoot 'mysql-data'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $toolRoot "backups\mysql-cold-$stamp"
$backupData = Join-Path $backupRoot 'mysql-data'
$sourceConfig = Join-Path $toolRoot 'mysql-s1-01.ini'
$backupConfig = Join-Path $backupRoot 'mysql-s1-01.ini'
if ((Resolve-Path -LiteralPath $source).Path -eq $backupData) { throw 'Source and destination collide' }
if (Test-Path -LiteralPath $backupRoot) { throw 'Backup destination already exists; do not overwrite' }
if ((Get-Item -LiteralPath $source).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Source is a reparse point' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
$sourceBytes = (Get-ChildItem -LiteralPath $source -Recurse -File | Measure-Object Length -Sum).Sum
$drive = Get-PSDrive -Name ([IO.Path]::GetPathRoot($backupRoot).TrimEnd(':\'))
if ($drive.Free -lt ($sourceBytes * 1.2)) { throw 'Insufficient free space for a cold copy plus margin' }
robocopy.exe $source $backupData /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /XJ
if ($LASTEXITCODE -ge 8) { throw "Robocopy failed with exit code $LASTEXITCODE; preserve partial backup for review" }
Copy-Item -LiteralPath $sourceConfig -Destination $backupConfig
Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object {
  $relative = [IO.Path]::GetRelativePath($source, $_.FullName)
  [pscustomobject]@{ Path=$relative; SHA256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
} | Export-Csv -NoTypeInformation -Encoding utf8 -LiteralPath (Join-Path $backupRoot 'sha256-source.csv')
Get-ChildItem -LiteralPath $backupData -Recurse -File | ForEach-Object {
  $relative = [IO.Path]::GetRelativePath($backupData, $_.FullName)
  [pscustomobject]@{ Path=$relative; SHA256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
} | Export-Csv -NoTypeInformation -Encoding utf8 -LiteralPath (Join-Path $backupRoot 'sha256-backup.csv')
Get-FileHash -LiteralPath $sourceConfig -Algorithm SHA256 | Export-Csv -NoTypeInformation -LiteralPath (Join-Path $backupRoot 'config-sha256.csv')
icacls.exe $source /T /C | Out-File -Encoding utf8 (Join-Path $backupRoot 'acl-source.txt')
icacls.exe $backupRoot /T /C | Out-File -Encoding utf8 (Join-Path $backupRoot 'acl-backup.txt')
```

Compare source and backup manifests by relative path, length, and SHA-256. Because MySQL is offline, hashes should match exactly. Verify backup ACLs are limited to the intended local identity and required system administrators/SYSTEM; stop if broad inherited Modify access remains. `/COPY:DAT` does not preserve ACLs; ACL evidence is captured separately so the copy can be secured without changing source permissions. Preserve the exact original option file and record its hash.

## C. Isolated restore procedure (proposed; not executed)

Never restore over `mysql-data`. Restore only to a new, absent sibling directory, use a separate option file, and bind the recovery instance to loopback on port 3308. Keep the original data directory and original option file unchanged. Reuse the same 8.4.11 portable binaries. The recovery option file must point *only* to the recovery datadir and its own error log, with `bind-address=127.0.0.1`, `port=3308`, `mysqlx=0`, `local-infile=0`, and `secure-file-priv=NULL`.

Before startup, canonicalize and compare paths; assert recovery datadir is not the original or backup; ensure 3308 is unused; verify copied file hashes against the backup manifest; ensure no reparse points; and check free space. Do not reuse port 3307 or change the original config. Start only the recovery instance with `--defaults-file=<recovery config> --console` in a dedicated PowerShell window, verify its ready message, and query `VERSION()`, `@@port`, `@@bind_address`, `@@datadir`, `DATABASE()`, and `SHOW DATABASES`. All reported paths must identify the disposable recovery copy; the only listener must be `127.0.0.1:3308`. Confirm no migration/seed/test command ran. A physical cold restore is version/platform-specific; using the exact same MySQL 8.4.11 binary distribution reduces compatibility risk.

Example restore commands (new unique timestamp; **not executed**):

```powershell
$ErrorActionPreference = 'Stop'
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $toolRoot 'backups\mysql-cold-REPLACE-WITH-APPROVED-TIMESTAMP'
$backupData = Join-Path $backupRoot 'mysql-data'
$recoveryData = Join-Path $toolRoot "mysql-recovery-data-$stamp"
$recoveryIni = Join-Path $toolRoot "mysql-recovery-$stamp.ini"
$originalData = [IO.Path]::GetFullPath((Join-Path $toolRoot 'mysql-data')).TrimEnd('\')
$resolvedRecovery = [IO.Path]::GetFullPath($recoveryData).TrimEnd('\')
if ($resolvedRecovery -eq $originalData -or $resolvedRecovery -eq [IO.Path]::GetFullPath($backupData).TrimEnd('\')) { throw 'Recovery target collides with source/backup' }
if (Test-Path -LiteralPath $recoveryData) { throw 'Recovery target exists; do not overwrite' }
if (Test-Path -LiteralPath $recoveryIni) { throw 'Recovery config exists; do not overwrite' }
if (netstat.exe -ano -p tcp | Select-String ':3308\s+.*LISTENING') { throw 'Port 3308 is already listening' }
robocopy.exe $backupData $recoveryData /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /XJ
if ($LASTEXITCODE -ge 8) { throw "Restore copy failed with exit code $LASTEXITCODE; keep the recovery path isolated" }
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($recoveryData.Replace('\','/'))
port=3308
bind-address=127.0.0.1
skip-name-resolve=ON
mysqlx=0
local-infile=0
secure-file-priv=NULL
log-error=$($recoveryData.Replace('\','/'))/mysql-recovery-error.log
"@ | Set-Content -LiteralPath $recoveryIni -Encoding ascii
Get-FileHash -LiteralPath $recoveryIni -Algorithm SHA256
& (Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqld.exe') "--defaults-file=$recoveryIni" --console
```

The `mysqld` invocation is intentionally foreground. Do not start it until the original instance is confirmed stopped and the restore is separately approved. The copied root account has the same authentication limitation, so a clean graceful shutdown of the recovery instance is not yet demonstrated either. Do not use Ctrl+C, `Stop-Process`, or `taskkill` as a substitute unless a MySQL-documented graceful mechanism for this exact Windows process has been independently established. A recovery run is not complete until the copied instance has also been safely shut down and its listener/process disappearance verified.

## D. Safety checks, evidence, and rollback

Capture timestamped evidence under the approved backup/recovery root: server identity query; process executable/PID and listener map; source and destination canonical paths; source/backup/recovery file counts and total bytes; source-vs-backup and backup-vs-recovery SHA-256 comparisons; config SHA-256; ACL listings; MySQL startup/error log; recovery identity query; and post-shutdown listener/process checks. Redact credentials. Never place passwords in command arguments or output.

Rollback is intentionally non-destructive: leave the original `mysql-data` and `mysql-s1-01.ini` untouched throughout. If restore verification fails, do not redirect the original configuration, do not copy files back over the source, and do not delete the failed recovery directory until it is confirmed stopped and a separate cleanup approval is given. Preserve both the cold backup and failure evidence. A future migration test, if separately authorized after the NO-GO is resolved, should use a fresh disposable clone of the verified cold backup, never the sole original.

## E. GO / NO-GO

**NO-GO for executing the recovery test now.** Reasons: (1) root authentication over the configured TCP path is known to fail; (2) the schema-scoped migrator cannot shut down MySQL; (3) no enabled local named-pipe/shared-memory administrative path was verified; (4) a second `mysqld.exe` process is unresolved; and (5) the datadir's inherited ACL includes `Authenticated Users: Modify`, so a private backup ACL must be designed and verified on a new copy. The source datadir remains running and untouched. Read-only discovery and this documentation are complete; no backup or restore has been attempted.

To move forward, obtain separate authorization with the exact commands and paths for resolving a supported graceful shutdown channel (without changing accounts/privileges unless separately approved), then approve cold-copy creation, then approve isolated restore execution and recovery-instance shutdown/cleanup as separate operations. This approval must not be interpreted as migration authorization.

## Read-only follow-up diagnostics (2026-10-07)

This follow-up used read-only PowerShell, `icacls`, `netstat`, and MySQL `SELECT`/`SHOW GRANTS` only. It did not stop/restart MySQL, change ACLs or accounts, or copy files.

### Process inventory and relationship

| PID | Parent PID | Image path | Start time | Command line | Listener / datadir |
|---|---|---|---|---|---|
| 21908 | UNKNOWN (CIM denied) | `D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqld.exe` | 2026-10-07 04:51:40 | UNKNOWN (CIM denied) | Owns `127.0.0.1:3307`; authenticated SQL for that listener reports `D:\claude\AssabERP\.tools\s1-01\mysql-data\` |
| 18784 | UNKNOWN (CIM denied) | `D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqld.exe` | 2026-10-07 04:51:40 | UNKNOWN (CIM denied) | No listener among the inspected MySQL ports; datadir UNKNOWN |

`Get-Process` verified both image paths and timestamps. `netstat -ano -p tcp` mapped only PID 21908 to TCP 127.0.0.1:3307; no listener was found on ports 3308 or 33060. `Get-CimInstance Win32_Process` and its owner query both returned `Access denied`; `tasklist` process-detail access had also failed earlier. Thus the second entry is a distinct OS process using the MySQL executable, but its parentage, command line, owner, role, and datadir are UNKNOWN. Matching image and timestamp do not prove it is a child or a legitimate helper; absent a listener does not prove it is unrelated or idle. Resolve this before any shutdown or file operation. Do not infer its role from the PID or terminate it.

Safe diagnostic command for an appropriately authorized read-only environment, with credential-like command-line values redacted before display (not executed successfully here):

```powershell
Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" | ForEach-Object {
  $line = [string]$_.CommandLine
  $line = $line -replace '(?i)(password|passwd|pwd|secret|token)(\s*=\s*|\s+)[^\s"'']+', '$1$2<redacted>'
  [pscustomobject]@{ PID=$_.ProcessId; ParentPID=$_.ParentProcessId; Path=$_.ExecutablePath; CommandLine=$line; StartTime=$_.CreationDate }
}
```

If that remains access-denied without elevating privileges, the process relationship remains unresolved and this is a hard recovery-test blocker.

### Administrative authentication and protocol

The three option files specify `[client] protocol=TCP`, `host=127.0.0.1`, and `port=3307`; the configured users are respectively `root`, `assab_s1_local`, and `assab_s1_migrator`. Password values were not displayed. A new read-only query using the existing root option file returned `ERROR 1045 (28000): Access denied for user 'root'@'127.0.0.1' (using password: YES)`. The migrator connection succeeded and verified `CURRENT_USER()=assab_s1_migrator@127.0.0.1`, server version 8.4.11, bind 127.0.0.1, port 3307, datadir above, and selected schema `assab_s1_local`. `SHOW GRANTS` returned only `USAGE ON *.*` and `ALL PRIVILEGES ON assab_s1_local.*`; this does not include global `SHUTDOWN`.

The migrator's read-only attempt to inspect `mysql.user` failed with error 1142, as expected from schema-scoped grants. Consequently, the actual root grant rows and authentication plugin for `root@localhost` and any `root@127.0.0.1` account remain UNKNOWN. Only the configured TCP route to `root@127.0.0.1` is verified to fail. The earlier TCP `localhost` attempt also resolved to loopback and failed; it does not establish whether a distinct local IPC authentication route would work. Server variables previously showed `named_pipe=OFF` and `shared_memory=OFF`, so no such route is presently available. No alternate passwords, reset mechanisms, account changes, or privilege changes were attempted.

The only documented SQL shutdown route is `SHUTDOWN`, requiring global `SHUTDOWN`; `mysqladmin shutdown` uses the same administrative capability. Therefore the currently authorized migrator cannot stop the instance, and the configured root TCP route cannot authenticate. **Graceful shutdown is not currently feasible through a verified authorized connection.** A controlled foreground start applies to a future disposable recovery instance, not to this already-running server. Process termination is not an acceptable substitute.

The exact read-only checks executed (password remains in the protected option file and is not printed) were:

```powershell
$root = 'D:\claude\AssabERP\.tools\s1-01'
$mysql = Join-Path $root 'mysql-8.4.11-winx64\bin\mysql.exe'
& $mysql "--defaults-extra-file=$root\mysql-root.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --batch --skip-column-names -e "SELECT CURRENT_USER(), USER(), @@port, @@datadir;"
& $mysql "--defaults-extra-file=$root\mysql-migration.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --batch --skip-column-names -e "SELECT User, Host, plugin FROM mysql.user WHERE User='root';"
```

The second command is not a shutdown path; it failed with 1142 and disclosed no account records.

### ACL findings and minimum correction proposal

`icacls` showed the following:

- `mysql-data`, its sampled child directories, the `.tools\s1-01` parent, and `mysql-s1-01.ini` inherit `NT AUTHORITY\Authenticated Users:(M)` (including child inheritance). The datadir also grants `BUILTIN\Users` read/execute. The portable binary directory has the same broad inherited Modify pattern.
- `mysql-root.cnf`, `mysql-runtime.cnf`, and `mysql-migration.cnf` each have only `<previous-sandbox-identity>:(F)` in the observed ACL output. No credential contents were read into output.
- The proposed `backups` destination does not exist. It would inherit the broad `.tools\s1-01` ACL unless an explicit protected ACL is applied to a newly created backup root.

This is a meaningful integrity and availability risk on a machine where other authenticated user accounts or processes can run: they receive Modify on the live database files and MySQL option file. `BUILTIN\Users` Read/Execute on the datadir can also expose database files to local users. The actual set of principals able to log on to this host and the runtime process token are UNKNOWN, so the practical exposure beyond this local sandbox cannot be quantified. The credential files themselves are appropriately restricted in the ACL output observed. A backup made under the current parent would inherit broad access unless corrected on the new destination.

Minimum proposed corrections, **not executed and requiring separate approval**:

1. Do not change live datadir ACLs while MySQL is running or until PID 18784/21908 ownership and runtime identity are understood. Then, in an approved maintenance window, remove inherited `Authenticated Users` Modify and broad `BUILTIN\Users` read from the datadir subtree while retaining explicit access for the verified MySQL runtime identity, current workspace identity, `SYSTEM`, and Administrators. Preserve only principals required by the running server and recovery operator.
2. Remove `Authenticated Users` Modify from the option file and its parent inheritance path, retaining only required administrative/operator access. The option file is not a password file, but modifying it can redirect the server or alter network exposure.
3. Create each future backup/recovery directory only after an absent-path check, then disable inheritance on that new directory and grant Full Control only to the current recovery identity, SYSTEM, and Administrators. Do not modify the `.tools\s1-01` parent merely to secure one backup.
4. Keep the three credential option files' current explicit restriction; verify each file after any future copy rather than inheriting the backup parent's ACL.

Illustrative ACL commands for a **newly created backup root only**, not for the live datadir and not executed:

```powershell
$backupRoot = 'D:\claude\AssabERP\.tools\s1-01\backups\mysql-cold-REPLACE-WITH-UNIQUE-TIMESTAMP'
if (Test-Path -LiteralPath $backupRoot) { throw 'Destination exists; do not overwrite' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
$operator = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls.exe $backupRoot /inheritance:r
icacls.exe $backupRoot /grant:r "$($operator):(OI)(CI)(F)" 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)'
icacls.exe $backupRoot
```

Because ACL inheritance/ownership behavior and the MySQL process token have not been resolved, these illustrative commands must be reviewed and separately approved before use. If they fail, do not broaden permissions or request elevation automatically.

## Updated recommendation

**NO-GO remains for shutdown, cold backup, and restore.** The direct root TCP authentication failure and schema-only migrator grants rule out the existing verified shutdown route. The unresolved second server process and broad inherited filesystem ACLs add blockers. Do not alter ACLs on active files, enable a different authentication protocol, change MySQL accounts, or stop either PID as part of this read-only preparation. Next safe step is to resolve process metadata and identify the server's actual runtime identity through a permitted read-only diagnostic; then request separate approval for a specific minimal ACL change and supported graceful shutdown path. Any later recovery-test approval is distinct from migration authorization.

## Process-tree and startup follow-up (2026-10-07)

### Process relationship: tree verified, process role partly unknown

A non-elevated read-only Toolhelp process snapshot returned:

| PID | Parent PID | Image | Command line | Evidence |
|---|---:|---|---|---|
| 18784 | 5852 | Portable `mysqld.exe` | `"D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqld.exe" --defaults-file=D:\claude\AssabERP\.tools\s1-01\mysql-s1-01.ini --init-file=D:\claude\AssabERP\.tools\s1-01\mysql-init-s1-01.sql` | Process parent is 5852; parent 5852 had exited when checked. |
| 21908 | 18784 | Same portable `mysqld.exe` | `D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqld.exe --defaults-file=D:\claude\AssabERP\.tools\s1-01\mysql-s1-01.ini --init-file=D:\claude\AssabERP\.tools\s1-01\mysql-init-s1-01.sql` | Direct child of 18784; owns the 127.0.0.1:3307 listener; MySQL error log says it started as process 21908 and became ready. |

This verifies PID 21908 is in PID 18784's process tree, with the same executable and startup arguments. It is not evidence of an independent unrelated MySQL launch. The exact functional role of PID 18784 (intentional launcher/supervisor behavior versus an unexpected extra server process) remains UNKNOWN; we found no MySQL documentation establishing that this particular parent-child pair is expected. PID 5852 is gone, so the original launcher/console cannot be controlled through that process. The command-line query used `NtQueryInformationProcess` from the current non-elevated session and displayed only the two redacted-safe command lines above; no password was present in either.

The command line includes `--init-file=D:\claude\AssabERP\.tools\s1-01\mysql-init-s1-01.sql`, but that file is now absent. Its contents and startup SQL effects cannot be audited from the current workspace. Do not recreate or rerun it. The error log confirms successful startup of PID 21908 but does not record the SQL contents. This leaves the historical account-provisioning actions UNKNOWN.

The repository's prior Stage C procedure describes `Start-Process ... -WindowStyle Hidden -PassThru`; this documents a background/hidden launch plan. The live command lines do not contain `--console`. The separate verification document's `mysqld --console` and Ctrl+C instructions are a proposed foreground procedure, not evidence that the current server was started that way. The exact Stage C PowerShell invocation/history was inaccessible (PowerShell history read denied), so the current server's console attachment and its launcher's exact command remain UNKNOWN. No existing foreground console was located or verified.

### Foreground console shutdown assessment

Do not send Ctrl+C to the current MySQL processes. There is no verified existing server console to receive it, and the live process did not include `--console`. MySQL documents `--console` as selecting console output for the error log; its Windows multiple-instance instructions show servers starting in the foreground and shutting down with `mysqladmin shutdown`, not Ctrl+C. Therefore the repository's proposed Ctrl+C behavior is not sufficiently source-verified to use for this recovery. The safer supported method remains an authenticated MySQL administrative shutdown, which requires `SHUTDOWN`; this account/path has not been established. MySQL's documented Windows command-line procedure starts each server in a foreground terminal and uses `mysqladmin ... shutdown` to stop it. See [Windows multiple-instance instructions](https://dev.mysql.com/doc/refman/8.4/en/multiple-windows-command-line-servers.html), [server `--console` option](https://dev.mysql.com/doc/refman/8.4/en/server-options.html), and [shutdown privilege requirement](https://dev.mysql.com/doc/refman/8.4/en/shutdown.html).

### Root host matching explanation

The root option file explicitly requests TCP to `127.0.0.1:3307`. The server reports `skip_name_resolve=ON`; the MySQL manual states that with this setting, host matching uses IP addresses and a TCP request to 127.0.0.1 does not fall back to the `localhost` account. This provides a verified reason that `root@localhost` and the configured `root@127.0.0.1` route can differ. The actual root grant rows are still unreadable to the migrator, so we cannot determine whether a `root@127.0.0.1` row exists or whether the stored password also mismatches. `named_pipe=OFF` and `shared_memory=OFF`, so switching the client to PIPE or MEMORY is not an available path with the current server configuration. No credentials or privileges were changed. See [MySQL `skip_name_resolve`](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html) and [connection transport interpretation](https://dev.mysql.com/doc/refman/8.4/en/transport-protocols.html).

### Protected backup destination

The minimum workspace-local correction is to create a unique backup root, remove inherited ACLs on that new directory only, and grant Full Control to the current recovery identity, SYSTEM, and local Administrators. Do not change `.tools\s1-01`, the live data directory, or Windows-wide ACLs. The following is proposed only; it creates a directory and changes that directory's ACL, so it requires separate approval. Run only when the timestamped destination is confirmed absent:

```powershell
$backupRoot = 'D:\claude\AssabERP\.tools\s1-01\backups\mysql-cold-REPLACE-WITH-UNIQUE-TIMESTAMP'
if (Test-Path -LiteralPath $backupRoot) { throw 'Destination exists; do not overwrite' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
$operator = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls.exe $backupRoot /inheritance:r
icacls.exe $backupRoot /grant:r "$($operator):(OI)(CI)(F)" 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)'
icacls.exe $backupRoot
```

Verify the resulting DACL before copying; it must not contain `Authenticated Users`, `BUILTIN\Users`, or the inherited sandbox-wide Modify ACEs. Because `robocopy /COPY:DAT` does not copy security descriptors, files created beneath this protected destination inherit its restricted ACL. Preserve a pre-copy `icacls` listing as evidence. Do not attempt to apply this correction to the active datadir.

### Exact proposed recovery-test sequence (not executed)

The full test remains blocked until a supported shutdown account/path is verified and separately approved. Root credentials/grants must not be modified as part of this plan. The live config currently has no verified shutdown route. Once an authorized shutdown mechanism is independently established and approved, the sequence is:

1. Recheck that only the intended portable instance is on 127.0.0.1:3307, confirm the `assab_s1_local` schema is empty, record server identity, current process tree, exact command lines, and source datadir size. Confirm both MySQL PIDs exit through the approved graceful shutdown. Verify no TCP 3307 listener and no process referencing this instance remains. If either process remains, do not copy.
2. Create the unique, protected backup root using the ACL commands above. Confirm it is a sibling below `.tools\s1-01`, distinct from the source, and has enough free space. Copy the full datadir and current option file without mirror/purge behavior; generate source/backup SHA-256 manifests and compare by relative path. Stop on any mismatch or ACL broadening.
3. Restore the verified backup to a new, absent sibling `D:\claude\AssabERP\.tools\s1-01\mysql-recovery-data-<timestamp>`; never restore over `mysql-data`. Copy the backup option file to a new recovery config and change only the recovery config's `datadir`, `port=3308`, and log path. Keep `bind-address=127.0.0.1`, `mysqlx=0`, `local-infile=0`, and `secure-file-priv=NULL`. Check the config's full resolved path before launch and verify port 3308 is unused.
4. Start only the recovery copy in a visible foreground PowerShell console, using the same 8.4.11 binary:

```powershell
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$recoveryIni = 'D:\claude\AssabERP\.tools\s1-01\mysql-recovery-REPLACE-WITH-UNIQUE-TIMESTAMP.ini'
$recoveryData = 'D:\claude\AssabERP\.tools\s1-01\mysql-recovery-data-REPLACE-WITH-UNIQUE-TIMESTAMP'
if ([IO.Path]::GetFullPath($recoveryData).TrimEnd('\') -eq [IO.Path]::GetFullPath((Join-Path $toolRoot 'mysql-data')).TrimEnd('\')) { throw 'Refuse to start against the original datadir' }
if (-not (Test-Path -LiteralPath $recoveryData -PathType Container)) { throw 'Verified recovery copy is absent' }
if (-not (Test-Path -LiteralPath $recoveryIni -PathType Leaf)) { throw 'Recovery config is absent' }
$dataLine = Get-Content -LiteralPath $recoveryIni | Where-Object { $_ -match '^\s*datadir\s*=' } | Select-Object -First 1
$configuredData = (($dataLine -replace '^\s*datadir\s*=\s*','').Trim().Replace('/','\')).TrimEnd('\')
if ([IO.Path]::GetFullPath($configuredData).TrimEnd('\') -ne [IO.Path]::GetFullPath($recoveryData).TrimEnd('\')) { throw 'Recovery config does not point exclusively to the recovery copy' }
& (Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqld.exe') "--defaults-file=$recoveryIni" --console
```

5. From another local shell, use the existing private migrator option file with explicit `--host=127.0.0.1 --port=3308` and read-only identity/schema queries. Confirm `VERSION()=8.4.11`, `@@port=3308`, `@@bind_address=127.0.0.1`, `@@datadir` equals the recovery copy, and `DATABASE()=assab_s1_local`; compare schema inventory to the saved pre-backup inventory. Do not run migrations, seeds, fixtures, or tests.
6. Gracefully shut down the recovery copy using the same verified administrative channel, scoped to port 3308. Confirm listener and process exit. The root issue means this step is presently unverified; do not fall back to Ctrl+C, `Stop-Process`, or `taskkill`. If no supported shutdown channel is available for the clone, do not start it.
7. Preserve the original datadir and backup. Keep failed recovery output for diagnosis; do not delete or overwrite any directory until a separate cleanup approval. Report matching hashes, ACLs, identity query, and clean shutdown evidence.

Relevant command forms, to be finalized with the approved shutdown identity and exact timestamped paths before execution:

```powershell
# Graceful shutdown, ONLY after a verified credential/admin route and separate approval:
& 'D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqladmin.exe' '--defaults-extra-file=D:\claude\AssabERP\.tools\s1-01\mysql-root.cnf' --protocol=TCP --host=127.0.0.1 --port=3307 shutdown

# Cold copy, ONLY after graceful stop and unique absent-path checks:
robocopy.exe 'D:\claude\AssabERP\.tools\s1-01\mysql-data' 'D:\claude\AssabERP\.tools\s1-01\backups\mysql-cold-REPLACE-WITH-UNIQUE-TIMESTAMP\mysql-data' /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /XJ
if ($LASTEXITCODE -ge 8) { throw "Cold copy failed: $LASTEXITCODE" }
```

These command forms are proposals only. The shutdown command is known to fail using the current `mysql-root.cnf`; do not execute it until the approved administrative authentication issue is resolved. The restore instance has the same copied MySQL accounts and therefore also needs a verified graceful shutdown route before its startup is approved.

### Historical decision

The process-tree relationship is verified, but PID 18784's functional role and the deleted init-file's SQL are unknown. The existing server was not verified as foreground or attached to a console; use of Ctrl+C is not supported by the evidence. The likely account-host mismatch mechanism is understood (`skip_name_resolve=ON` plus TCP to 127.0.0.1), but actual root account rows and password correctness remain unknown. The protected backup-root ACL plan is concrete and workspace-local. **NO-GO remains for the recovery test** until a supported shutdown path is verified for both source and restored instances, exact Stage C init-file effects are understood or explicitly accepted, and separate approval is given for destination ACL creation, shutdown, backup, restore, and clone shutdown. Migration authorization remains separate.

## Administrative shutdown blocker review (2026-10-07)

### Existing accounts and local connection paths

Read-only connections using the existing runtime and migrator option files succeeded against `127.0.0.1:3307`. `SHOW GRANTS` verified:

- `assab_s1_local@127.0.0.1`: `SELECT, INSERT, UPDATE, DELETE` on `assab_s1_local.*` only.
- `assab_s1_migrator@127.0.0.1`: all privileges on `assab_s1_local.*` only, with `USAGE` globally.

Neither is granted a global administrative privilege. A read-only query of `information_schema.USER_PRIVILEGES` returned no visible rows for `SHUTDOWN`, `SUPER`, `SYSTEM_USER`, or `CONNECTION_ADMIN`. This does not prove no hidden account has those grants; the migrator cannot inspect `mysql.user`. There is **no verified authorized account** available for graceful shutdown. The configured root option file still requests `TCP` to `127.0.0.1:3307` and its login fails with 1045. `named_pipe=OFF`, `shared_memory=OFF`; `Get-Service` found no MySQL service. No interactive console is attached/verified. The missing historical init SQL prevents confirmation of any account it may have set up.

Root mismatch explanation: MySQL's documented account matching with `skip_name_resolve=ON` uses IP host values; the configured TCP client targets `127.0.0.1`, so `root@localhost` is not a fallback for this route. The actual root rows and whether a `root@127.0.0.1` account exists remain UNKNOWN. A supplied, already-known password for a matching `root@127.0.0.1` account could be tested interactively, but no password should be guessed and the stored option-file password is known to fail. Changing this live instance to use a named pipe would require a restart/config change and still requires a safe way to stop it first.

### Comparison and recommendation

An authorized administrative connection is the lowest-impact path because it preserves the current server instance and its local account state. No working admin credential/transport is presently available, so it cannot be used today.

Recreating from scratch is simpler and safer than attempting to repair unknown local root/account state **for the database contents**, because the database has no application tables, fixtures, or business data. It does not solve the immediate shutdown of the current process: port 3307 remains occupied and the original process would continue running. Do not overwrite the current datadir or start a second instance on port 3307. A new isolated datadir on port 3308 can be prepared later, but it would be a separate instance, not a shutdown method for this one.

Recommended approach: **NO-GO now. Do not attempt in-place account recovery or process termination.** First obtain a valid, already-authorized local administrative route for the current server (if the owner can provide credentials for an existing matching admin account), or separately approve another supported administrative recovery path. If no such credential exists and the user accepts discarding only the empty local DB state, prefer a fresh instance in a new protected datadir after the current instance has been shut down safely. Configure that fresh instance from the outset with a restricted named pipe and use `root@localhost` over `PIPE` for administration; retain loopback-only TCP for application connections. The current Windows identity is a member of the existing `<historical-sandbox-group>` group, which could be named as the MySQL pipe full-access group without changing Windows group membership. Verify that group exposure is acceptable before using it.

### Exact proposed commands (not executed)

**Option 1: current server, only if a known valid credential for a matching admin account already exists.** This command prompts for the password; it has no password argument and deliberately bypasses the known-bad option-file value. Do not try guessed credentials. It will stop the current server if authentication and `SHUTDOWN` authorization succeed, so it requires separate explicit approval:

```powershell
$mysqlAdmin = 'D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysqladmin.exe'
& $mysqlAdmin --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password shutdown
if ($LASTEXITCODE -ne 0) { throw "Graceful shutdown was denied or failed: $LASTEXITCODE" }
netstat.exe -ano -p tcp | Select-String ':3307\s'
Get-Process -Name mysqld -ErrorAction SilentlyContinue | Select-Object Id,Path,StartTime
```

**Option 2: fresh replacement, only after the old server has been safely shut down and a separate initialization approval is given.** Keep the old directory untouched. Use a unique new root and an absent path; never point this at `mysql-data`:

```powershell
$ErrorActionPreference = 'Stop'
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$freshRoot = Join-Path $toolRoot "mysql-fresh-$stamp"
$freshData = Join-Path $freshRoot 'data'
$initIni = Join-Path $freshRoot 'initialize.ini'
$runIni = Join-Path $freshRoot 'my.ini'
$mysqld = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqld.exe'
if (Test-Path -LiteralPath $freshRoot) { throw 'Fresh target exists; do not overwrite' }
New-Item -ItemType Directory -Path $freshRoot | Out-Null
$operator = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls.exe $freshRoot /inheritance:r
icacls.exe $freshRoot /grant:r "$($operator):(OI)(CI)(F)" 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)'
New-Item -ItemType Directory -Path $freshData | Out-Null
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($freshData.Replace('\','/'))
"@ | Set-Content -LiteralPath $initIni -Encoding ascii
& $mysqld "--defaults-file=$initIni" --initialize *> (Join-Path $freshRoot 'initialize-output.log')
if ($LASTEXITCODE -ne 0) { throw 'MySQL initialization failed; preserve the new directory for review' }
```

The initialization output can contain a temporary root password; the proposed ACL protects `initialize-output.log`. Do not display or copy that file into chat or a terminal transcript. After successful initialization, write a separate runtime `my.ini` under `$freshRoot` with the same `basedir` and `datadir`, `bind-address=127.0.0.1`, `port=3307` only after confirming the old listener is gone, `skip-name-resolve=ON`, `mysqlx=0`, `local-infile=0`, `secure-file-priv=NULL`, `named_pipe=ON`, `socket=AssabS1Local`, `named_pipe_full_access_group=<historical-sandbox-group>`, and an error log under `$freshData`. Do not include `init-file`. Start it in a visible console with:

```powershell
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($freshData.Replace('\','/'))
port=3307
bind-address=127.0.0.1
skip-name-resolve=ON
mysqlx=0
local-infile=0
secure-file-priv=NULL
named_pipe=ON
socket=AssabS1Local
named_pipe_full_access_group=<historical-sandbox-group>
log-error=$($freshData.Replace('\','/'))/mysql-error.log
"@ | Set-Content -LiteralPath $runIni -Encoding ascii
if (netstat.exe -ano -p tcp | Select-String ':3307\s+.*LISTENING') { throw 'Old server still owns port 3307; do not start replacement there' }
& $mysqld "--defaults-file=$runIni" --console
```

After privately changing the fresh instance's expired temporary root password, use the named pipe for administration (again, a command that stops the server and requires separate approval):

```powershell
$mysqlAdmin = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqladmin.exe'
& $mysqlAdmin --no-defaults --no-login-paths --protocol=PIPE --socket=AssabS1Local --user=root --password shutdown
if ($LASTEXITCODE -ne 0) { throw "Fresh instance graceful shutdown failed: $LASTEXITCODE" }
```

MySQL documents `--initialize` as creating a fresh `root@localhost` with a generated, expired password; Windows named-pipe transport is supported when enabled, and `named_pipe_full_access_group` can restrict full pipe access to a local Windows group. See [initialization and root account behavior](https://dev.mysql.com/doc/refman/8.4/en/data-directory-initialization.html), [named-pipe system variables](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html), and [Windows connection protocols](https://dev.mysql.com/doc/refman/8.4/en/transport-protocols.html).

### Impact, verification, and rollback

No application or financial data would be lost by recreating the database according to the latest read-only inventory, but the existing MySQL system tables, current local accounts, grants, generated certificates, and any non-application state would be discarded from the *replacement*. The existing original datadir must remain preserved and untouched until the user separately approves a cleanup plan. The current instance remains live throughout this assessment.

For either approved option, verify MySQL version, configured executable, command line, datadir, bind address, port, and listener PID before action. After graceful shutdown, verify 3307 has no listener and both PIDs 18784 and 21908 have exited; do not copy or reinitialize if either remains. For a fresh instance, verify the exact new datadir, loopback bind, named-pipe connection as the expected root host, and successful `mysqladmin shutdown` over that pipe. Roll back by leaving the original `mysql-data` and its option file untouched; never restore or initialize over them. Preserve any failed new datadir/output for review; cleanup requires separate approval.

**Current GO/NO-GO: NO-GO for stopping, recreating, backing up, or restoring.** The known root route fails, no other verified account has global `SHUTDOWN`, and recreation cannot free port 3307 while the current processes remain. Approval alone cannot make a currently unavailable credential or unsupported transport work. A viable next input is a known valid credential for an already-existing account matching the TCP host and granted `SHUTDOWN`, or separate authorization to design a non-destructive path that first obtains administrative control. Any replacement initialization must also be separately authorized. No current authorization here includes those actions.

## Alternative: isolated MySQL instance on 127.0.0.1:3308 (proposed only)

### A. Layout and configuration

Preflight on 2026-10-07 confirmed the existing MySQL 8.4.11 portable binary, the 3307 listener PID 21908, no listener on 3308, and no existing `mysql-3308` target paths. The existing datadir contains 184 files totaling 209,046,128 bytes (about 199.4 MiB).

Proposed independent tree:

```text
D:\claude\AssabERP\.tools\s1-01\mysql-3308\
  config\initialize.ini                 # basedir + new datadir only
  config\mysql-3308.ini                 # runtime options
  credentials\                           # empty until a later approval
  data\                                  # entirely new MySQL data directory
  logs\initialize-output.log             # private; can contain generated root password
  logs\mysql-error.log
  run\mysqld.pid
  backups\cold-<timestamp>\             # full cold data copy + config + manifests
  restore\<timestamp>\data\             # separate disposable restored copy
  restore\<timestamp>\config\restore.ini
  restore\<timestamp>\logs\mysql-error.log
  restore\<timestamp>\run\mysqld.pid
```

Runtime configuration: same verified binary `D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64`; new datadir; TCP bind `127.0.0.1:3308`; `skip-name-resolve=ON`; `mysqlx=0`; `local-infile=0`; `secure-file-priv=NULL`; unique named pipe `AssabS1Test3308`; named-pipe full-access group `<historical-sandbox-group>`; error log under the new `logs`; PID file under the new `run`. The restore clone uses port 3309, a distinct pipe name `AssabS1Restore3309`, and its own data, log, and PID paths. MySQL documents that each instance needs a different datadir, TCP port, named pipe name when pipes are enabled, and PID file. [Running multiple instances](https://dev.mysql.com/doc/refman/8.4/en/multiple-servers.html)

The current operator is a member of `<historical-sandbox-group>`; no Windows group membership changes are proposed. MySQL authentication still applies to pipe connections. This group setting means group members can access the pipe transport, so that local group scope should be accepted before enabling it. The new DB's initial `root@localhost` account can be reached over the named pipe; `mysqladmin` can then perform graceful shutdown over that same pipe with a private password prompt. MySQL supports Windows named pipes when enabled and lets the server restrict full access to a named Windows group. [Named-pipe configuration](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html) · [Connection protocols](https://dev.mysql.com/doc/refman/8.4/en/transport-protocols.html)

The separate `credentials` directory remains empty in this proposal. Use interactive password prompts for first setup and shutdown. If private client option files are later approved, place separate admin/runtime/migration files there and apply the instance-root ACL before writing any secrets. Do not reuse the old instance's credentials.

### B. Isolation and port preflight

Read-only preflight results: D: is NTFS, total 104,856,547,328 bytes, free 17,437,016,064 bytes (about 16.24 GiB); 3307 is still listening on 127.0.0.1 under PID 21908; no 3308 listener was found; all proposed `mysql-3308` paths are absent. `Get-Volume` was access-denied, so free space was independently read through .NET `DriveInfo`. Before any future initialization/start, repeat the listener/path checks and require the 3307 listener to remain unchanged and 3308 to be unused.

Keep the new root entirely under `.tools\s1-01`; disable inherited ACLs on that newly created root only, then grant inheritable Full Control to the current operator, SYSTEM, and Administrators. Children (including data, logs, credentials, backup, and restore) inherit this restricted ACL. Do not change the broader `.tools\s1-01` ACL or the original instance's ACL. The data and new options must point only within `mysql-3308`; restore options must point only within `mysql-3308\restore\...`.

### C. Proposed initialization and administration commands (not executed)

All commands below are examples for a future separately approved operation. They create files/directories and initialize/start a database; none were run. Replace the timestamp once, verify it is unique, and keep it fixed for each procedure.

```powershell
$ErrorActionPreference = 'Stop'
$toolRoot = 'D:\claude\AssabERP\.tools\s1-01'
$instanceRoot = Join-Path $toolRoot 'mysql-3308'
$dataDir = Join-Path $instanceRoot 'data'
$configDir = Join-Path $instanceRoot 'config'
$credentialDir = Join-Path $instanceRoot 'credentials'
$logDir = Join-Path $instanceRoot 'logs'
$runDir = Join-Path $instanceRoot 'run'
$backupDir = Join-Path $instanceRoot 'backups'
$restoreDir = Join-Path $instanceRoot 'restore'
$mysqld = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqld.exe'
$mysql = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysql.exe'
$mysqlAdmin = Join-Path $toolRoot 'mysql-8.4.11-winx64\bin\mysqladmin.exe'

if (Test-Path -LiteralPath $instanceRoot) { throw 'Target exists; do not overwrite' }
$drive = [System.IO.DriveInfo]::new('D:\')
if (-not $drive.IsReady -or $drive.AvailableFreeSpace -lt 2GB) { throw 'Require at least 2 GiB free on D:' }
if (netstat.exe -ano -p tcp | Select-String ':3308\s+.*LISTENING') { throw 'Port 3308 is already in use' }
$old3307 = netstat.exe -ano -p tcp | Select-String '127\.0\.0\.1:3307\s+.*LISTENING\s+21908$'
if (-not $old3307) { throw 'Existing 3307 listener differs from the verified instance; stop and review' }

New-Item -ItemType Directory -Path $instanceRoot | Out-Null
$operator = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls.exe $instanceRoot /inheritance:r
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict new instance root ACL' }
icacls.exe $instanceRoot /grant:r "$($operator):(OI)(CI)(F)" 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)'
if ($LASTEXITCODE -ne 0) { throw 'Could not grant restricted instance root ACL' }
@($configDir,$credentialDir,$logDir,$runDir,$backupDir,$restoreDir,$dataDir) |
  ForEach-Object { New-Item -ItemType Directory -Path $_ | Out-Null }
icacls.exe $instanceRoot /T /C

$initIni = Join-Path $configDir 'initialize.ini'
$runtimeIni = Join-Path $configDir 'mysql-3308.ini'
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($dataDir.Replace('\','/'))
"@ | Set-Content -LiteralPath $initIni -Encoding ascii
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($dataDir.Replace('\','/'))
port=3308
bind-address=127.0.0.1
skip-name-resolve=ON
mysqlx=0
local-infile=0
secure-file-priv=NULL
named_pipe=ON
socket=AssabS1Test3308
named_pipe_full_access_group=<historical-sandbox-group>
log-error=$($logDir.Replace('\','/'))/mysql-error.log
pid-file=$($runDir.Replace('\','/'))/mysqld.pid
"@ | Set-Content -LiteralPath $runtimeIni -Encoding ascii

# Initialization creates root@localhost with a random expired password.
# Capture all output only beneath the ACL-restricted instance root; never print it.
& $mysqld "--defaults-file=$initIni" --initialize *> (Join-Path $logDir 'initialize-output.log')
if ($LASTEXITCODE -ne 0) { throw 'Initialization failed; preserve the new directory and review privately' }
```

MySQL recommends initialization with only location options such as `basedir` and `datadir`; the runtime pipe, port, logging, and PID settings belong in the separate runtime file. The generated temporary root password is written in initialization diagnostics, so all initialization output and the datadir must remain under the restricted ACL. [Initialization procedure](https://dev.mysql.com/doc/refman/8.4/en/data-directory-initialization.html)

Start the new instance in a foreground PowerShell terminal using its runtime options (without `--console`, so configured `log-error` remains the file destination). Keep this terminal open; use a second local terminal for client commands:

```powershell
& $mysqld "--defaults-file=$runtimeIni"
```

In the second terminal, authenticate as the initial `root@localhost` over the restricted named pipe, privately enter the generated temporary password at the prompt, and set a fresh high-entropy password interactively. Do not put a password in command arguments or output:

```powershell
& $mysql --no-defaults --no-login-paths --connect-expired-password --protocol=PIPE --socket=AssabS1Test3308 --user=root --password
```

In that interactive client, change the *new instance's* expired root password using a privately generated secret; do not enter that secret into a logged agent command. After setup, verify read-only identity values and shut down gracefully using the pipe. `mysqladmin` will prompt for the password; no password is supplied on the command line:

```powershell
& $mysql --no-defaults --no-login-paths --protocol=PIPE --socket=AssabS1Test3308 --user=root --password --execute="SELECT VERSION(), @@port, @@bind_address, @@datadir, @@named_pipe, @@named_pipe_full_access_group;"
& $mysqlAdmin --no-defaults --no-login-paths --protocol=PIPE --socket=AssabS1Test3308 --user=root --password shutdown
if ($LASTEXITCODE -ne 0) { throw 'Graceful shutdown failed; do not copy data' }
netstat.exe -ano -p tcp | Select-String ':3308\s+.*LISTENING'
Get-Process -Name mysqld -ErrorAction SilentlyContinue | Select-Object Id,Path,StartTime
```

Do not copy until 3308 has no listener and the new instance's process tree is fully exited. Confirm separately that the existing `127.0.0.1:3307` listener still belongs to PID 21908; do not touch that process or its datadir.

### D. Cold backup and isolated restore test (not executed)

Reserve at least 2 GiB free on D: before the test. The existing datadir is about 199.4 MiB; one new initialized instance plus a full cold backup and a separate restore copy is approximately 600 MiB before logs, filesystem overhead, and growth. Two GiB is a conservative minimum; current free space is 16.24 GiB.

After the 3308 instance has been gracefully shut down, use a fresh timestamp and absent paths. Copy the complete `data` directory to a new backup directory using `robocopy` without `/MIR` or `/PURGE`, copy the runtime config, and hash every file by relative path. Keep manifests outside the datadir. Example:

```powershell
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $backupDir "cold-$stamp"
$backupData = Join-Path $backupRoot 'data'
if (Test-Path -LiteralPath $backupRoot) { throw 'Backup exists; do not overwrite' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
robocopy.exe $dataDir $backupData /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /XJ
if ($LASTEXITCODE -ge 8) { throw "Cold copy failed: $LASTEXITCODE" }
Copy-Item -LiteralPath $runtimeIni -Destination (Join-Path $backupRoot 'mysql-3308.ini')
Get-ChildItem -LiteralPath $dataDir -Recurse -File | ForEach-Object {
  [pscustomobject]@{ Path=[IO.Path]::GetRelativePath($dataDir,$_.FullName); SHA256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
} | Export-Csv -NoTypeInformation -Encoding utf8 -LiteralPath (Join-Path $backupRoot 'source-hashes.csv')
Get-ChildItem -LiteralPath $backupData -Recurse -File | ForEach-Object {
  [pscustomobject]@{ Path=[IO.Path]::GetRelativePath($backupData,$_.FullName); SHA256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
} | Export-Csv -NoTypeInformation -Encoding utf8 -LiteralPath (Join-Path $backupRoot 'backup-hashes.csv')
$hashDiff = Compare-Object (Import-Csv (Join-Path $backupRoot 'source-hashes.csv')) (Import-Csv (Join-Path $backupRoot 'backup-hashes.csv')) -Property Path,SHA256
if ($hashDiff) { throw 'Source and cold-backup manifests differ; do not restore from this copy' }
```

Compare source and backup manifests exactly. For restore, use a unique timestamp, copy `backupData` only to a newly created, absent `restore\<timestamp>\data`, and verify hashes before startup. The exact command shape follows; all paths must be fresh and must not be the original `mysql-data` or 3308 `data`:

```powershell
$restoreRoot = Join-Path $restoreDir "restore-$stamp"
$restoreData = Join-Path $restoreRoot 'data'
$restoreConfigDir = Join-Path $restoreRoot 'config'
$restoreLogDir = Join-Path $restoreRoot 'logs'
$restoreRunDir = Join-Path $restoreRoot 'run'
$restoreIni = Join-Path $restoreConfigDir 'restore.ini'
if (Test-Path -LiteralPath $restoreRoot) { throw 'Restore target exists; do not overwrite' }
$originalData = [IO.Path]::GetFullPath((Join-Path $toolRoot 'mysql-data')).TrimEnd('\')
$newData = [IO.Path]::GetFullPath($dataDir).TrimEnd('\')
$coldData = [IO.Path]::GetFullPath($backupData).TrimEnd('\')
$newRestoreData = [IO.Path]::GetFullPath($restoreData).TrimEnd('\')
if ($newRestoreData -in @($originalData,$newData,$coldData)) { throw 'Restore target collides with original, active, or backup data' }
New-Item -ItemType Directory -Path $restoreRoot,$restoreConfigDir,$restoreLogDir,$restoreRunDir,$restoreData | Out-Null
robocopy.exe $backupData $restoreData /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /XJ
if ($LASTEXITCODE -ge 8) { throw "Restore copy failed: $LASTEXITCODE" }
Get-ChildItem -LiteralPath $restoreData -Recurse -File | ForEach-Object {
  [pscustomobject]@{ Path=[IO.Path]::GetRelativePath($restoreData,$_.FullName); SHA256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
} | Export-Csv -NoTypeInformation -Encoding utf8 -LiteralPath (Join-Path $restoreRoot 'restore-hashes.csv')
$hashDiff = Compare-Object (Import-Csv (Join-Path $backupRoot 'backup-hashes.csv')) (Import-Csv (Join-Path $restoreRoot 'restore-hashes.csv')) -Property Path,SHA256
if ($hashDiff) { throw 'Cold-backup and restored manifests differ; do not start restore copy' }
@"
[mysqld]
basedir=$($toolRoot.Replace('\','/'))/mysql-8.4.11-winx64
datadir=$($restoreData.Replace('\','/'))
port=3309
bind-address=127.0.0.1
skip-name-resolve=ON
mysqlx=0
local-infile=0
secure-file-priv=NULL
named_pipe=ON
socket=AssabS1Restore3309
named_pipe_full_access_group=<historical-sandbox-group>
log-error=$($restoreLogDir.Replace('\','/'))/mysql-error.log
pid-file=$($restoreRunDir.Replace('\','/'))/mysqld.pid
"@ | Set-Content -LiteralPath $restoreIni -Encoding ascii
if (netstat.exe -ano -p tcp | Select-String ':3309\s+.*LISTENING') { throw 'Port 3309 is already in use' }
& $mysqld "--defaults-file=$restoreIni"
```

From a second local terminal, verify identity over the restore pipe and then issue graceful shutdown. The restored datadir contains the same MySQL account state as the source, so it uses the new instance's privately set root secret:

```powershell
& $mysql --no-defaults --no-login-paths --protocol=PIPE --socket=AssabS1Restore3309 --user=root --password --execute="SELECT VERSION(), @@port, @@bind_address, @@datadir, CURRENT_USER(); SHOW DATABASES;"
& $mysqlAdmin --no-defaults --no-login-paths --protocol=PIPE --socket=AssabS1Restore3309 --user=root --password shutdown
if ($LASTEXITCODE -ne 0) { throw 'Restore instance graceful shutdown failed' }
netstat.exe -ano -p tcp | Select-String ':3309\s+.*LISTENING'
```

Compare `restore-hashes.csv` with `backup-hashes.csv` by relative path, verify restored `@@datadir` points only to `restoreData`, and verify the 3309 listener and restore process have exited before marking the test complete. Do not delete a failed restore without separate approval. MySQL describes a physical backup as a copy of the datadir and supports offline copying while the server is stopped. [Backup types](https://dev.mysql.com/doc/refman/8.4/en/backup-types.html)

### E. Adoption as S1-01 test database

Once the 3308 instance has passed its isolation and shutdown checks, it can become the Backend's local S1-01 database without touching the old server: update only the ignored local Backend `.env` to `DB_HOST=127.0.0.1`, `DB_PORT=3308`, `DB_DATABASE=assab_s1_local`, and the new instance's separate runtime username/password; verify the effective Laravel config and database identity. The Dashboard uses the Backend API and does not need direct DB credentials. Leave the old 3307 listener and datadir unchanged. No migrations are included in this adoption step; migration execution remains subject to a separate explicit approval and a migration safety GO.

### F. Risks and decision

Risks are contained by unique data/config/log/PID/pipe/port paths, a loopback-only bind, `mysqlx=0`, no Windows service, restricted instance-root ACLs, and refusing any existing target path. The `<historical-sandbox-group>` pipe group may include other sandbox users; they would gain transport access but still need valid MySQL authentication. Initialization diagnostics contain a temporary root password and must remain private. A restore test needs about three datadir footprints plus overhead. Startup and backup must be sequential: no cold copy while either instance is running.

Preflight supports a **GO proposal** for creating the separate 3308 instance: the binary is verified at 8.4.11; 3308 is free; all target paths are absent; the operator belongs to the planned local pipe group; and D: has 16.24 GiB free against a 2 GiB reserve. **Execution remains NO-GO until explicit approval.** Nothing was initialized, started, or created. The current 3307 server and data remain untouched. After separate approval for initialization/start, the password handling and named-pipe shutdown should be tested on the new instance before approving any backup/restore test or migrations.

## References

- MySQL 8.4, [Backup and Recovery Types](https://dev.mysql.com/doc/refman/8.4/en/backup-types.html): physical backup copies data files and may be performed offline; recovery copies files back to a location.
- MySQL 8.4, [Access Control, Stage 2](https://dev.mysql.com/doc/refman/8.4/en/request-access.html): administrative requests such as shutdown require global administrative privilege checks.
- MySQL 8.4, [SHUTDOWN Statement](https://dev.mysql.com/doc/refman/8.4/en/shutdown.html): `SHUTDOWN` requires the `SHUTDOWN` privilege.
- MySQL 8.4, [Starting the Server for the First Time on Windows](https://dev.mysql.com/doc/refman/8.4/en/windows-server-first-start.html): console startup behavior and use of `--console`.

</details>
