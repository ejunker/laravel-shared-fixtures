<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Tests\Feature;

use Ejunker\SharedFixtures\Fixture;
use Ejunker\SharedFixtures\Tests\Fixtures\UserFixture;
use Ejunker\SharedFixtures\Tests\TestCase;
use Ejunker\SharedFixtures\WithSharedFixtures;
use Workbench\App\Models\User;

// Uses the class-string #[Fixture] form (not the first-class-callable form) so
// this file parses on PHP 8.3/8.4. The FCC-attribute syntax needs PHP 8.5;
// Fixture::build()'s callable branch is covered at runtime in Unit/FixtureTest.
class SharedFixturesTest extends TestCase
{
    use WithSharedFixtures;

    #[Fixture(UserFixture::class)]
    protected static User $user;

    protected static User $inlineUser;

    protected function fixtures(): void
    {
        static::$inlineUser = User::factory()->create(['name' => 'Inline']);
    }

    public function test_attribute_fixture_is_built(): void
    {
        $this->assertInstanceOf(User::class, static::$user);
        $this->assertNotNull(static::$user->getKey());
    }

    public function test_inline_fixture_is_built(): void
    {
        $this->assertSame('Inline', static::$inlineUser->name);
    }

    // both mutate $user in memory and both must see it pristine — proves the
    // per-test clone, regardless of execution order
    public function test_fixture_is_pristine_a(): void
    {
        $this->assertNotSame('MUTATED', static::$user->name);
        static::$user->name = 'MUTATED';
    }

    public function test_fixture_is_pristine_b(): void
    {
        $this->assertNotSame('MUTATED', static::$user->name);
        static::$user->name = 'MUTATED';
    }

    // a per-test DB write is rolled back by the savepoint before the next test
    public function test_pertest_write_rolled_back_a(): void
    {
        User::factory()->create(['email' => 'ephemeral@example.com']);
        $this->assertTrue(User::where('email', 'ephemeral@example.com')->exists());
    }

    public function test_pertest_write_rolled_back_b(): void
    {
        $this->assertFalse(User::where('email', 'ephemeral@example.com')->exists());
    }
}
