<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Tests\Fixtures;

use Ejunker\SharedFixtures\Contracts\CreatesFixture;

class FixtureTestScenario implements CreatesFixture
{
    public static function create(string $suffix = ''): string
    {
        return $suffix === '' ? 'scenario' : "scenario:{$suffix}";
    }
}
