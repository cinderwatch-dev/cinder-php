<?php

use Cinderwatch\Support\DeployedRevision;

function git(string $directory, string $arguments): string
{
    return trim((string) shell_exec(sprintf(
        'git -C %s -c user.name=Cinder -c user.email=cinder@example.test -c commit.gpgsign=false %s 2>&1',
        escapeshellarg($directory),
        $arguments,
    )));
}

/**
 * Make a throwaway directory, or with $cleanUp remove every one made so far.
 */
function temporaryDirectory(bool $cleanUp = false): string
{
    static $directories = [];

    if ($cleanUp) {
        foreach ($directories as $directory) {
            shell_exec('rm -rf '.escapeshellarg($directory));
        }

        $directories = [];

        return '';
    }

    $directory = sys_get_temp_dir().'/cinder-git-'.bin2hex(random_bytes(6));
    mkdir($directory);

    return $directories[] = realpath($directory);
}

function gitCheckout(bool $withCommit = true): string
{
    $directory = temporaryDirectory();
    git($directory, 'init -q');
    git($directory, 'checkout -q -b main');

    if ($withCommit) {
        git($directory, "commit -q --allow-empty -m 'Ship checkout' -m 'More detail'");
    }

    return $directory;
}

afterEach(function () {
    temporaryDirectory(cleanUp: true);
});

it('reads the commit, branch and subject from the .git folder', function () {
    $checkout = gitCheckout();

    $revision = DeployedRevision::fromGit($checkout);

    expect($revision->revision)->toBe(git($checkout, 'rev-parse HEAD'))
        ->and($revision->branch)->toBe('main')
        ->and($revision->message)->toBe('Ship checkout');
})->group('revision');

it('leaves out the branch on a detached head', function () {
    $checkout = gitCheckout();
    git($checkout, 'checkout -q --detach');

    $revision = DeployedRevision::fromGit($checkout);

    expect($revision->revision)->toBe(git($checkout, 'rev-parse HEAD'))
        ->and($revision->branch)->toBeNull();
})->group('revision');

it('reads refs and leaves out the message once git has packed them', function () {
    $checkout = gitCheckout();
    git($checkout, 'gc -q');

    $revision = DeployedRevision::fromGit($checkout);

    expect($checkout.'/.git/refs/heads/main')->not->toBeFile()
        ->and($revision->revision)->toBe(git($checkout, 'rev-parse HEAD'))
        ->and($revision->branch)->toBe('main')
        ->and($revision->message)->toBeNull();
})->group('revision');

it('follows a worktree back to the main checkout', function () {
    $checkout = gitCheckout();
    $worktree = temporaryDirectory().'/feature';
    git($checkout, 'worktree add -q -b feature '.escapeshellarg($worktree));

    $revision = DeployedRevision::fromGit($worktree);

    expect($revision->revision)->toBe(git($checkout, 'rev-parse HEAD'))
        ->and($revision->branch)->toBe('feature')
        ->and($revision->message)->toBe('Ship checkout');
})->group('revision');

it('follows a relative gitdir pointer', function () {
    $checkout = gitCheckout();
    rename($checkout.'/.git', $checkout.'/.git-real');
    file_put_contents($checkout.'/.git', "gitdir: .git-real\n");

    expect(DeployedRevision::fromGit($checkout)->branch)->toBe('main');
})->group('revision');

it('finds the .git folder of a parent directory', function () {
    $checkout = gitCheckout();
    mkdir($checkout.'/apps/shop', recursive: true);

    expect(DeployedRevision::fromGit($checkout.'/apps/shop')->branch)->toBe('main');
})->group('revision');

it('stops searching a few directories up', function () {
    $checkout = gitCheckout();
    mkdir($checkout.'/a/b/c/d', recursive: true);

    expect(DeployedRevision::fromGit($checkout.'/a/b/c/d')->revision)->toBeNull()
        ->and(DeployedRevision::fromGit('/')->revision)->toBeNull();
})->group('revision');

it('finds nothing without a commit or with an unreadable head', function () {
    $empty = gitCheckout(withCommit: false);
    $broken = gitCheckout();
    file_put_contents($broken.'/.git/HEAD', "not a revision\n");

    expect(DeployedRevision::fromGit($empty))->toEqual(new DeployedRevision)
        ->and(DeployedRevision::fromGit($broken))->toEqual(new DeployedRevision)
        ->and(DeployedRevision::fromGit(temporaryDirectory()))->toEqual(new DeployedRevision);
})->group('revision');

it('leaves out the message when the commit object is unreadable', function () {
    $checkout = gitCheckout();
    $sha = git($checkout, 'rev-parse HEAD');
    $object = $checkout.'/.git/objects/'.substr($sha, 0, 2).'/'.substr($sha, 2);
    chmod($object, 0644);
    file_put_contents($object, 'not zlib');

    // gzuncompress() warns about the broken data before the error is suppressed.
    set_error_handler(fn () => true);
    $revision = DeployedRevision::fromGit($checkout);
    restore_error_handler();

    expect($revision->message)->toBeNull()
        ->and($revision->revision)->toBe($sha);
})->group('revision');

it('reads each directory once per process', function () {
    $checkout = gitCheckout();
    $first = DeployedRevision::fromGit($checkout);
    git($checkout, "commit -q --allow-empty -m 'Later'");

    expect(DeployedRevision::fromGit($checkout))->toBe($first);
})->group('revision');

it('falls back to the variables hosting platforms set', function (array $environment, array $expected) {
    $revision = DeployedRevision::fromEnvironment($environment);

    expect([$revision->revision, $revision->branch, $revision->message])->toBe($expected);
})->with([
    'github actions' => [['GITHUB_SHA' => 'abc123', 'GITHUB_REF_NAME' => 'main'], ['abc123', 'main', null]],
    'vercel' => [['VERCEL_GIT_COMMIT_SHA' => 'def456', 'VERCEL_GIT_COMMIT_REF' => 'preview', 'VERCEL_GIT_COMMIT_MESSAGE' => 'Fix nav'], ['def456', 'preview', 'Fix nav']],
    'render' => [['RENDER_GIT_COMMIT' => 'aaa111', 'RENDER_GIT_BRANCH' => 'prod'], ['aaa111', 'prod', null]],
    'heroku' => [['SOURCE_VERSION' => 'bbb222'], ['bbb222', null, null]],
    'empty values are skipped' => [['GITHUB_SHA' => ' ', 'SOURCE_VERSION' => 'ccc333'], ['ccc333', null, null]],
    'nothing set' => [[], [null, null, null]],
])->group('revision');

it('prefers a configured revision and never mixes it with a detected one', function () {
    $checkout = gitCheckout();

    $configured = DeployedRevision::resolve($checkout, 'deadbeef', '', 'Configured');
    $detected = DeployedRevision::resolve($checkout, revision: '', environment: ['SOURCE_VERSION' => 'ignored']);

    expect([$configured->revision, $configured->branch, $configured->message])->toBe(['deadbeef', null, 'Configured'])
        ->and($detected->revision)->toBe(git($checkout, 'rev-parse HEAD'));
})->group('revision');

it('uses the environment when there is no checkout, and nothing when detection is off', function () {
    $directory = temporaryDirectory();

    expect(DeployedRevision::resolve($directory, environment: ['SOURCE_VERSION' => 'bbb222'])->revision)->toBe('bbb222')
        ->and(DeployedRevision::resolve($directory))->toEqual(DeployedRevision::fromEnvironment(getenv()))
        ->and(DeployedRevision::resolve($directory, detect: false, environment: ['SOURCE_VERSION' => 'bbb222']))->toEqual(new DeployedRevision);
})->group('revision');
