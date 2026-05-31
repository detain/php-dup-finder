<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * A short-circuit validation pipeline: each step returns a reason string when it
 * should reject, or null when it passes. The first non-null reason short-circuits.
 */
final class StepwiseValidator
{
    /**
     * @template T
     * @param T                                  $subject
     * @param array<callable(T):?string>         $steps
     * @param callable(T):array<string,mixed>    $buildAcceptedPayload
     */
    public function validate(
        mixed $subject,
        array $steps,
        callable $buildAcceptedPayload,
    ): ValidationOutcome {
        foreach ($steps as $step) {
            $reason = $step($subject);
            if ($reason !== null) {
                return ValidationOutcome::reject($reason);
            }
        }

        return ValidationOutcome::accept($buildAcceptedPayload($subject));
    }
}

/* Example wiring for SignupValidator:
 *
 *  $validator->validate($req, [
 *      fn($r) => $r->email === ''                       ? 'email_missing'   : null,
 *      fn($r) => !$emailVerifier->looksValid($r->email) ? 'email_malformed' : null,
 *      fn($r) => $users->existsByEmail($r->email)       ? 'email_taken'     : null,
 *      fn($r) => !$countryGate->isAllowed($r->countryCode) ? 'country_blocked' : null,
 *  ], fn($r) => ['email' => $r->email, 'country' => $r->countryCode, 'plan' => $r->plan]);
 */
