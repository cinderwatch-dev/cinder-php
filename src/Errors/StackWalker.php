<?php

namespace Cinderwatch\Errors;

use SplFileObject;
use Throwable;

/**
 * PHP's trace puts the caller's file and line next to the callee's name, so each
 * location is paired with the function named by the next trace entry instead.
 */
class StackWalker
{
    private const INTERNAL_PATH = '[internal function]';

    private const TOP_LEVEL = '{main}';

    private readonly string $vendorSegment;

    public function __construct(
        private readonly ?string $applicationRoot = null,
        private readonly int $contextLines = 5,
    ) {
        $this->vendorSegment = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;
    }

    /**
     * @return list<CallSite>
     */
    public function walk(Throwable $throwable): array
    {
        $entries = $throwable->getTrace();

        $locations = [[$throwable->getFile(), $throwable->getLine()]];

        foreach ($entries as $entry) {
            $locations[] = [
                is_string($entry['file'] ?? null) ? $entry['file'] : self::INTERNAL_PATH,
                is_int($entry['line'] ?? null) ? $entry['line'] : 0,
            ];
        }

        $sites = [];

        foreach ($locations as $depth => [$path, $line]) {
            $owner = $entries[$depth] ?? null;

            $sites[] = new CallSite(
                path: $path,
                line: $line,
                function: $owner['function'] ?? self::TOP_LEVEL,
                className: $owner['class'] ?? null,
                excerpt: $this->excerpt($path, $line),
                inApplication: $this->belongsToApplication($path),
            );
        }

        return $sites;
    }

    /**
     * @return array<int, string>
     */
    private function excerpt(string $path, int $line): array
    {
        if ($line < 1 || $path === self::INTERNAL_PATH || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $file = new SplFileObject($path);
        $first = max(1, $line - $this->contextLines);
        $last = $line + $this->contextLines;
        $lines = [];

        for ($file->seek($first - 1); $file->valid() && $file->key() < $last; $file->next()) {
            $text = $file->current();

            // A trailing newline leaves an empty "line" after the last one.
            if (is_string($text) && ! ($text === '' && $file->eof())) {
                $lines[$file->key() + 1] = rtrim($text, "\r\n");
            }
        }

        return $lines;
    }

    private function belongsToApplication(string $path): bool
    {
        if ($path === self::INTERNAL_PATH || str_contains($path, $this->vendorSegment)) {
            return false;
        }

        if ($this->applicationRoot === null || $this->applicationRoot === '') {
            return true;
        }

        return str_starts_with($path, $this->applicationRoot);
    }
}
