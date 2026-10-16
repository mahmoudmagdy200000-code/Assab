#!/usr/bin/env bash
# ASSAB BRC v3.4 isolated regression CI -- source guard.
#
# Proves, for the commit that is actually being tested:
#   1. the checked-out HEAD is exactly the requested tested commit;
#   2. the accepted anchor commit (Staging integration candidate) exists with the
#      recorded tree, and the historical accepted baseline is its ancestor;
#   3. the tested commit descends from the anchor (so it is the corrected
#      candidate lineage, never an unrelated commit);
#   4. no path changed since the anchor is denied by scripts/ci/source_scope_policy.txt
#      (deployment, secrets, schema, seeders, dependencies, staging workflows).
# Approved application corrections are allowed and reported by category.
#
# Environment (all required unless a default is shown):
#   TESTED_HEAD       commit under test
#   ANCHOR_SHA        accepted candidate commit
#   ANCHOR_TREE       its tree
#   BASELINE_SHA      historical accepted backend baseline (ancestor of the anchor)
#   POLICY_FILE       default scripts/ci/source_scope_policy.txt
#   ARTIFACT_DIR      default artifacts
set -euo pipefail

: "${TESTED_HEAD:?}" "${ANCHOR_SHA:?}" "${ANCHOR_TREE:?}" "${BASELINE_SHA:?}"
POLICY_FILE="${POLICY_FILE:-scripts/ci/source_scope_policy.txt}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts}"
mkdir -p "$ARTIFACT_DIR"
OUT="$ARTIFACT_DIR/source-guard.txt"
: > "$OUT"

fail() {
  echo "::error title=SOURCE GUARD::$*"
  echo "SOURCE_GUARD=FAIL reason=$*" | tee -a "$OUT"
  exit 1
}

head_sha="$(git rev-parse HEAD)"
[[ "$head_sha" == "$TESTED_HEAD" ]] || fail "checked-out HEAD $head_sha != requested tested head $TESTED_HEAD"

git cat-file -e "${ANCHOR_SHA}^{commit}" 2>/dev/null || fail "accepted anchor ${ANCHOR_SHA} is not present in the fetched history"
[[ "$(git rev-parse "${ANCHOR_SHA}^{tree}")" == "$ANCHOR_TREE" ]] || fail "anchor tree mismatch"
git cat-file -e "${BASELINE_SHA}^{commit}" 2>/dev/null || fail "accepted baseline ${BASELINE_SHA} is not present in the fetched history"
git merge-base --is-ancestor "$BASELINE_SHA" "$ANCHOR_SHA" || fail "accepted baseline ${BASELINE_SHA} is not an ancestor of the anchor"
git merge-base --is-ancestor "$ANCHOR_SHA" "$head_sha" || fail "tested commit does not descend from the accepted anchor ${ANCHOR_SHA}"

changed="$(git diff --name-only "$ANCHOR_SHA" "$head_sha")"

declare -a deny=() allow=()
while IFS= read -r line; do
  line="${line%%#*}"
  line="$(sed -E 's/[[:space:]]+$//;s/^[[:space:]]+//' <<<"$line")"
  [[ -z "$line" ]] && continue
  kind="${line%%[[:space:]]*}"
  pattern="$(sed -E 's/^[^[:space:]]+[[:space:]]+//' <<<"$line")"
  case "$kind" in
    deny)  deny+=("$pattern") ;;
    allow) allow+=("$pattern") ;;
    *) fail "unknown policy directive '$kind' in $POLICY_FILE" ;;
  esac
done < "$POLICY_FILE"
[[ ${#deny[@]} -gt 0 ]] || fail "policy file defines no deny rules"

violations=""
n_ci=0; n_tests=0; n_docs=0; n_app=0
while IFS= read -r path; do
  [[ -z "$path" ]] && continue
  denied=0
  for p in "${deny[@]}"; do
    if grep -Eq -- "$p" <<<"$path"; then denied=1; break; fi
  done
  if [[ $denied -eq 1 ]]; then
    for p in "${allow[@]:-}"; do
      [[ -z "$p" ]] && continue
      if grep -Eq -- "$p" <<<"$path"; then denied=0; break; fi
    done
  fi
  [[ $denied -eq 1 ]] && violations+="$path"$'\n'
  case "$path" in
    .github/*|scripts/ci/*) n_ci=$((n_ci+1)) ;;
    tests/*) n_tests=$((n_tests+1)) ;;
    docs/*|*.md) n_docs=$((n_docs+1)) ;;
    *) n_app=$((n_app+1)) ;;
  esac
done <<<"$changed"

{
  echo "tested_sha=$head_sha"
  echo "tested_tree=$(git rev-parse "${head_sha}^{tree}")"
  echo "anchor_sha=$ANCHOR_SHA"
  echo "anchor_tree=$ANCHOR_TREE"
  echo "baseline_sha=$BASELINE_SHA"
  echo "commits_since_anchor=$(git rev-list --count "${ANCHOR_SHA}..${head_sha}")"
  echo "files_changed_ci=$n_ci files_changed_tests=$n_tests files_changed_docs=$n_docs files_changed_application=$n_app"
  echo "files_changed_since_anchor:"
  printf '%s\n' "$changed"
} | tee -a "$OUT"

if [[ -n "$violations" ]]; then
  {
    echo "denied_paths:"
    printf '%s' "$violations"
  } | tee -a "$OUT"
  fail "paths denied by the source-scope policy changed since the accepted anchor"
fi

echo "SOURCE_GUARD=PASS" | tee -a "$OUT"
echo "::notice title=BRC34 source guard::PASS tested_sha=${head_sha} tested_tree=$(git rev-parse "${head_sha}^{tree}") anchor=${ANCHOR_SHA} commits_since_anchor=$(git rev-list --count "${ANCHOR_SHA}..${head_sha}") ci=${n_ci} tests=${n_tests} docs=${n_docs} application=${n_app} denied=0"
