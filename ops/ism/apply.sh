#!/usr/bin/env bash
# Apply ISM retention policies to OpenSearch so old log indices auto-delete.
# New daily indices auto-attach via each policy's ism_template; this also
# attaches the policy to any indices that already exist.
#
# Usage: OPENSEARCH=http://10.0.2.30:9200 ./apply.sh
set -euo pipefail

OS="${OPENSEARCH:-http://10.0.2.30:9200}"
DIR="$(cd "$(dirname "$0")" && pwd)"

declare -A PATTERNS=(
  [audit-retention]="logs-audit-*"
  [app-retention]="logs-app-*"
  [error-retention]="logs-dev-*"
)

for policy in "${!PATTERNS[@]}"; do
  echo "==> PUT policy: $policy"
  curl -s -X PUT "$OS/_plugins/_ism/policies/$policy" \
    -H 'Content-Type: application/json' \
    --data-binary "@$DIR/$policy.json" | grep -o '"_id":"[^"]*"' || true
  echo "    attach to existing ${PATTERNS[$policy]}"
  curl -s -X POST "$OS/_plugins/_ism/add/${PATTERNS[$policy]}" \
    -H 'Content-Type: application/json' \
    -d "{\"policy_id\":\"$policy\"}" >/dev/null || true
done

echo "Done. Verify: curl \"$OS/_plugins/_ism/explain/logs-audit-*?pretty\""
