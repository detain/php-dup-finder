<?php
declare(strict_types=1);

// tests/Fixtures/drift/options_uniform/VerifyOnTwo.php — item 4.1
// NEGATIVE CONTROL for the option arm: both members set the identical
// flag, the hole never forms.

final class VerifyOnTwo
{
    public function handle($url)
    {
        $ch = init($url);
        configure($ch, CURLOPT_SSL_VERIFYPEER, true);
        apply($ch);
    }
}
