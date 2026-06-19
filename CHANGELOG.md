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
- `projects_usage_gauges.p_by_service`
- `projects_usage_gauges.p_by_resource`
- `projects_usage_gauges.p_by_resourceId`
- `projects_usage_gauges.p_by_resource_resourceId`

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

Repeat for `p_by_country`, `p_by_service` on events and
`p_by_service`, `p_by_resource`, `p_by_resourceId` on gauges. Throttle
by partition if the source table is large. Greenfield installs need
no action — projections capture all subsequent inserts.

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
- The `daily` route on the events flat-sum path now requires both
  `time` bounds to fall on UTC midnight. A caller passing a mid-day
  bound (e.g. `time >= '2026-06-10 12:00:00'`) falls back to the raw
  scan; the daily MV stores rows at `toStartOfDay(time)` and a
  mid-day predicate would otherwise exclude the partial start day
  and over-include the partial end day.
- Narrow purges that target a column the events daily MV does not
  carry (e.g. `Query::equal('path', [...])`) AND carry no `time`
  bound are now treated as a no-op on the daily side. The raw
  events table still receives the narrow delete; the daily MV is
  left to overwrite stale rows on the next ingest cycle rather
  than issuing an unbounded `DELETE WHERE 1=1` that would wipe
  unrelated metrics. `value` is also removed from the daily-safe
  filter set: the daily `value` is a SUMmed aggregate, not a raw
  event value.
