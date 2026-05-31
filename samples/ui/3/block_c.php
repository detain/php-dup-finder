<?php
// modules/CRM/Views/CustomerSidebar.php
namespace CRM\Views;

final class CustomerSidebar
{
    public function render(array $segments, array $filters): string
    {
        $selectedSegment = $filters['category'] ?? '';
        $from            = $filters['from'] ?? '';
        $to              = $filters['to'] ?? '';
        $q               = $filters['q'] ?? '';

        $segOptions = '';
        foreach ($segments as $code => $label) {
            $sel = ((string) $selectedSegment === (string) $code) ? ' selected' : '';
            $segOptions .= sprintf('<option value="%s"%s>%s</option>',
                htmlspecialchars((string) $code), $sel, htmlspecialchars($label));
        }

        return <<<HTML
<aside class="filter-sidebar">
    <h4>Filter Customers</h4>
    <form method="get" action="/customers" class="filters">
        <div class="field">
            <label for="f_category">Segment</label>
            <select id="f_category" name="category">
                <option value="">All segments</option>
                {$segOptions}
            </select>
        </div>
        <div class="field range">
            <label>Joined between</label>
            <input type="date" name="from" value="{$from}">
            <input type="date" name="to"   value="{$to}">
        </div>
        <div class="field">
            <label for="f_q">Search</label>
            <input id="f_q" type="search" name="q" value="{$q}" placeholder="Name or email">
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary">Apply</button>
            <a href="/customers" class="btn-link">Reset</a>
        </div>
    </form>
</aside>
HTML;
    }
}
