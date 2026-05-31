<?php

declare(strict_types=1);

namespace Acme\View\Helpers;

use Acme\View\Model\User;
use Acme\View\Component\BetaBanner;

final class DashboardViewHelper
{
    public function renderBetaSection(User $user): string
    {
        if ($user->canSeeBetaFeatures()) {
            $banner = new BetaBanner($user);
            return $banner->render()
                . "<section class='beta'><h2>AI Summaries</h2></section>";
        }

        return "<section class='beta-locked'>"
            . "<a href='/upgrade'>Upgrade to unlock beta</a>"
            . "</section>";
    }
}
