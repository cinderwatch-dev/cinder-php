<?php

use Cinderwatch\Errors\CallSite;
use Cinderwatch\Errors\StackWalker;

function thrownAt(string $file, int $line): RuntimeException
{
    $exception = new RuntimeException('boom');

    (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, $file);
    (new ReflectionProperty(Exception::class, 'line'))->setValue($exception, $line);

    return $exception;
}

it('records calls made by php itself as internal frames', function () {
    try {
        array_map(fn () => throw new RuntimeException('boom'), [1]);
    } catch (RuntimeException $exception) {
        $sites = (new StackWalker)->walk($exception);
    }

    $internal = array_values(array_filter($sites, fn (CallSite $site): bool => $site->path === '[internal function]'))[0];

    expect($sites[0]->function)->toContain('{closure')
        ->and($internal->line)->toBe(0)
        ->and($internal->function)->toBe('array_map')
        ->and($internal->excerpt)->toBe([])
        ->and($internal->inApplication)->toBeFalse()
        ->and(end($sites)->function)->toBe('{main}');
})->group('errors');

it('marks vendor frames and frames outside the application root as not in the app', function () {
    $exception = new RuntimeException('boom');

    $inside = (new StackWalker(dirname(__DIR__)))->walk($exception);
    $outside = (new StackWalker('/somewhere/else'))->walk($exception);
    $vendor = array_values(array_filter($inside, fn (CallSite $site): bool => str_contains($site->path, '/vendor/')));

    expect($inside[0]->inApplication)->toBeTrue()
        ->and($outside[0]->inApplication)->toBeFalse()
        ->and($vendor)->not->toBeEmpty()
        ->and($vendor[0]->inApplication)->toBeFalse();
})->group('errors');

it('stops the source excerpt at the end of the file', function () {
    $site = (new StackWalker)->walk(thrownAt(__DIR__.'/../Fixtures/short-source.txt', 3))[0];

    expect($site->excerpt)->toBe([1 => 'first line', 2 => 'second line', 3 => 'third line']);
})->group('errors');

it('takes the configured number of context lines', function () {
    $site = (new StackWalker(contextLines: 1))->walk(thrownAt(__DIR__.'/../Fixtures/short-source.txt', 2))[0];

    expect($site->excerpt)->toBe([1 => 'first line', 2 => 'second line', 3 => 'third line'])
        ->and((new StackWalker(contextLines: 0))->walk(thrownAt(__DIR__.'/../Fixtures/short-source.txt', 2))[0]->excerpt)->toBe([2 => 'second line']);
})->group('errors');

it('has no excerpt for files that do not exist', function () {
    expect((new StackWalker)->walk(thrownAt('/does/not/exist.php', 3))[0]->excerpt)->toBe([]);
})->group('errors');
