<?php

declare(strict_types=1);

namespace Acme\Hr\Applicants;

use Acme\Hr\Applicants\Dto\ApplicantProfile;
use Acme\Hr\Applicants\Dto\RankedApplicant;

final class CandidateRanker
{
    /**
     * @param ApplicantProfile[] $profiles
     * @return RankedApplicant[]
     */
    public function rank(array $profiles): array
    {
        $weights = [
            'years_experience' => 0.35,
            'matched_skills'   => 0.30,
            'referral_score'   => 0.15,
            'education_score'  => 0.10,
            'culture_fit'      => 0.10,
        ];

        $ranked = [];
        foreach ($profiles as $profile) {
            $features = [
                'years_experience' => min($profile->yearsExperience / 10.0, 1.0),
                'matched_skills'   => $profile->matchedSkills / max($profile->requiredSkills, 1),
                'referral_score'   => $profile->referralScore,
                'education_score'  => $profile->educationScore,
                'culture_fit'      => $profile->cultureFit,
            ];

            $total = 0.0;
            foreach ($features as $key => $value) {
                $total += $value * ($weights[$key] ?? 0.0);
            }

            $ranked[] = new RankedApplicant($profile->id, round($total, 4), $features);
        }

        usort(
            $ranked,
            static fn(RankedApplicant $a, RankedApplicant $b): int => $b->score <=> $a->score,
        );

        return $ranked;
    }
}
