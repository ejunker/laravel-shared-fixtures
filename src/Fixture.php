<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures;

use Attribute;
use Closure;
use Ejunker\SharedFixtures\Contracts\CreatesFixture;

/**
 * Marks a static test property and describes how to build its value. Accepts
 * either form:
 *
 *   - a first-class callable — invoked with any extra args:
 *
 *         #[Fixture(SeekerFixture::make(...))]
 *         #[Fixture(SeekerFixture::make(...), ['clearance' => 2])]
 *
 *   - a scenario class-string — its static create() is called (extra args
 *     forwarded); use this for named, reusable fixtures:
 *
 *         #[Fixture(HiringScenario::class)]
 *
 * PHP attributes can't hold a closure body, so inline logic lives in the
 * referenced method/class; the fixtures() hook covers runtime construction.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Fixture
{
    /** @var array<array-key, mixed> */
    public array $args;

    /** @param (Closure(mixed...): mixed)|class-string<CreatesFixture> $factory */
    public function __construct(public Closure|string $factory, mixed ...$args)
    {
        $this->args = $args;
    }

    public function build(): mixed
    {
        if (is_string($this->factory)) {
            $class = $this->factory;

            return $class::create(...$this->args);
        }

        return ($this->factory)(...$this->args);
    }
}
