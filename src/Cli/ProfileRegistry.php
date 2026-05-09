<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * Loads profile JSON from the bundled `profiles/` directory.
 *
 * Profiles ship as part of the package; consumers can also bundle
 * their own by passing a custom $rootDir. Each profile file looks
 * like a phpdup.json fragment and is folded into the user's
 * config as a low-priority override (explicit CLI flags + the
 * user's --config still win).
 */
final class ProfileRegistry
{
    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    public static function bundled(): self
    {
        return new self(dirname(__DIR__, 2) . '/profiles');
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException when the profile JSON is missing or invalid
     */
    public function load(string $name): array
    {
        $file = $this->rootDir . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($file)) {
            throw new \RuntimeException("Unknown profile '$name' (looked at $file)");
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Profile '$name' is not valid JSON: $file");
        }
        // Drop the human-readable description so it doesn't leak into
        // the validator (which rejects unknown keys).
        unset($data['_description']);
        return $data;
    }

    /** @return list<string> */
    public function listAvailable(): array
    {
        if (!is_dir($this->rootDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->rootDir) ?: [] as $entry) {
            if (str_ends_with($entry, '.json')) {
                $out[] = substr($entry, 0, -5);
            }
        }
        sort($out);
        return $out;
    }
}
