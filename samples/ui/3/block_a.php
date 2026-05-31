<?php
// modules/Shop/Views/ProductSidebar.php
namespace Shop\Views;

final class ProductSidebar
{
    public function render(array $categories, array $filters): string
    {
        $selectedCat = $filters['category'] ?? '';
        $from        = $filters['from'] ?? '';
        $to          = $filters['to'] ?? '';
        $q           = $filters['q'] ?? '';

        $catOptions = '';
        foreach ($categories as $id => $name) {
            $sel = ((string) $selectedCat === (string) $id) ? ' selected' : '';
            $catOptions .= sprintf('<option value="%s"%s>%s</option>',
                htmlspecialchars((string) $id), $sel, htmlspecialchars($name));
        }

        return <<<HTML
<aside class="filter-sidebar">
    <h4>Filter Products</h4>
    <form method="get" action="/products" class="filters">
        <div class="field">
            <label for="f_category">Category</label>
            <select id="f_category" name="category">
                <option value="">All categories</option>
                {$catOptions}
            </select>
        </div>
        <div class="field range">
            <label>Listed between</label>
            <input type="date" name="from" value="{$from}">
            <input type="date" name="to"   value="{$to}">
        </div>
        <div class="field">
            <label for="f_q">Search</label>
            <input id="f_q" type="search" name="q" value="{$q}" placeholder="Name or SKU">
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary">Apply</button>
            <a href="/products" class="btn-link">Reset</a>
        </div>
    </form>
</aside>
HTML;
    }
}
