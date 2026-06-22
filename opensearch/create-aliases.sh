#!/bin/bash

# OpenSearch host
OPENSEARCH_HOST="${1:-10.0.2.30}"
OPENSEARCH_PORT="${2:-9200}"

echo "Creating OpenSearch index aliases for audit logging..."

# Create aliases for each environment
curl -X POST "$OPENSEARCH_HOST:$OPENSEARCH_PORT/_aliases" -H 'Content-Type: application/json' -d '
{
  "actions": [
    {
      "add": {
        "index": "audit-dev-*",
        "alias": "audit-dev"
      }
    },
    {
      "add": {
        "index": "audit-staging-*",
        "alias": "audit-staging"
      }
    },
    {
      "add": {
        "index": "audit-prod-*",
        "alias": "audit-prod"
      }
    }
  ]
}'

echo ""
echo "Creating combined alias for all audit logs..."

curl -X POST "$OPENSEARCH_HOST:$OPENSEARCH_PORT/_aliases" -H 'Content-Type: application/json' -d '
{
  "actions": [
    {
      "add": {
        "index": "audit-*",
        "alias": "audit-all"
      }
    }
  ]
}'

echo ""
echo "Creating index templates..."

# Create audit index template
curl -X PUT "$OPENSEARCH_HOST:$OPENSEARCH_PORT/_index_template/audit_template" -H 'Content-Type: application/json' -d '
{
  "index_patterns": ["audit-*"],
  "priority": 100,
  "template": {
    "settings": {
      "number_of_shards": 2,
      "number_of_replicas": 1
    },
    "mappings": {
      "properties": {
        "@timestamp": {"type": "date"},
        "environment": {"type": "keyword"},
        "system": {"type": "keyword"},
        "action": {"type": "keyword"},
        "entity_type": {"type": "keyword"},
        "entity_id": {"type": "long"},
        "actor_id": {"type": "long"},
        "actor_type": {"type": "keyword"},
        "ip_address": {"type": "ip"},
        "trace_id": {"type": "keyword"}
      }
    }
  }
}'

echo ""
echo "Aliases and templates created successfully!"
echo ""
echo "You can now search using:"
echo "  audit-dev     -> All dev audit logs"
echo "  audit-staging -> All staging audit logs"
echo "  audit-prod    -> All prod audit logs"
echo "  audit-all     -> All audit logs across environments"