<div align="center">
    <h1>Laravel Shared Fixtures</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/ejunker/laravel-shared-fixtures"><img src="https://img.shields.io/packagist/v/ejunker/laravel-shared-fixtures.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/ejunker/laravel-shared-fixtures"><img src="https://img.shields.io/packagist/php-v/ejunker/laravel-shared-fixtures.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/ejunker/laravel-shared-fixtures"><img src="https://badge.laravel.cloud/badge/ejunker/laravel-shared-fixtures?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/ejunker/laravel-shared-fixtures/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/ejunker/laravel-shared-fixtures/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/ejunker/laravel-shared-fixtures"><img src="https://img.shields.io/packagist/dt/ejunker/laravel-shared-fixtures.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Build your test fixtures **once per test class** and share them across every test — with automatic per-test rollback, so speed doesn't cost you isolation.

Think of it as pytest's `scope="class"` fixtures for Laravel: the expensive factory setup runs a single time for the class, but each test still gets a clean, isolated copy. You don't trade safety for speed.

## The problem

Building fixtures in `setUp()` (or with `RefreshDatabase` + factories) re-runs your factories for **every test method**. A fixture that costs 25ms and is used by 20 tests in a class costs you half a second — per class, across your whole suite that adds up to minutes.

The usual "fix" is to seed once and stop rolling back between tests, but then tests share mutable state, become order-dependent, and are miserable to debug.

## The solution

`WithSharedFixtures` wraps each test **class** in an outer database transaction and layers a per-test **savepoint** on top (using Laravel's own `DatabaseTransactions`). Your fixtures are built once, inside the outer transaction, and:

- **each test reads a fresh clone**, so in-memory mutations never leak between tests;
- **each test's own database writes roll back** at its savepoint;
- **the outer transaction rolls back** when the class finishes, so nothing is ever committed.

Factories run once. Every test is isolated. Test order never matters.

## Background

This package builds on the "scenario-specific setup" pattern popularized by David Hemphill's [Feature Tests Powered by Database Seeders](https://laravel-news.com/feature-tests-powered-by-database-seeders) — named, reusable classes that arrange a realistic slice of data for a test, instead of scattering factory calls through your test bodies.

`WithSharedFixtures` takes that idea further:

- the setup runs **once per class** instead of once per test method;
- you get a **typed handle** to reference (`static::$user`) instead of re-querying seeded rows;
- every test stays **isolated** via savepoint rollback, so sharing never makes tests order-dependent.

Already using scenario seeders? Keep them — call `$this->seed(YourScenarioSeeder::class)` inside [`fixtures()`](#the-fixtures-method--inline--runtime-construction) and it runs once for the class and rolls back with it.

## Requirements

- PHP 8.3+
- Laravel 12 / 13
- A database driver with **savepoint** support: MySQL, PostgreSQL, file-based SQLite, or SQL Server. (The trait throws a clear error on drivers without it.)

> **Note on the callable attribute form.** The `#[Fixture(Builder::method(...))]` form uses a first-class callable inside an attribute, which requires **PHP 8.5**. On PHP 8.3 / 8.4, use the `#[Fixture(Scenario::class)]` class-string form or the `fixtures()` method instead — both are fully supported on every version.

## Installation

```bash
composer require --dev ejunker/laravel-shared-fixtures
```

## Usage

### Quick start

Add the trait, declare a typed static property, and point `#[Fixture]` at a builder:

```php
use Ejunker\SharedFixtures\Fixture;
use Ejunker\SharedFixtures\WithSharedFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use WithSharedFixtures;

    #[Fixture(UserFixture::make(...))]
    protected static User $user;

    public function test_dashboard_loads(): void
    {
        $this->actingAs(static::$user)
            ->get('/dashboard')
            ->assertOk();
    }
}
```

`UserFixture::make()` runs **once** for the whole class. Every test reads `static::$user` as an isolated clone.

### Fixtures with a first-class callable

> Requires **PHP 8.5** (first-class callables inside attributes). On PHP 8.3 / 8.4, use the [scenario class-string form](#scenario-classes--grouping-several-related-models) or [`fixtures()`](#the-fixtures-method--inline--runtime-construction) instead.

Point the attribute at any static method reference. Extra constant arguments are forwarded:

```php
#[Fixture(UserFixture::make(...))]
protected static User $user;

#[Fixture(UserFixture::make(...), ['role' => 'admin'])]
protected static User $admin;

#[Fixture(UserFixture::admin(...))]        // a named variant on your builder
protected static User $namedAdmin;
```

```php
class UserFixture
{
    public static function make(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    public static function admin(): User
    {
        return self::make(['role' => 'admin']);
    }
}
```

> Attribute arguments must be **constant expressions** (literals, arrays, enums, class constants) — the same rule PHP applies to every attribute. For runtime values, use the `fixtures()` method below.

### Scenario classes — grouping several related models

When a fixture is a *situation* of related models, point `#[Fixture]` at a class implementing `CreatesFixture`. Its static `create()` builds the graph and returns a single typed handle:

```php
use Ejunker\SharedFixtures\Contracts\CreatesFixture;

final class CheckoutScenario implements CreatesFixture
{
    public function __construct(
        public Customer $customer,
        public Cart $cart,
        public Product $product,
    ) {}

    public static function create(): self
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $cart = Cart::factory()->for($customer)->hasAttached($product)->create();

        return new self($customer, $cart, $product);
    }
}
```

```php
#[Fixture(CheckoutScenario::class)]
protected static CheckoutScenario $checkout;

public function test_checkout(): void
{
    $this->assertTrue(static::$checkout->cart->contains(static::$checkout->product));
}
```

The grouping object and every model it holds are deep-cloned per test, so mutating `static::$checkout->customer` in one test can't affect another.

### The `fixtures()` method — inline & runtime construction

For one-off or runtime-computed fixtures, assign to typed static properties in `fixtures()`. The trait snapshots whatever you assign and hands each test a clone, exactly like the attribute path:

```php
protected static Team $team;
protected static User $owner;

protected function fixtures(): void
{
    static::$owner = User::factory()->create();
    static::$team = Team::factory()->for(static::$owner, 'owner')->create();

    // run a Laravel seeder that rolls back with the class:
    $this->seed(RolesSeeder::class);
}
```

### Fresh fixtures per test

Sharing is the default. If a class genuinely needs its fixtures rebuilt for every method (e.g. it mutates them heavily and you want maximum paranoia), opt in:

```php
class BillingTest extends TestCase
{
    use WithSharedFixtures;

    protected bool $freshFixtures = true;   // rebuild inside each test's savepoint
}
```

### Multiple connections

Like `DatabaseTransactions`, declare `$connectionsToTransact` to wrap more than the default connection. Fixtures written to any listed connection are shared and isolated the same way:

```php
protected array $connectionsToTransact = ['mysql', 'warehouse'];
```

## Isolation & cloning

Each test receives a clone of every fixture:

- **Models** are shallow-cloned — their attributes are isolated, and relations lazy-load per clone.
- **Scenario / grouping objects** are cloned along with their properties, recursing until it reaches a model.

One thing to know: because model clones are shallow, an **eagerly-loaded relation** is shared by reference. Keep fixtures relation-free (build related rows with `->save()`, which does not load them onto the parent) and let each clone lazy-load its own — or reload the relation per test. In practice the common `User` + `Profile` style fixture is already safe, because creating a related row does not load it onto the parent.

## When *not* to use it

This package is a scalpel, not a sledgehammer. It pays off when a test class has **many methods sharing an expensive fixture**. Reach for plain per-test factories instead when:

- the class has only one or two tests (nothing to amortize);
- each test needs genuinely different data (nothing to share);
- your fixtures are trivially cheap to build.

It is **not** a replacement for parallel testing. The biggest cost in most suites is per-test application boot, which no fixture strategy removes — run [`paratest`](https://github.com/paratestphp/paratest) for that. The two compose well: share fixtures in your fat classes *and* parallelize everything.

It is also **not** a global/reference-data tool. Data that every class depends on (lookups, roles, a base dataset) belongs committed once before the suite — this package is for a class's *own* fixtures, which you specifically do not want committed.

## How it works

1. On the first test of a class, the trait opens a real transaction on each connection and remembers its PDO.
2. It builds your fixtures inside that transaction, then hands the underlying `DatabaseTransactions` trait control to open a **savepoint** for the test.
3. Laravel rebuilds the application between tests, so the trait re-pins the same PDO and forces the connection's transaction level so the next `beginTransaction()` emits another savepoint rather than a fresh `BEGIN`.
4. Each test rolls back to its savepoint; the outer transaction rolls back in `tearDownAfterClass()`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Shared Fixtures! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Credits

- [Eric Junker](https://github.com/ejunker)
- [All Contributors](../../contributors)

## License

Laravel Shared Fixtures is open-sourced software licensed under the [MIT license](LICENSE.md).
