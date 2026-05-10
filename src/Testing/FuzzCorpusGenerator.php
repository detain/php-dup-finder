<?php
declare(strict_types=1);

namespace Phpdup\Testing;

/**
 * Generates synthetic PHP corpora with controlled duplication for
 * detection-rate fuzz testing.
 *
 * The generator produces N "templates" (parametrised function
 * skeletons), instantiates each K times with different hole-value
 * sets, and writes one file per instantiation. The intended
 * duplication topology is recoverable from the generator's plan,
 * so a fuzz test can compare phpdup's reported clusters against
 * the ground truth.
 *
 * Templates are intentionally simple — the goal is detection-rate
 * benchmarking, not realistic application code.
 */
final class FuzzCorpusGenerator
{
    public function __construct(
        private readonly int $seed = 42,
    ) {
    }

    /**
     * @param array<string, list<array<string,string>>> $plan
     *   template-name → list of hole-value rows (one row per
     *   instantiation; each row maps hole-name → literal repr).
     * @return list<array{file:string,template:string,row:int}>
     */
    public function generate(string $dir, array $plan): array
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        // Seeded mt_rand keeps generation deterministic for fuzz tests.
        // We explicitly avoid \Random\Randomizer because that class is
        // PHP 8.2+ and this codebase's floor is 8.1.
        mt_srand($this->seed);
        $manifest = [];
        foreach ($plan as $template => $rows) {
            foreach ($rows as $idx => $holes) {
                $code = $this->renderTemplate($template, $holes, $idx);
                $file = sprintf('%s/%s_%03d.php', $dir, $template, $idx);
                file_put_contents($file, $code);
                $manifest[] = ['file' => $file, 'template' => $template, 'row' => $idx];
            }
        }
        return $manifest;
    }

    /** @param array<string,string> $holes */
    private function renderTemplate(string $template, array $holes, int $idx): string
    {
        $value     = $holes['value']     ?? (string)mt_rand(1, 99);
        $threshold = $holes['threshold'] ?? (string)mt_rand(1, 99);
        $callee    = $holes['callee']    ?? 'doThing';
        $namespace = "Generated\\{$template}_{$idx}";

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

class T_{$template}
{
    public function process(\$item): mixed
    {
        if (\$item->score > {$threshold}) {
            return \$this->{$callee}({$value}, \$item);
        }
        \$result = [];
        foreach (\$item->children as \$child) {
            \$result[] = \$this->{$callee}({$value}, \$child);
        }
        return \$result;
    }
}

PHP;
    }
}
