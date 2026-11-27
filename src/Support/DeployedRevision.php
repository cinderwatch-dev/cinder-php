<?php

namespace Cinderwatch\Support;

/**
 * The commit that is running, read straight from the .git folder (no `git` binary
 * needed), or from the variables common hosting and CI platforms set.
 */
class DeployedRevision
{
    /** How many parent folders to search for a .git folder, for apps inside a monorepo. */
    private const SEARCH_DEPTH = 3;

    /**
     * Commit, branch and message variables, in the order they are checked.
     *
     * @var list<array{0: string, 1: string|null, 2: string|null}>
     */
    private const PLATFORM_VARIABLES = [
        ['GITHUB_SHA', 'GITHUB_REF_NAME', null],
        ['VERCEL_GIT_COMMIT_SHA', 'VERCEL_GIT_COMMIT_REF', 'VERCEL_GIT_COMMIT_MESSAGE'],
        ['RENDER_GIT_COMMIT', 'RENDER_GIT_BRANCH', null],
        ['SOURCE_VERSION', null, null],
    ];

    /** @var array<string, self> */
    private static array $fromGit = [];

    public function __construct(
        public readonly ?string $revision = null,
        public readonly ?string $branch = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * Use the given revision when one is set, otherwise detect it. The branch and
     * message are only taken from the same source as the revision, so they always match.
     *
     * @param  array<string, mixed>|null  $environment  Defaults to getenv().
     */
    public static function resolve(
        string $directory,
        ?string $revision = null,
        ?string $branch = null,
        ?string $message = null,
        bool $detect = true,
        ?array $environment = null,
    ): self {
        if ($revision !== null && $revision !== '') {
            return new self($revision, self::filled($branch), self::filled($message));
        }

        if (! $detect) {
            return new self;
        }

        $detected = self::fromGit($directory);

        return $detected->revision !== null ? $detected : self::fromEnvironment($environment ?? getenv());
    }

    public static function fromGit(string $directory): self
    {
        return self::$fromGit[$directory] ??= self::readGit($directory);
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        foreach (self::PLATFORM_VARIABLES as [$revision, $branch, $message]) {
            $value = self::filled($environment[$revision] ?? null);

            if ($value !== null) {
                return new self(
                    $value,
                    $branch === null ? null : self::filled($environment[$branch] ?? null),
                    $message === null ? null : self::filled($environment[$message] ?? null),
                );
            }
        }

        return new self;
    }

    private static function readGit(string $directory): self
    {
        $gitDirectory = self::findGitDirectory($directory);

        if ($gitDirectory === null) {
            return new self;
        }

        $head = self::readFile($gitDirectory.'/HEAD');
        $common = self::commonDirectory($gitDirectory);

        if ($head !== null && str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $revision = self::readFile($common.'/'.$ref) ?? self::packedRef($common, $ref);
            $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
        } else {
            $revision = $head;
            $branch = null;
        }

        if ($revision === null || ! preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $revision)) {
            return new self;
        }

        return new self($revision, $branch, self::commitMessage($common, $revision));
    }

    private static function findGitDirectory(string $directory): ?string
    {
        for ($depth = 0; $depth <= self::SEARCH_DEPTH; $depth++) {
            $candidate = $directory.'/.git';

            if (is_dir($candidate)) {
                return $candidate;
            }

            // Worktrees and submodules have a .git file pointing at the real folder.
            $pointer = self::readFile($candidate);

            if ($pointer !== null && str_starts_with($pointer, 'gitdir: ')) {
                return self::absolute(substr($pointer, 8), $directory);
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }

    /**
     * A worktree keeps its own HEAD, but shares refs and objects with the main checkout.
     */
    private static function commonDirectory(string $gitDirectory): string
    {
        $common = self::readFile($gitDirectory.'/commondir');

        return $common === null ? $gitDirectory : self::absolute($common, $gitDirectory);
    }

    private static function packedRef(string $gitDirectory, string $ref): ?string
    {
        $packed = self::readFile($gitDirectory.'/packed-refs') ?? '';

        foreach (explode("\n", $packed) as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return substr($line, 0, strpos($line, ' '));
            }
        }

        return null;
    }

    /**
     * Only commits stored as loose objects can be read cheaply. Once git packs them
     * (after `git gc` or a fresh clone) the message is left out.
     */
    private static function commitMessage(string $gitDirectory, string $revision): ?string
    {
        $object = $gitDirectory.'/objects/'.substr($revision, 0, 2).'/'.substr($revision, 2);
        $compressed = is_file($object) ? file_get_contents($object) : false;
        $commit = is_string($compressed) && function_exists('gzuncompress') ? @gzuncompress($compressed) : false;

        if (! is_string($commit) || ! str_contains($commit, "\n\n")) {
            return null;
        }

        $body = substr($commit, strpos($commit, "\n\n") + 2);

        return self::filled(strtok($body, "\n"));
    }

    private static function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return self::filled(file_get_contents($path));
    }

    private static function absolute(string $path, string $relativeTo): string
    {
        return str_starts_with($path, '/') ? $path : $relativeTo.'/'.$path;
    }

    private static function filled(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
