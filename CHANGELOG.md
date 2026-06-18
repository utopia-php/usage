# Changelog

## 0.4.0 — Per-dimension ClickHouse projections and auto-routing

This release accelerates grouped reads against the events and gauges
tables by declaring ClickHouse `PROJECTION`s on the base tables. The
ClickHouse optimizer transparently routes any grouped query whose
GROUP BY shape matches a projection — no library-level routing
scaffolding required.

The projections live on the base tables themselves:

- `projects_usage_events.p_by_path`
- `projects_usage_events.p_by_country`
- `projects_usage_events.p_by_service`
- `projects_usage_events.p_by_method_status`
- `projects_usage_gauges.p_by_service`
- `projects_usage_gauges.p_by_resource`

`Usage::find()` issues a normal `GROUP BY` against the base table; the
optimizer picks the matching projection when one exists. The decision
is visible per query via `system.query_log.projections`.

### Upgrade note: projections are empty for pre-upgrade data

ClickHouse projections only capture rows inserted **after** the
projection is declared. Rows already in the events / gauges tables at
upgrade time are not materialized into the projection by
`ADD PROJECTION` alone. Queries that hit only pre-upgrade days will
return the same result either way (the optimizer falls back to the
base table when the projection can't satisfy the read), but to gain
the routing win on historical days you need to materialize each
projection per partition:

```sql
-- Materialize one partition at a time during off-peak hours.
ALTER TABLE <ns>_usage_events
  MATERIALIZE PROJECTION p_by_path
  IN PARTITION 'YYYYMM' SETTINGS mutations_sync = 2;
```

Repeat for `p_by_country`, `p_by_service`, `p_by_method_status` on
events, and `p_by_service`, `p_by_resource` on gauges. Throttle by
partition if the source table is large. Greenfield installs need no
action — projections capture all subsequent inserts.

### Other notable changes

- The pre-existing events daily MV (`projects_usage_events_daily`)
  and its routing in `Usage::sum()` / `Usage::getTotal()` are
  unchanged. The flat-sum path still chooses `daily` / `hybrid` /
  `raw` based on the query shape; the route log surfaces that choice.
- Gauges schema gains `service` and `resource` columns (idempotent
  `ALTER` applied in `setup()` for existing deployments).
- `ALTER TABLE ... MODIFY SETTING lightweight_mutation_projection_mode = 'rebuild'`
  is applied to the events and gauges tables so `DELETE` (the purge
  path) re-materializes affected projection parts atomically.
- The dual-read sampler now applies only to the events daily MV path
  (the one source that can drift from raw). Projection-routed reads
  are derived in the same write transaction as the parent insert and
  cannot diverge, so they're not sampled.
