<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Tests\Support\Fixtures;

abstract class TestCase extends BaseTestCase
{
    public static function tearDownAfterClass(): void
    {
        Fixtures::cleanup();
        parent::tearDownAfterClass();
    }
}
