<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Wraps each test class in an outer transaction and layers a per-test savepoint
 * on top via Laravel's DatabaseTransactions. Each test rolls back to its
 * savepoint (fixtures survive); the outer transaction rolls back when the class
 * finishes, so nothing is committed to the database. Fixtures are built ONCE for
 * the class and each test reads a fresh clone.
 *
 * Requires a driver with savepoint support (MySQL, PostgreSQL, file SQLite,
 * SQL Server).
 *
 * Fixtures — two co-located, type-safe options on a static property:
 *
 *   1. #[Fixture(...)] attribute — the factory runs ONCE for the class; each
 *      test gets a fresh clone, so in-memory mutations never leak between tests:
 *
 *          #[Fixture(SeekerFixture::make(...))]
 *          protected static Seeker $seeker;   // read as static::$seeker
 *
 *   2. fixtures() method — for inline/runtime construction. Assign to a typed
 *      static property yourself; the trait snapshots it and hands each test a
 *      clone, same as the attribute path:
 *
 *          protected static Employer $employer;
 *
 *          protected function fixtures(): void
 *          {
 *              static::$employer = Employer::factory()->create();
 *          }
 *
 * Either way the factory runs once per class and each test reads a fresh clone.
 * Cloning is automatic: a model is shallow-cloned (its attributes are isolated,
 * relations lazy-load per clone), and any object that groups models (a scenario
 * DTO) has its properties cloned too — so nothing leaks between tests.
 */
trait WithSharedFixtures
{
    use DatabaseTransactions {
        beginDatabaseTransaction as beginPerTestTransaction;
    }

    /** @var array<string, PDO> Outer-transaction PDOs keyed by connection name, shared across the class's per-test app rebuilds. */
    protected static array $seedPdos = [];

    /** @var array<string, mixed> Pristine #[Fixture] values, built once and cloned per test. */
    private static array $pristineFixtures = [];

    // Declare `protected bool $freshFixtures = true;` in your test class to
    // rebuild the fixtures inside each test's savepoint instead of once for the
    // class. Slower, but guarantees no test depends on state left behind by a
    // previous test. (Not declared here so the class can override the default —
    // PHP forbids redeclaring a trait property with a different value.)

    /**
     * Build test data, assigning to typed static properties on your test class.
     * Runs once inside the class transaction. Call $this->seed(...) here if the
     * class needs a Laravel seeder run (and rolled back with the class).
     */
    protected function fixtures(): void {}

    /**
     * Overrides the trait method setUpTraits() calls during setUp(). Pinning the
     * shared PDO and pre-setting the transaction level to 1 turns the trait's
     * beginTransaction() into a SAVEPOINT instead of a fresh BEGIN.
     *
     * @throws Throwable
     */
    public function beginDatabaseTransaction(): void
    {
        $db = $this->app->make('db');
        $firstTest = static::$seedPdos === [];

        // open (or re-pin) the outer transaction on every transacted connection
        // — must happen before fixtures() so cross-connection writes roll back
        foreach ($this->connectionsToTransact() as $name) {
            $connection = $db->connection($name);
            $key = $connection->getName();

            if ($firstTest) {
                throw_unless(
                    $connection->getQueryGrammar()->supportsSavepoints(),
                    RuntimeException::class,
                    'WithSharedFixtures requires a database driver with savepoint support (MySQL, PostgreSQL, SQLite, SQL Server).',
                );

                $connection->beginTransaction();
                static::$seedPdos[$key] = $connection->getPdo();
            } else {
                // reuse the still-open outer transaction on the fresh connection
                $pdo = static::$seedPdos[$key];
                $connection->setPdo($pdo);
                $connection->setReadPdo($pdo);
                // force the transaction level to 1 so beginTransaction() emits a
                // SAVEPOINT instead of a fresh BEGIN (reaches a protected property)
                (fn () => $this->transactions = 1)->call($connection); // @phpstan-ignore property.notFound
            }
        }

        $freshFixtures = property_exists($this, 'freshFixtures') && $this->freshFixtures;

        // build + capture pristine fixtures once at the outer level — the
        // factories run a single time and their rows persist for the whole class
        if ($firstTest && ! $freshFixtures) {
            $this->fixtures();
            $this->capturePristineFixtures();
        }

        $this->beginPerTestTransaction();

        // rebuild inside this test's savepoint — rolled back after every test
        if ($freshFixtures) {
            $this->fixtures();
            $this->capturePristineFixtures();
        }

        // hand every fixture property a fresh clone so in-memory mutations in
        // one test never bleed into the next
        $this->assignFixtureClones();
    }

    /**
     * Capture the pristine value of every fixture, from both sources:
     *   - #[Fixture(...)] attributes — invoke the referenced factory once
     *   - fixtures() — snapshot whatever it assigned to static properties
     */
    private function capturePristineFixtures(): void
    {
        $attributeProperties = [];

        foreach ($this->fixtureProperties() as [$property, $build]) {
            $name = $property->getName();
            self::$pristineFixtures[$name] = $build();
            $attributeProperties[$name] = true;
        }

        // Snapshot whatever fixtures() assigned. Skip #[Fixture] properties
        // (already built above); re-capture the rest every call so the
        // freshFixtures path picks up each test's freshly-built value rather
        // than a stale one whose row has since rolled back.
        foreach ($this->userStaticProperties() as $property) {
            $name = $property->getName();

            if (! isset($attributeProperties[$name]) && $property->isInitialized()) {
                self::$pristineFixtures[$name] = $property->getValue();
            }
        }
    }

    /**
     * Assign a fresh clone of each pristine fixture back to its static property.
     */
    private function assignFixtureClones(): void
    {
        $reflection = new ReflectionClass(static::class);

        foreach (self::$pristineFixtures as $name => $value) {
            $reflection->getProperty($name)->setValue(null, $this->cloneFixture($value));
        }
    }

    /**
     * Clone a fixture value for a single test. Models are shallow-cloned (their
     * attributes are isolated; relations lazy-load per clone). Any other object
     * — a scenario DTO grouping models — is cloned along with its properties, so
     * nothing is shared between tests. Recursion stops at models by design.
     */
    private function cloneFixture(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn (mixed $item) => $this->cloneFixture($item));
        }

        if (! is_object($value)) {
            return $value;
        }

        $clone = clone $value;

        if ($clone instanceof Model) {
            return $clone;
        }

        foreach (get_object_vars($clone) as $property => $inner) {
            $clone->{$property} = $this->cloneFixture($inner);
        }

        return $clone;
    }

    /**
     * Every #[Fixture] property paired with a closure that builds its value.
     *
     * @return array<int, array{0: ReflectionProperty, 1: Closure}>
     */
    private function fixtureProperties(): array
    {
        $result = [];

        foreach ($this->userStaticProperties() as $property) {
            foreach ($property->getAttributes(Fixture::class) as $attribute) {
                $fixture = $attribute->newInstance();
                $result[] = [$property, fn () => $fixture->build()];
            }
        }

        return $result;
    }

    /**
     * Static properties declared by the test class, excluding the trait's own.
     *
     * @return array<int, ReflectionProperty>
     */
    private function userStaticProperties(): array
    {
        $internal = ['seedPdos', 'pristineFixtures'];

        return array_values(array_filter(
            (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_STATIC),
            fn (ReflectionProperty $property) => ! in_array($property->getName(), $internal, true),
        ));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (static::$seedPdos as $pdo) {
            $pdo->rollBack();
        }

        static::$seedPdos = [];
        self::$pristineFixtures = [];

        parent::tearDownAfterClass();
    }
}
