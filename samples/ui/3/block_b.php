<?php
// modules/Blog/Views/BlogSidebar.php
namespace Blog\Views;

final class BlogSidebar
{
    public function render(array $topics, array $filters): string
    {
        $selectedTopic = $filters['category'] ?? '';
        $from          = $filters['from'] ?? '';
        $to            = $filters['to'] ?? '';
        $q             = $filters['q'] ?? '';

        $topicOptions = '';
        foreach ($topics as $slug => $label) {
            $sel = ((string) $selectedTopic === (string) $slug) ? ' selected' : '';
            $topicOptions .= sprintf('<option value="%s"%s>%s</option>',
                htmlspecialchars((string) $slug), $sel, htmlspecialchars($label));
        }

        return <<<HTML
<aside class="filter-sidebar">
    <h4>Filter Posts</h4>
    <form method="get" action="/blog" class="filters">
        <div class="field">
            <label for="f_category">Topic</label>
            <select id="f_category" name="category">
                <option value="">All topics</option>
                {$topicOptions}
            </select>
        </div>
        <div class="field range">
            <label>Published between</label>
            <input type="date" name="from" value="{$from}">
            <input type="date" name="to"   value="{$to}">
        </div>
        <div class="field">
            <label for="f_q">Search</label>
            <input id="f_q" type="search" name="q" value="{$q}" placeholder="Title or author">
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary">Apply</button>
            <a href="/blog" class="btn-link">Reset</a>
        </div>
    </form>
</aside>
HTML;
    }
}
