<?php
declare(strict_types=1);

namespace Acme\Signup;

final class SignupValidator
{
    public function __construct(
        private UserRepository $users,
        private EmailVerifier $emailVerifier,
        private CountryGate $countryGate,
    ) {
    }

    public function validate(SignupRequest $req): ValidationOutcome
    {
        if ($req->email === '') {
            return ValidationOutcome::reject('email_missing');
        } else {
            if (!$this->emailVerifier->looksValid($req->email)) {
                return ValidationOutcome::reject('email_malformed');
            } else {
                if ($this->users->existsByEmail($req->email)) {
                    return ValidationOutcome::reject('email_taken');
                } else {
                    if (!$this->countryGate->isAllowed($req->countryCode)) {
                        return ValidationOutcome::reject('country_blocked');
                    } else {
                        return ValidationOutcome::accept([
                            'email'   => $req->email,
                            'country' => $req->countryCode,
                            'plan'    => $req->plan,
                        ]);
                    }
                }
            }
        }
    }
}
