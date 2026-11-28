<?php
declare(strict_types=1);

// tests/Fixtures/drift/options/VerifyOn.php — item 4.1 option-divergence
// fixture: same call shape, security-relevant option flag set to true
// here and false in the sibling (doc §4.1 TLS-verify pattern).

final class VerifyOn
{
    public function handle($url)
    {
        $ch = init($url);
        configure($ch, CURLOPT_SSL_VERIFYPEER, true);
        apply($ch);
    }
}
