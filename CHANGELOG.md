# Changelog

## 0.4.0 — Per-dimension rollups and auto-routing

This release introduces per-dimension materialized views over the events
and gauges tables and routes closed-day grouped reads to them
automatically. The new MVs are:

- `<ns>_usage_events_daily_by_path`
- `<ns>_usage_events_daily_by_country`
- `<ns>_usage_events_daily_by_service`
- `<ns>_usage_events_daily_by_method_status`
- `<ns>_usage_gauges_daily_by_service`
- `<ns>_usage_gauges_daily_by_resource`

`Usage::find()` / `Usage::sum()` route eligible queries to the cheapest
source automatically — there is no configuration knob. Routing decisions
are recorded in the adapter's route log (see
`ClickHouse::getRouteLog()`) so downstream operators can audit the
chosen path.

### Upgrade note: rollup backfill required

ClickHouse materialized views only capture rows inserted **after** the
MV is created. Rows already in the events / gauges tables at upgrade
time are NOT backfilled by the library. Auto-routing those queries to
the empty MV will undercount until the backfill completes.

**For existing deployments**, run a one-time backfill per MV before
relying on grouped reads:

```sql
-- events by_path
INSERT INTO <ns>_usage_events_daily_by_path (metric, time, path, tenant, value)
SELECT
    metric,
    toStartOfDay(time) AS time,
    path,
    tenant,
    sum(value) AS value
FROM <ns>_usage_events
WHERE time < (SELECT min(time) FROM <ns>_usage_events_daily_by_path)
GROUP BY metric, toStartOfDay(time), path, tenant;
```

Repeat for each rollup MV, substituting the dim columns
(`country`, `service`, `method, status`). For gauges use
`argMaxState(value, time)` instead of `sum(value)` since the gauge
rollups use `AggregatingMergeTree`:

```sql
-- gauges by_service
INSERT INTO <ns>_usage_gauges_daily_by_service (metric, time, service, tenant, value)
SELECT
    metric,
    toStartOfDay(time) AS time,
    service,
    tenant,
    argMaxState(value, time) AS value
FROM <ns>_usage_gauges
WHERE time < (SELECT min(time) FROM <ns>_usage_gauges_daily_by_service)
GROUP BY metric, toStartOfDay(time), service, tenant;
```

Backfill during off-peak hours; throttle by month if the source table is
large. Once backfill completes, set
`ClickHouse::setDualReadSampleRate(0.01)` (1% sampled dual-read) for a
day or two to catch any per-group divergences before relying on the
routed result.

**Greenfield installs**: no action needed — MVs are created at first
`setup()` and capture all subsequent inserts.

### Other notable changes

- Gauges schema gains `service` and `resource` columns (ALTER applied
  in `setup()` for existing deployments).
- Auto-routing falls back to raw scans for queries shaped in ways the
  rollups cannot serve correctly (filters on `id` / `value`, cursor
  pagination, `orderBy('time')` on grouped queries, sub-day intervals).
- Purge operations propagate across rollups: when a purge filter
  references a column the rollup doesn't store, the rollup still drops
  the affected whole-day rows to avoid stale aggregates.
- Dual-read sampler compares per-group values for grouped queries
  (totals-only comparison missed distribution bugs that cancel out).
