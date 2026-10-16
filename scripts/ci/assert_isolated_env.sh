#!/usr/bin/env bash
# ASSAB BRC v3.4 isolated regression CI -- Layer 1 isolation guard (shell).
#
# Fails the job BEFORE any application code or test runs unless the process
# environment proves this is a disposable GitHub-hosted runner configured for
# the in-memory SQLite test database, with no database endpoint, no Staging /
# Production marker and no non-CI application key.
#
# Only variable NAMES are ever printed -- never values.
#
# Local self-test: ASSAB_CI_LOCAL_SELFTEST=1 skips the "GitHub-hosted runner"
# assertion only. Every other assertion still applies.
set -euo pipefail

fail() {
  echo "::error title=ISOLATION GUARD FAILED::$*"
  echo "ISOLATION_GUARD=FAIL reason=$*"
  exit 1
}

# ---- 1. disposable, GitHub-hosted runner ------------------------------------
if [[ "${ASSAB_CI_LOCAL_SELFTEST:-}" != "1" ]]; then
  [[ "${GITHUB_ACTIONS:-}" == "true" ]] || fail "GITHUB_ACTIONS is not 'true'"
  [[ "${RUNNER_ENVIRONMENT:-}" == "github-hosted" ]] || fail "runner is not github-hosted (RUNNER_ENVIRONMENT='${RUNNER_ENVIRONMENT:-unset}')"
fi

# ---- 2. exact expected test configuration -----------------------------------
expect() {
  local name="$1" want="$2" got="${!1-__UNSET__}"
  if [[ "$got" != "$want" ]]; then
    if [[ "$name" == "APP_KEY" ]]; then
      fail "APP_KEY is not the CI-only key (value withheld)"
    fi
    fail "$name must be '$want' (got '${got}')"
  fi
}
expect APP_ENV testing
expect DB_CONNECTION sqlite
expect DB_DATABASE ':memory:'
expect CACHE_STORE array
expect SESSION_DRIVER array
expect QUEUE_CONNECTION sync
expect MAIL_MAILER array

# The key is the well-known CI-only key (base64 of the ASCII string
# 0123456789abcdef0123456789abcdef). Anything else is rejected so that a real
# application key can never reach a test process.
expect APP_KEY 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='

# ---- 3. no database endpoint / credential variables --------------------------
for v in DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_URL DB_SOCKET DATABASE_URL \
         MYSQL_HOST MYSQL_PWD MYSQL_DATABASE MYSQL_USER MYSQL_ROOT_PASSWORD \
         MARIADB_ROOT_PASSWORD PGHOST PGPASSWORD REDIS_HOST REDIS_URL; do
  if [[ -n "${!v-}" ]]; then
    fail "$v is set; the SQLite gate must not carry any database/cache endpoint variable"
  fi
done

# The PHPUnit bootstrap exports ASSAB_CI_ISOLATION_VERIFIED itself, after its
# own strict check, so that #[RunInSeparateProcess] children can be recognised
# (see scripts/ci/phpunit-isolation-bootstrap.php). It must never be pre-set by
# the job environment, otherwise the strict top-level check would be skipped.
if [[ -n "${ASSAB_CI_ISOLATION_VERIFIED+x}" ]]; then
  fail "ASSAB_CI_ISOLATION_VERIFIED is pre-set; it is reserved for the PHPUnit bootstrap"
fi

# ---- 4. no Staging / Production / Coolify markers (names and values) ---------
# Names: scan variable NAMES only.
while IFS= read -r name; do
  case "$name" in
    GITHUB_*|RUNNER_*|ACTIONS_*|ASSAB_CI_*|INPUT_*|CI|HOME|PATH|PWD|OLDPWD|SHLVL|LANG|USER|SHELL|_|COMPOSER_*|PHP_*|PHPRC|JAVA_*|ANDROID_*|AZURE_*|ACCEPT_EULA|DEBIAN_FRONTEND|ImageOS|ImageVersion|DOTNET_*|AGENT_*|BOOST_*|CONDA|EDGEDRIVER*|GECKOWEBDRIVER|CHROMEWEBDRIVER|GOROOT*|HOMEBREW_*|SELENIUM_*|SWIFT_*|STATS_*|VCPKG_*|GRADLE_*|PIPX_*|POWERSHELL_*|LEIN_*|XDG_*|INVOCATION_ID|JOURNAL_STREAM|SYSTEMD_*|MANPATH|LD_*|LESS*|LS_COLORS|TERM|TMPDIR) continue ;;
  esac
  if [[ "$name" =~ [Ss][Tt][Aa][Gg][Ii][Nn][Gg]|[Pp][Rr][Oo][Dd]|[Cc][Oo][Oo][Ll][Ii][Ff][Yy] ]]; then
    fail "environment variable name '$name' looks like a Staging/Production/Coolify marker"
  fi
done < <(compgen -e)

# Values: look for the real database name or Coolify URL in ANY value without
# printing it.
while IFS= read -r name; do
  val="${!name-}"
  if [[ "$val" =~ [Aa][Ss][Ss][Aa][Bb]_[Ss][Tt][Aa][Gg][Ii][Nn][Gg]|[Aa][Ss][Ss][Aa][Bb]_[Pp][Rr][Oo][Dd] ]]; then
    fail "value of environment variable '$name' references a Staging/Production database name"
  fi
done < <(compgen -e)

# ---- 5. no secret references in the task workflow/action ---------------------
root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
while IFS= read -r f; do
  [[ -f "$f" ]] || continue
  if grep -nE '\$\{\{[[:space:]]*(secrets|vars)\.' "$f" >/dev/null; then
    fail "$f references secrets./vars. contexts; this gate must run without secrets or repository variables"
  fi
done < <(printf '%s\n' \
  "$root/.github/workflows/assab-brc34-regression.yml" \
  "$root/.github/actions/brc34-sqlite-setup/action.yml")

echo "ISOLATION_GUARD=PASS driver=${DB_CONNECTION} database=${DB_DATABASE} env=${APP_ENV} runner=${RUNNER_ENVIRONMENT:-local-selftest}"
