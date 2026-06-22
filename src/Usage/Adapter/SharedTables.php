<?php

namespace Utopia\Usage\Adapter;

use Utopia\Query\Query;

/**
 * Multi-tenant ClickHouse adapter.
 *
 * Adds a shared `tenant` column to every table and keys the physical order on
 * it, so many tenants share one set of tables. All tenant SQL lives here — the
 * base {@see ClickHouse} adapter is single-tenant and tenant-agnostic.
 *
 * Tenancy on the two paths:
 *  - Writes carry the tenant per-row via the metric's `$tenant` key, so one
 *    buffer can batch many tenants in a single flush.
 *  - Reads/purges are scoped to a single tenant through {@see withTenant()},
 *    which hands the closure a tenant-bound clone — no shared mutable state,
 *    so it is safe under concurrent coroutines.
 */
class SharedTables extends ClickHouse
{
    /**
     * Tenant the current (cloned) instance is scoped to for reads/purges.
     * Null means "no implicit scope" — callers must obtain a scope via
     * withLock()-style {@see withTenant()}.
     */
    protected ?string $scopeTenant = null;

    /**
     * Run a callback against a clone scoped to the given tenant.
     *
     * The clone — not $this — is passed to the callback, so the scope cannot
     * leak past the closure or across coroutines sharing this instance.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     */
    public function withTenant(?string $tenant, callable $callback): mixed
    {
        $scoped = clone $this;
        $scoped->scopeTenant = $tenant;

        return $callback($scoped);
    }

    protected function tenantColumnDefs(): array
    {
        return ['tenant Nullable(String)'];
    }

    protected function keyPrefix(): array
    {
        return ['tenant'];
    }

    protected function extraQueryableColumns(): array
    {
        return ['tenant'];
    }

    protected function scopeOnlyAttributes(): array
    {
        return ['tenant'];
    }

    protected function scopeQueries(array $queries): array
    {
        if ($this->scopeTenant !== null) {
            $queries[] = Query::equal('tenant', [$this->scopeTenant]);
        }

        return $queries;
    }

    protected function decorateRow(array $row, array $metricData): array
    {
        $row['tenant'] = $this->resolveTenantFromMetric($metricData);

        return $row;
    }

    /**
     * Resolve the per-row tenant from a metric entry's `$tenant` key.
     *
     * @param  array<string, mixed>  $metricData
     */
    private function resolveTenantFromMetric(array $metricData): ?string
    {
        $tenant = $metricData['$tenant'] ?? null;

        if ($tenant === null) {
            return null;
        }

        if (is_string($tenant)) {
            return $tenant;
        }

        if (is_int($tenant) || is_float($tenant)) {
            return (string) $tenant;
        }

        return null;
    }
}
