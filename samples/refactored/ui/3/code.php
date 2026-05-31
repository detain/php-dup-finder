<?php
// app/View/FilterSidebar.php
namespace App\View;

final class FilterSidebar
{
    /**
     * @param array<string,string>           $options  value => label
     * @param array{category?:string,from?:string,to?:string,q?:string} $filters
     */
    public static function render(
        string $heading,
        string $action,
        string $selectLabel,
        string $allOptionLabel,
        string $rangeLabel,
        string $searchPlaceholder,
        array $options,
        array $filters
    ): string {
        $selected = $filters['category'] ?? '';
        $from     = $filters['from'] ?? '';
        $to       = $filters['to']   ?? '';
        $q        = $filters['q']    ?? '';

        $opts = '';
        foreach ($options as $value => $label) {
            $sel = ((string) $selected === (string) $value) ? ' selected' : '';
            $opts .= sprintf('<option value="%s"%s>%s</option>',
                htmlspecialchars((string) $value), $sel, htmlspecialchars($label));
        }

        $escAction      = htmlspecialchars($action);
        $escHeading     = htmlspecialchars($heading);
        $escSelectLabel = htmlspecialchars($selectLabel);
        $escAllLabel    = htmlspecialchars($allOptionLabel);
        $escRangeLabel  = htmlspecialchars($rangeLabel);
        $escPlaceholder = htmlspecialchars($searchPlaceholder);

        return <<<HTML
<aside class="filter-sidebar">
    <h4>{$escHeading}</h4>
    <form method="get" action="{$escAction}" class="filters">
        <div class="field">
            <label for="f_category">{$escSelectLabel}</label>
            <select id="f_category" name="category">
                <option value="">{$escAllLabel}</option>
                {$opts}
            </select>
        </div>
        <div class="field range">
            <label>{$escRangeLabel}</label>
            <input type="date" name="from" value="{$from}">
            <input type="date" name="to"   value="{$to}">
        </div>
        <div class="field">
            <label for="f_q">Search</label>
            <input id="f_q" type="search" name="q" value="{$q}" placeholder="{$escPlaceholder}">
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary">Apply</button>
            <a href="{$escAction}" class="btn-link">Reset</a>
        </div>
    </form>
</aside>
HTML;
    }
}
