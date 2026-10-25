<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Load a binary test fixture (tests/fixtures/fit/*) by file name. */
function fixture(string $name): string
{
    $path = __DIR__.'/fixtures/fit/'.$name;

    if (! is_file($path)) {
        throw new RuntimeException("missing fixture: {$name}");
    }

    return (string) file_get_contents($path);
}

/** @return array<string, string> all compact FIT fixture files */
function fitFixtures(): array
{
    $fixtures = [];

    foreach (glob(__DIR__.'/fixtures/fit/*.fit') as $path) {
        $fixtures[basename($path)] = (string) file_get_contents($path);
    }

    return $fixtures;
}
