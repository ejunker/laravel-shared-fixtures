---
name: laravel-shared-fixtures-development
description: >
  Configure and apply the Laravel Shared Fixtures package in Laravel applications.
license: MIT
metadata:
  author: Eric Junker
---

# Laravel Shared Fixtures

Use this skill when a Laravel test class rebuilds the same expensive fixtures in
every test method and you want to build them once per class while keeping each
test isolated.

## Primary Goal

- apply the `ejunker/laravel-shared-fixtures` package's public API in the smallest correct way

## When to reach for it

- a test class has many methods that share the same fixture setup
- the setup is expensive (factories, related models, seeders) and dominates the class's runtime

Do NOT reach for it when the class has one or two tests, each test needs
different data, the fixtures are trivially cheap, or the data is global
reference data that belongs committed once before the suite.

## Workflow

### 1. Add the trait

Add `Ejunker\SharedFixtures\WithSharedFixtures` to the test class. It requires a
database driver with savepoint support (MySQL, PostgreSQL, file SQLite, SQL
Server) and replaces `DatabaseTransactions`/`RefreshDatabase` for that class.

### 2. Declare fixtures on typed static properties

Pick the smallest form that fits:

```php
use Ejunker\SharedFixtures\Fixture;
use Ejunker\SharedFixtures\WithSharedFixtures;

class OrdersTest extends TestCase
{
    use WithSharedFixtures;

    // a scenario class (any PHP version) — good for grouped/related models
    #[Fixture(CheckoutScenario::class)]
    protected static CheckoutScenario $checkout;

    // a first-class callable (PHP 8.5+) — good for a single model
    #[Fixture(UserFixture::make(...))]
    protected static User $user;

    // inline / runtime construction
    protected static Team $team;

    protected function fixtures(): void
    {
        static::$team = Team::factory()->create();
    }
}
```

Read fixtures in tests via the static property (`static::$user`). Each test
receives an isolated clone.

### 3. Choose the form by PHP version and shape

- single model, PHP 8.5+: `#[Fixture(Builder::method(...))]`
- single model or grouped models, any version: `#[Fixture(ScenarioClass::class)]` where the class implements `Ejunker\SharedFixtures\Contracts\CreatesFixture` with a static `create()`
- runtime values or several related properties from one build: the `fixtures()` method

## Rules, References, and Templates

- attribute arguments must be constant expressions; use `fixtures()` for runtime values
- keep fixtures relation-free (build related rows with `->save()`); let each clone lazy-load its own relations
- for a class that must not share state, set `protected bool $freshFixtures = true;`
- for extra connections, set `protected array $connectionsToTransact = [...]`

## Examples

- convert a class where every test calls `$this->getActiveEmployer()` in `setUp()` into a single `#[Fixture]` on a static property, then reference the clone in each test

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not use it to seed global reference data — that belongs committed once before the suite
- do not eager-load a relation onto a shared fixture you intend to mutate; the shallow clone shares loaded relations
