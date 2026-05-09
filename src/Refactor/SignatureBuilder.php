<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;

/**
 * Renders a PHP-style function signature for a Cluster's suggested
 * abstraction.
 *
 *   function clusterMembersFnName(
 *       <synthesized parameters>,
 *   ): mixed
 *
 * The function name is derived from the most common base name across
 * cluster members, suffixed with "By" + the holes' role names if a
 * sensible verb-by-pattern emerges (e.g. notifyByThreshold). Falls
 * back to the plain common name.
 */
final class SignatureBuilder
{
    public function buildSignature(Cluster $cluster): void
    {
        $name = $this->suggestFunctionName($cluster);
        $params = [];
        foreach ($cluster->holes as $hole) {
            $type = $this->displayType($hole->inferredType);
            $params[] = sprintf('    %s%s%s,', $type ? $type . ' ' : '', $hole->suggestedName, '');
        }
        $body = $params ? "\n" . implode("\n", $params) . "\n" : '';
        $cluster->signature = "function {$name}({$body}): mixed";
    }

    private function displayType(string $t): string
    {
        return match ($t) {
            'class-string' => 'class-string',
            default => $t,
        };
    }

    private function suggestFunctionName(Cluster $cluster): string
    {
        $names = [];
        foreach ($cluster->members as $m) {
            if ($m->name) {
                $names[] = $m->name;
            }
        }
        if (!$names) {
            return 'extractedFunction';
        }
        $common = $this->longestCommonPrefix($names);
        if (strlen($common) < 3) {
            $common = $names[0];
        }
        $common = rtrim($common, '_');

        // If holes look like role-by-threshold, append "By<RoleType>"
        $byParts = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->kind === 'literal' && in_array($hole->inferredType, ['int', 'float'], true)) {
                $byParts[] = 'Threshold';
            } elseif ($hole->kind === 'name' || $hole->kind === 'call') {
                $byParts[] = 'Strategy';
            }
        }
        if ($byParts) {
            $byParts = array_values(array_unique($byParts));
            return $common . 'By' . implode('And', $byParts);
        }
        return $common;
    }

    /** @param list<string> $names */
    private function longestCommonPrefix(array $names): string
    {
        if (!$names) return '';
        $prefix = $names[0];
        foreach ($names as $n) {
            $i = 0;
            $max = min(strlen($prefix), strlen($n));
            while ($i < $max && $prefix[$i] === $n[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') return '';
        }
        return $prefix;
    }
}
