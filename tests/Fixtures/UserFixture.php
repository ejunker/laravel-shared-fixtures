<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Tests\Fixtures;

use Ejunker\SharedFixtures\Contracts\CreatesFixture;
use Workbench\App\Models\User;

class UserFixture implements CreatesFixture
{
    public static function create(): User
    {
        return User::factory()->create(['name' => 'Fixture']);
    }
}
