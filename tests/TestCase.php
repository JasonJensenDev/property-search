<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$name);
    }
}
