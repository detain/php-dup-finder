<?php
declare(strict_types=1);

namespace Acme\Forms\Signup;

final class SignupForm
{
    /**
     * Validate and normalize a submitted phone field.
     *
     * @param string $raw user-submitted phone string
     * @return array{ok:bool,value:string,reason:string}
     */
    public function validatePhone(string $raw): array
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        $len = strlen($digits);
        if ($len < 10) {
            return ['ok' => false, 'value' => '', 'reason' => 'too_short'];
        }
        if ($len > 15) {
            return ['ok' => false, 'value' => '', 'reason' => 'too_long'];
        }
        $country = $len === 10 ? '1' : substr($digits, 0, $len - 10);
        $area = substr($digits, $len - 10, 3);
        $prefix = substr($digits, $len - 7, 3);
        $line = substr($digits, $len - 4, 4);
        $e164 = '+' . $country . $area . $prefix . $line;
        return ['ok' => true, 'value' => $e164, 'reason' => ''];
    }

    public function submit(array $payload): void
    {
        // persist signup
    }
}
