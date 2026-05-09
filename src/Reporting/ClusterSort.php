<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

/**
 * Sort specification for cluster output. Values come from the CLI
 * (`--sort=KEY[:asc|desc]`), `phpdup.json` (`sort`), or the TUI's
 * cycle-sort key.
 *
 * Each key maps to a numeric or string projection of a {@see Cluster}.
 * Ties are broken consistently in the order:
 *
 *   <primary key (per direction)> ▸ size DESC ▸ similarity DESC ▸ id ASC
 *
 * so the same input always produces the same final ordering.
 *
 * Supported keys (all available on the CLI as `--sort=<key>`):
 *
 *   impact       — duplicated-line count after holes-penalty (default)
 *   members      — number of duplicate blocks in the cluster
 *   block-size   — average AST node count per member
 *   lines        — total duplicated lines across all members
 *   similarity   — Jaccard or containment edge weight (1.0 for exact)
 *   confidence   — Ranker's [0..1] safety score
 *   name         — first member's qualified name (alphabetical)
 *   file         — first member's file path (alphabetical)
 *   id           — cluster id (alphabetical, mostly for stable diffs)
 */
final class ClusterSort
{
    public const KEY_IMPACT      = 'impact';
    public const KEY_MEMBERS     = 'members';
    public const KEY_BLOCK_SIZE  = 'block-size';
    public const KEY_LINES       = 'lines';
    public const KEY_SIMILARITY  = 'similarity';
    public const KEY_CONFIDENCE  = 'confidence';
    public const KEY_NAME        = 'name';
    public const KEY_FILE        = 'file';
    public const KEY_ID          = 'id';

    public const ALL_KEYS = [
        self::KEY_IMPACT,
        self::KEY_MEMBERS,
        self::KEY_BLOCK_SIZE,
        self::KEY_LINES,
        self::KEY_SIMILARITY,
        self::KEY_CONFIDENCE,
        self::KEY_NAME,
        self::KEY_FILE,
        self::KEY_ID,
    ];

    public const DIRECTION_ASC  = 'asc';
    public const DIRECTION_DESC = 'desc';

    public function __construct(
        public readonly string $key = self::KEY_IMPACT,
        public readonly string $direction = self::DIRECTION_DESC,
    ) {
        if (!in_array($key, self::ALL_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid sort key "%s". Use one of: %s.',
                $key, implode(', ', self::ALL_KEYS),
            ));
        }
        if (!in_array($direction, [self::DIRECTION_ASC, self::DIRECTION_DESC], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid sort direction "%s". Use "asc" or "desc".',
                $direction,
            ));
        }
    }

    /**
     * Parse a CLI / config string of the form `KEY` or `KEY:DIRECTION`.
     * Aliases recognised: `desc`/`descending`/`-`, `asc`/`ascending`/`+`.
     * Aliased keys: `size` → `members`, `count` → `members`.
     */
    public static function parse(string $spec): self
    {
        $spec = trim($spec);
        if ($spec === '') {
            return new self();
        }
        $direction = self::DIRECTION_DESC;
        $key = $spec;

        // Honor a leading "-" or "+" as a direction prefix.
        if (str_starts_with($key, '-')) {
            $direction = self::DIRECTION_DESC;
            $key = substr($key, 1);
        } elseif (str_starts_with($key, '+')) {
            $direction = self::DIRECTION_ASC;
            $key = substr($key, 1);
        }

        if (str_contains($key, ':')) {
            [$key, $dir] = explode(':', $key, 2);
            $dir = strtolower(trim($dir));
            $direction = match ($dir) {
                'asc', 'ascending', '+'   => self::DIRECTION_ASC,
                'desc', 'descending', '-' => self::DIRECTION_DESC,
                default => throw new \InvalidArgumentException(
                    "Invalid sort direction '{$dir}' in '{$spec}'. Use 'asc' or 'desc'."
                ),
            };
        }

        $key = strtolower(trim($key));
        $key = match ($key) {
            'size', 'count' => self::KEY_MEMBERS,
            'block_size'    => self::KEY_BLOCK_SIZE,
            default         => $key,
        };

        return new self($key, $direction);
    }

    /**
     * Apply this sort to a list of clusters. Pure — returns a new list.
     *
     * @param list<Cluster> $clusters
     * @return list<Cluster>
     */
    public function apply(array $clusters): array
    {
        $sign = $this->direction === self::DIRECTION_DESC ? -1 : 1;

        usort($clusters, function (Cluster $a, Cluster $b) use ($sign): int {
            $av = $this->project($a);
            $bv = $this->project($b);
            $primary = is_string($av)
                ? strcmp($av, (string)$bv)
                : ($av <=> $bv);
            if ($primary !== 0) {
                return $sign * $primary;
            }
            // Stable tie-break: higher member count wins, then higher
            // similarity, then alphabetical id (always ascending — keeps
            // diffs stable regardless of the user's primary direction).
            $tie = $b->size() <=> $a->size();
            if ($tie !== 0) return $tie;
            $tie = $b->similarity <=> $a->similarity;
            if ($tie !== 0) return $tie;
            return strcmp($a->id, $b->id);
        });

        return $clusters;
    }

    /**
     * Project a cluster onto a sortable scalar (or string) for the active key.
     */
    public function project(Cluster $c): int|float|string
    {
        return match ($this->key) {
            self::KEY_IMPACT      => $c->impact,
            self::KEY_MEMBERS     => $c->size(),
            self::KEY_BLOCK_SIZE  => $c->avgBlockSize(),
            self::KEY_LINES       => $c->totalLines(),
            self::KEY_SIMILARITY  => $c->similarity,
            self::KEY_CONFIDENCE  => $c->confidence,
            self::KEY_NAME        => $c->members ? strtolower($c->members[0]->qualifiedName()) : '',
            self::KEY_FILE        => $c->members ? $c->members[0]->file : '',
            self::KEY_ID          => $c->id,
            default               => 0,
        };
    }

    public function describe(): string
    {
        return $this->key . ':' . $this->direction;
    }
}
