<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic two-tier match classifier:
 *   primary match → label; secondary match → flag; side-effect → return DTO.
 */
final class TieredClassifier
{
    /**
     * @template I
     * @template C   primary classification value (string label)
     * @template R   returned DTO
     * @param I                                       $input
     * @param array<int, array{0:callable(I):bool,1:string}> $primaryArms   ordered guards
     * @param string                                  $primaryDefault
     * @param array<string, bool>                     $escalationMap
     * @param callable(string):void                   $sideEffect
     * @param callable(I,string,bool):R               $build
     * @return R
     */
    public function classify(
        mixed $input,
        array $primaryArms,
        string $primaryDefault,
        array $escalationMap,
        callable $sideEffect,
        callable $build,
    ): mixed {
        $label = $primaryDefault;
        foreach ($primaryArms as [$guard, $arm]) {
            if ($guard($input)) {
                $label = $arm;
                break;
            }
        }

        $flag = $escalationMap[$label] ?? false;
        $sideEffect($label);

        return $build($input, $label, $flag);
    }
}
