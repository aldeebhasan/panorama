# Changelog

All notable changes to `panorama` will be documented in this file.

## Unreleased

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
  merges them with SQL. A shared contract suite every future engine must pass.
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
