#!/bin/bash
# Create the pinba schema on first ClickHouse start.
# The base tables must exist before the materialized views that read from
# them, so apply the files in an explicit order (alphabetical would fail).
set -e

for f in pinba.requests.sql pinba.report_by_all.sql pinba.report_by_tags.sql; do
    clickhouse-client -n < "/schemas/$f"
done
