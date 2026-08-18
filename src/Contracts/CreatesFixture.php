<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Contracts;

/**
 * A named fixture referenced by #[Fixture(SomeClass::class)]. create() builds
 * the data and returns it — either a single model or an object grouping
 * several. Any extra parameters must be optional (or variadic) so the signature
 * stays compatible.
 */
interface CreatesFixture
{
    public static function create(): mixed;
}
