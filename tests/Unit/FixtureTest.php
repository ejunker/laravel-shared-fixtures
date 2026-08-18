<?php

declare(strict_types=1);

use Ejunker\SharedFixtures\Fixture;
use Ejunker\SharedFixtures\Tests\Fixtures\FixtureTestScenario;

it('invokes a first-class callable', function () {
    $fixture = new Fixture(fn () => 'built');

    expect($fixture->build())->toBe('built');
});

it('invokes a method first-class callable', function () {
    // the runtime form of #[Fixture(FixtureTestScenario::create(...))]; the
    // attribute form of this needs PHP 8.5, but the build() path is identical
    $fixture = new Fixture(FixtureTestScenario::create(...));

    expect($fixture->build())->toBe('scenario');
});

it('forwards extra args to a callable', function () {
    $fixture = new Fixture(fn (array $overrides) => $overrides, ['clearance' => 2]);

    expect($fixture->build())->toBe(['clearance' => 2]);
});

it('calls create() on a class-string', function () {
    $fixture = new Fixture(FixtureTestScenario::class);

    expect($fixture->build())->toBe('scenario');
});

it('forwards extra args to create()', function () {
    $fixture = new Fixture(FixtureTestScenario::class, 'x');

    expect($fixture->build())->toBe('scenario:x');
});
