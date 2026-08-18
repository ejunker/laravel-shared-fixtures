# Release Notes

## [Unreleased](https://github.com/ejunker/laravel-shared-fixtures/compare/v0.1.0...1.x)

## [v0.1.0](https://github.com/ejunker/laravel-shared-fixtures/compare/...v0.1.0) - 2026-08-17

Initial release.

### Added

- `WithSharedFixtures` trait — wraps each test class in an outer transaction and layers a per-test savepoint on top, so fixtures are built once per class while every test stays isolated and rolls back.
- `#[Fixture]` attribute for declaring fixtures on typed static properties, accepting either a first-class callable (`#[Fixture(Builder::make(...))]`, PHP 8.5+) or a `CreatesFixture` class-string (`#[Fixture(Scenario::class)]`, any supported version).
- `CreatesFixture` contract for named, reusable scenario classes that build a single model or a group of related models.
- `fixtures()` method hook for inline and runtime fixture construction, including `$this->seed(...)` support that rolls back with the class.
- Automatic per-test cloning: models are shallow-cloned (attributes isolated, relations lazy-load per clone) and scenario objects are cloned along with their properties, so in-memory mutations never leak between tests.
- `$freshFixtures` opt-in to rebuild fixtures inside each test's savepoint instead of once per class.
- Multi-connection support via `$connectionsToTransact`, matching Laravel's `DatabaseTransactions`.
- Savepoint-support guard that throws a clear error on drivers without it.
