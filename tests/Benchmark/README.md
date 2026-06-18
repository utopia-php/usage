# `tests/Benchmark/` — ClickHouse perf harness

Synthetic-load benchmarks for `utopia-php/usage`. Not run by `composer test`;
invoke with `composer bench`.

## Running

```bash
docker compose up -d --wait
docker compose exec usage composer bench
```

Dataset size is controlled by `BENCH_ROWS` (default: 1,000,000). The seed SQL
in `fixtures/seed.sql` is parameterised, so the same harness covers 1M / 10M /
100M:

```bash
BENCH_ROWS=10000000 docker compose exec usage composer bench
BENCH_ROWS=100000000 docker compose exec usage composer bench
```

Each scenario runs 1 warmup pass + 5 measured iterations (override
`$iterations` per scenario if needed). For inserts, fewer iterations are used
to keep dataset bloat bounded.

## Output

Per-class JSON reports land in `tests/Benchmark/output/<class>.json`:

```json
{
  "scenarios": {
    "bench_events_sum_30d": {
      "iterations": 5,
      "rows_dataset": 1000000,
      "wall_p50_ms": 31.2,
      "wall_p95_ms": 38.7,
      "ch_p50_ms": 27.4,
      "ch_p95_ms": 33.1,
      "rows_read_p50": 1000000,
      "rows_read_p95": 1000000,
      "read_bytes_p50": 18504192,
      "read_bytes_p95": 18504192,
      "samples": [...]
    }
  }
}
```

ClickHouse-side stats (`rows_read`, `read_bytes`, `query_duration_ms`) come
from `system.query_log`, joined by the `query_id` the harness forwards to
each call via `ClickHouse::setNextQueryId()`.

## Scenarios

| Name | Shape | Expected budget @ 1M |
|---|---|---|
| `bench_events_sum_30d` | flat sum, no interval, no dims | p50 < 50ms |
| `bench_events_timeseries_30d_1h` | `interval=1h`, no dims | p50 < 200ms |
| `bench_events_topN_path_30d` | `groupBy('path')`, no interval | p50 < 300ms |
| `bench_events_topN_method_status_30d` | `groupBy(method, status)` | p50 < 250ms |
| `bench_events_topN_country_30d` | `groupBy('country')` | p50 < 250ms |
| `bench_events_topN_service_30d` | `groupBy('service')` | p50 < 250ms |
| `bench_events_count_max_5k` | capped count | p50 < 10ms |
| `bench_insert_10k` | 10k-row batch insert | p50 < 2000ms |
| `bench_gauges_latest_in_window` | gauge latest | p50 < 50ms |

Multi-dim MV scenarios (`*_mv`, `*_today_partial`, `*_filtered_resource`,
etc.) ship with commit 4 once the MVs land; their targets are documented in
`/tmp/multi-dim-mv-strategy.md` §7.

Budgets are guidance, not gates — CI promotion to fail-on-regression happens
after one stable release cycle (per `usage-final-plan.md` §P0).

## Notes

* Seeding uses `INSERT … SELECT FROM numbers(N)` to bypass the library's
  batch path. Wall-time scales linearly with row count; expect a 100M seed to
  take several minutes.
* The harness flushes `system.query_log` (`SYSTEM FLUSH LOGS`) before
  reading per-iteration stats. ClickHouse Cloud and some hardened
  deployments deny that statement; in that case `rows_read`/`read_bytes`
  come back zero, but PHP-side `wall_ms` is still accurate.
* Each scenario uses a unique `query_id` so `system.query_log` rows never
  collide. The `query_id` is generated per-iteration in `BenchmarkBase`.
