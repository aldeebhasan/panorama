# Changelog

All notable changes to `panorama` will be documented in this file.

## Unreleased

Panorama now focuses on building reports. Datasets fetch their own rows, and reports merge them in PHP. This breaks the
v0.1.0 API.

### Changed

- **Datasets fetch their own rows.** `fetch()` uses whatever the application already uses: `DB::table()->cursor()`,
  the HTTP client, an Eloquent model. The `$connection` property and the `query()`, `http()` and `fixtures()` helpers
  are gone.
- **Reports merge in PHP.** `Report::query()` returns a `Query\DatasetQuery`: `join`, `leftJoin`, `union` and
  `tagSource`, the `where` family, `filter(Closure)`, `groupBy` and `aggregate` with `Query\Aggregate`, `having`,
  `select`, `orderBy`, `limit` and `offset`. Join keys may be named in either order, and are still inferred from declared
  relations. A grouped query outputs its group columns and aggregates, replacing `sum(...) as ...` select expressions.
- Decimals are summed exactly at their declared scale: revenue of `19.99 + 30.00` is now `49.99`, not
  `49.989999999999995`.
- With no `select`, a field name two joined datasets share comes out as `dataset.field` for both, instead of one
  overwriting the other.
- `reports:list` shows the datasets each report reads instead of its engine. `datasets:list` and `datasets:describe`
  no longer show a connection.
- `ReportRun::merge()` is now `ReportRun::query()`.

### Removed

- Connections: the `connections` config, `ConnectionRegistry`, and the `database`, `http` and `array` drivers.
- Workspaces: the `workspace` and `workspaces` config, `DatabaseWorkspace`, `SqlCompiler`, `MergeRunner`,
  `WorkspaceFactory` and `Report::$engine`. No temporary tables are created any more.
- Raw SQL: `Datasets::sql()`, `whereRaw()` and `havingRaw()`. Use `filter()` for tests the conditions cannot express.
- `Query\Query`, `Query\Merge`, `Query\Planner`, `ConnectionException` and `WorkspaceException`.
- The MySQL and PostgreSQL CI legs, since the package no longer generates SQL.

## v0.1.0 - 2026-10-13

Initial implementation of the design in `docs/`. See `docs/roadmap.md` for the scope of this release and what was
deliberately left out.

### Added

- **Schema engine.** `Schema::make()` declares a dataset's fields, types, primary key, field mapping and relations.
  Rows are processed in one fixed order — map, default, cast, normalize, validate, apply the violation policy — and the
  policy is `fail`, `skip` or `nullify` per dataset. Casts reject rather than guess.
- **Connections.** `database`, `http` (with pagination) and `array` drivers, declared once in config and shared by many
  datasets.
- **Datasets.** Defined once, looked up by name, registered by list or by directory discovery. Rows stream as
  generators, so a dataset of any size costs one row of memory. `Datasets::fake()` swaps any source for fixtures while
  still enforcing the real schema.
- **Merging.** A fluent builder for joins, unions and aggregates, plus raw SQL. Join keys are inferred from declared
  relations; incompatible key types, mismatched union shapes and unknown datasets all fail before anything is fetched.
- **Workspace.** `DatabaseWorkspace` loads datasets into temporary tables in a database the application already has and
  merges them with SQL. A shared contract suite every future engine must pass, run against SQLite, MySQL and
  PostgreSQL in CI.
- **Reports.** `parameters()`, `query()`, `columns()` and `authorize()`. Parameters are validated against the declared
  rules; `authorize()` is enforced when a user is given.
- **Rendering and delivery.** CSV and JSON, streamed; download responses and Storage disks.
- **Console.** `datasets:list`, `datasets:describe`, `reports:list`, `reports:run`, `make:dataset`, `make:report`.

### Changed

- PHPStan raised from level 5 to `max`.
- `ReportUI` renamed to `ReportContract`: it is the report contract, not a UI.
- `ReportContract::build(): array` replaced by a streamed result.
- `panorama:list` and `panorama:generate` replaced by `reports:list` and `reports:run`.

### Removed

- The per-filter result cache. It cached whole array payloads, which no longer exist now that rows stream. A cache of
  the *rendered file*, keyed by report plus parameter hash, is planned instead.
