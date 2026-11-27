<?php

namespace Cinderwatch\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Closure;
use JsonException;

/**
 * Checks the versions in composer.lock against the Packagist security advisories database.
 */
class ComposerAdvisoriesProbe implements Probe
{
    protected const ADVISORIES_URL = 'https://packagist.org/api/security-advisories/';

    /** @var Closure(list<string>): (array<string, list<array<string, mixed>>>|null) */
    private readonly Closure $lookup;

    /**
     * @param  (Closure(list<string>): (array<string, list<array<string, mixed>>>|null))|null  $lookup  Returns null when the lookup failed.
     */
    public function __construct(
        private readonly ?string $lockFile = null,
        ?Closure $lookup = null,
        private readonly int $timeoutSeconds = 15,
    ) {
        $this->lookup = $lookup ?? $this->queryPackagist(...);
    }

    public function id(): string
    {
        return 'composer-advisories';
    }

    public function title(): string
    {
        return 'Composer security advisories';
    }

    public function examine(): Observation
    {
        $lockFile = $this->lockFile ?? getcwd().DIRECTORY_SEPARATOR.'composer.lock';
        $installed = $this->installedVersions($lockFile);

        if ($installed === null) {
            return Observation::unhealthy('No lock file', "Could not read {$lockFile}.", ['lock_file' => $lockFile]);
        }

        if ($installed === []) {
            return Observation::healthy('No packages installed');
        }

        $advisories = ($this->lookup)(array_keys($installed));

        if ($advisories === null) {
            return Observation::degraded('Lookup failed', 'The security advisories database could not be queried.');
        }

        $vulnerable = [];

        foreach ($advisories as $package => $entries) {
            if (! isset($installed[$package])) {
                continue;
            }

            foreach ($entries as $entry) {
                $range = $entry['affectedVersions'] ?? '';

                if (! is_string($range) || $range === '' || $this->rangeContains($range, $installed[$package])) {
                    $vulnerable[$package] = $installed[$package];
                    break;
                }
            }
        }

        if ($vulnerable === []) {
            return Observation::healthy('No known advisories', ['packages_scanned' => count($installed)]);
        }

        $names = array_keys($vulnerable);

        return Observation::unhealthy(
            count($names).' vulnerable',
            'Installed versions with published advisories: '.implode(', ', $names).'.',
            ['packages' => $names, 'packages_scanned' => count($installed)],
        );
    }

    /**
     * @return array<string, string>|null package name => normalised version, or null when unreadable
     */
    private function installedVersions(string $lockFile): ?array
    {
        if (! is_readable($lockFile)) {
            return null;
        }

        try {
            $lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($lock)) {
            return null;
        }

        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                    $versions[$package['name']] = $this->normaliseVersion($package['version']);
                }
            }
        }

        return $versions;
    }

    /**
     * Composer-style range: alternatives separated by "|" (or "||"), each a set
     * of comparator constraints separated by commas or spaces that must all hold.
     */
    private function rangeContains(string $range, string $version): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($range)) ?: [] as $alternative) {
            $constraints = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($constraints === []) {
                continue;
            }

            $satisfiesAll = true;

            foreach ($constraints as $constraint) {
                if (! $this->satisfies($version, $constraint)) {
                    $satisfiesAll = false;
                    break;
                }
            }

            if ($satisfiesAll) {
                return true;
            }
        }

        return false;
    }

    private function satisfies(string $version, string $constraint): bool
    {
        preg_match('/^(>=|<=|<>|!=|==|>|<|=)?(.*)$/', $constraint, $parts);

        return version_compare($version, $this->normaliseVersion($parts[2]), $parts[1] ?: '==');
    }

    private function normaliseVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /**
     * @param  list<string>  $packages
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function queryPackagist(array $packages): ?array
    {
        $body = http_build_query(['packages' => $packages]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(static::ADVISORIES_URL, false, $context);

        if ($response === false) {
            return null;
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['advisories'] ?? null)) {
            return null;
        }

        /** @var array<string, list<array<string, mixed>>> $advisories */
        $advisories = $decoded['advisories'];

        return $advisories;
    }
}
