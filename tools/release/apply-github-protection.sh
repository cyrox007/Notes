#!/usr/bin/env bash
set -euo pipefail

repo="${1:-}"
if [[ -z "$repo" ]]; then
  repo="$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || true)"
fi
if [[ -z "$repo" || "$repo" != */* ]]; then
  echo "Usage: $0 owner/repo" >&2
  exit 2
fi

for command in gh php; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Required command not found: $command" >&2
    exit 2
  fi
done

policy=".github/release-governance.json"
if [[ ! -f "$policy" ]]; then
  echo "Run from a checkout containing $policy" >&2
  exit 2
fi

gh auth status >/dev/null
actor="$(gh api user --jq .login)"
independent_count="$({
  gh api --paginate "repos/$repo/collaborators?affiliation=direct&per_page=100" \
    --jq ".[] | select(.login != \"$actor\") | select(.permissions.push == true or .permissions.maintain == true or .permissions.admin == true) | .login" \
    || true
} | sort -u | sed '/^$/d' | wc -l | tr -d ' ')"

approvals=0
if [[ "${independent_count:-0}" -gt 0 ]]; then
  approvals=1
fi

read_checks() {
  local key="$1"
  php -r '
    $policy = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $checks = $policy[$argv[2]] ?? null;
    if (!is_array($checks) || $checks === []) {
        fwrite(STDERR, "Missing required checks for {$argv[2]}\\n");
        exit(2);
    }
    foreach ($checks as $check) {
        if (!is_string($check) || $check === "") {
            fwrite(STDERR, "Invalid required check in {$argv[2]}\\n");
            exit(2);
        }
        echo $check, PHP_EOL;
    }
  ' "$policy" "$key"
}

apply_branch() {
  local branch="$1"
  local key="$2"
  mapfile -t checks < <(read_checks "$key")

  local contexts_json
  contexts_json="$(printf '%s\\n' "${checks[@]}" | php -r '$a=[]; while (($l=fgets(STDIN))!==false) { $l=trim($l); if ($l!=="") $a[]=$l; } echo json_encode($a, JSON_THROW_ON_ERROR);')"

  local payload
  payload="$(CONTEXTS_JSON="$contexts_json" APPROVALS="$approvals" php -r '
    $contexts = json_decode((string) getenv("CONTEXTS_JSON"), true, 32, JSON_THROW_ON_ERROR);
    $approvals = (int) getenv("APPROVALS");
    echo json_encode([
      "required_status_checks" => ["strict" => true, "contexts" => $contexts],
      "enforce_admins" => true,
      "required_pull_request_reviews" => [
        "dismiss_stale_reviews" => true,
        "require_code_owner_reviews" => false,
        "required_approving_review_count" => $approvals,
        "require_last_push_approval" => false,
      ],
      "restrictions" => null,
      "required_linear_history" => false,
      "allow_force_pushes" => false,
      "allow_deletions" => false,
      "block_creations" => false,
      "required_conversation_resolution" => false,
      "lock_branch" => false,
      "allow_fork_syncing" => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  ')"

  printf '%s' "$payload" | gh api \
    --method PUT \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "repos/$repo/branches/$branch/protection" \
    --input - >/dev/null

  echo "Protected $repo:$branch with checks: ${checks[*]}"
}

apply_branch "1.0" "stabilization_required_checks"
apply_branch "master" "required_checks"

echo "Approvals required: $approvals (independent direct collaborators with write/maintain/admin: ${independent_count:-0})"
echo "Verification:"
for branch in 1.0 master; do
  gh api \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "repos/$repo/branches/$branch/protection" \
    --jq '{branch: "'"$branch"'", strict: .required_status_checks.strict, checks: [.required_status_checks.contexts[]], dismiss_stale_reviews: .required_pull_request_reviews.dismiss_stale_reviews, approvals: .required_pull_request_reviews.required_approving_review_count, enforce_admins: .enforce_admins.enabled, force_pushes: .allow_force_pushes.enabled, deletions: .allow_deletions.enabled}'
done
