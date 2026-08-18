<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Tests\Feature;

use Ejunker\SharedFixtures\Tests\TestCase;
use Ejunker\SharedFixtures\WithSharedFixtures;
use Workbench\App\Models\User;

// Regression: with $freshFixtures = true, a fixtures()-assigned model must be
// rebuilt (and re-captured) for every test. Previously the pristine cache kept
// the first test's model, whose row had already rolled back — so later tests
// cloned a model pointing at a non-existent row.
class FreshFixturesTest extends TestCase
{
    use WithSharedFixtures;

    protected bool $freshFixtures = true;

    protected static User $user;

    protected function fixtures(): void
    {
        static::$user = User::factory()->create();
    }

    // Assert on the unique email (not the id): SQLite reuses a rolled-back
    // auto-increment id, so an id check would coincidentally pass even with the
    // stale-clone bug. The email is regenerated per rebuild, so it only matches
    // a live row when the fixture was actually re-captured this test.
    public function test_fixture_matches_a_live_row_a(): void
    {
        $this->assertDatabaseHas('users', ['email' => static::$user->email]);
    }

    public function test_fixture_matches_a_live_row_b(): void
    {
        // a's user rolled back with its savepoint; the model handed to b must be
        // b's freshly-built user, whose email row actually exists — not a's
        $this->assertDatabaseHas('users', ['email' => static::$user->email]);
    }
}
