<?php

declare(strict_types=1);

namespace Acme\Dashboard\Widgets;

use Acme\Dashboard\Model\Customer;
use Acme\Dashboard\View\WidgetBag;
use Acme\Dashboard\View\UpgradePrompt;

final class DashboardWidgetBuilder
{
    public function build(Customer $customer): WidgetBag
    {
        $bag = new WidgetBag();

        if ($customer->subscription()->isCurrentlyActive()) {
            $bag->add($this->usageWidget($customer));
            $bag->add($this->billingWidget($customer));
        } else {
            $bag->add(new UpgradePrompt($customer->id()));
        }

        return $bag;
    }

    private function usageWidget(Customer $c): object
    {
        return (object) ['type' => 'usage', 'customer' => $c->id()];
    }

    private function billingWidget(Customer $c): object
    {
        return (object) ['type' => 'billing', 'customer' => $c->id()];
    }
}
