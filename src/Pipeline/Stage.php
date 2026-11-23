<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

enum Stage: string
{
    /**
     * The five canonical phases, in pipeline order. Each phase appears as a
     * yield value from Pipeline::iter() — once-S72 made even Reporting
     * cooperative, so every frame the live driver repaints between now
     * includes the report tail, not just the analysis before it.
     */
    case Scanning      = 'scanning';
    case Preprocessing = 'preprocessing';
    case Clustering    = 'clustering';
    case Refactoring   = 'refactoring';
    case Reporting     = 'reporting';

    public function label(): string
    {
        return match ($this) {
            self::Scanning      => 'Scanning',
            self::Preprocessing => 'Preprocessing',
            self::Clustering   => 'Clustering',
            self::Refactoring  => 'Refactoring',
            self::Reporting    => 'Reporting',
        };
    }

    public function index(): int
    {
        return match ($this) {
            self::Scanning      => 0,
            self::Preprocessing => 1,
            self::Clustering    => 2,
            self::Refactoring   => 3,
            self::Reporting     => 4,
        };
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Scanning,
            self::Preprocessing,
            self::Clustering,
            self::Refactoring,
            self::Reporting,
        ];
    }
}
