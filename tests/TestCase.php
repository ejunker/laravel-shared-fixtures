<?php

declare(strict_types=1);

namespace Ejunker\SharedFixtures\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * Use the file-based sqlite database that `composer build` migrates, so the
     * schema lives OUTSIDE the per-test transaction the WithSharedFixtures trait
     * opens (migrations can't run inside it and roll back per test).
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', database_path('database.sqlite'));
    }
}
