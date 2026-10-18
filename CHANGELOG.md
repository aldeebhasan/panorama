# Changelog

All notable changes to `panorama` will be documented in this file.

## v0.4.0 - 2026-10-19

### Added

- **Result caching.** A report that sets `$cacheFor` keeps its rows for that many seconds per set of parameters, in
  the `cache.store` store. Results longer than `cache.max_rows` are streamed and not cached. `Panorama::forget()`
  drops them early.

## v0.3.0 - 2026-10-18

### Added

- **XLSX.** `XlsxRenderer`, registered as `xlsx`, and `ReportRun::toXlsx()`. Number, money and percent columns are
  written as numbers, other cells as their display reading, all as plain text so nothing becomes a formula. It streams
  through a temporary file and needs `ext-zip`.
- **Scheduled runs.** `Panorama::schedule($report)` registers a `Scheduling\ScheduledReport` on Laravel's scheduler.
  It takes `params()`, `format()`, `named()`, `store()`, `notify()`, `onConnection()` and `onQueue()`, and passes every
  frequency and constraint through to the scheduled event. Parameters, file names and notifiables can be closures
  evaluated when the schedule fires. Each run's file name is stamped with that moment and a hash of its parameters. A run that cannot be queued
  notifies `ReportFailed`.
- `ReportRun::stamped()` and `PendingReport::stamped()`, which stamp the default file name with a moment and a hash of the parameters.

- **Queued runs.** `Panorama::queue($report, $params, $user)` validates and authorizes now, and returns a
  `Queue\PendingReport`: `format()`, `named()`, `store()`, `notify()`, `onConnection()`, `onQueue()`, `delay()`, then
  `dispatch()`. The `Queue\RunReport` job renders the report and stores it on a disk. The user is authorized again
  in the worker.
- **Notifications.** `Notifications\ReportReady` is sent when a queued report is stored, with the report attached up
  to `queue.attach_up_to` bytes. `Notifications\ReportFailed` is sent after the last attempt fails, without the
  reason. Channels and classes are configurable under `panorama.queue`.
- `RenderedReport::bytes()`, the size of the document produced so far.

- **Run history.** With `panorama.history.enabled`, every rendered report is recorded in a `report_runs` table: report,
  parameters, user, format, destination, stored path, rows, bytes, duration, status and error. A run is inserted as
  `running` when delivery starts and settled as `succeeded` or `failed`. A download settles when its stream ends, and a
  run abandoned half way is recorded as failed. Publish the migration with `--tag=panorama-migrations`.
- `History\RecordedRun`, an Eloquent model over the table with `forReport()`, `failed()` and `latestFirst()` scopes.
- `History\RecorderContract`, to keep history elsewhere. `DatabaseRecorder` and `NullRecorder` are the built-ins.
- `reports:history` lists recent runs, filtered by report or `--failed`. `reports:prune` deletes runs older than
  `history.keep_days`, or `--days`.
- `Query\Result::yielded()`, how many rows a result has handed out.
- `ReportRun::user()`, the identifier of the user the run was authorized for.

### Changed

- `RenderedReport::download()` and `store()` now go through the same delivery path as `to()`.

## v0.2.0 - 2026-10-15

Panorama now focuses on building reports. Datasets fetch their own rows, and reports merge them in PHP. This breaks the
v0.1.0 API.

### Added

- **HTML export.** `ReportRun::toHtml()` and `reports:run --format=html` render a standalone page: the report name as
  its title, the table with display values, numeric columns right aligned, and a totals footer. It carries its own
  stylesheet and escapes every value. `new HtmlRenderer(document: false)` renders the bare table to embed, and
  `stylesheet:` replaces the built-in styles.
- `Column::isNumeric()`, true for `money()`, `number()` and `percent()` columns.

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
- `RendererContract::render()` takes a third parameter, `string $title = ''`, the report's name. Custom renderers must
  accept it.

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
