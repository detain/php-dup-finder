<?php
declare(strict_types=1);

namespace Acme\Forum\Sanitize;

use HTMLPurifier;
use HTMLPurifier_Config;

final class HtmlPurifierSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.AllowedElements', [
            'a', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'code', 'pre', 'blockquote',
        ]);
        $config->set('HTML.AllowedAttributes', ['a.href', 'a.title']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.TidyLevel', 'medium');
        $this->purifier = new HTMLPurifier($config);
    }

    public function purify(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $clean = $this->purifier->purify($html);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/>\s+</', '><', $clean) ?? $clean;
        return trim($clean);
    }
}
