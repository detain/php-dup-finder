<?php
declare(strict_types=1);

namespace Acme\Forum\Sanitize;

use HTMLPurifier;
use HTMLPurifier_Config;

final class CommentSanitizer
{
    private const ALLOWED_TAGS = ['a', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'code', 'pre', 'blockquote'];
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.AllowedElements', self::ALLOWED_TAGS);
        $config->set('HTML.AllowedAttributes', ['a.href', 'a.title']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.DefinitionImpl', null);
        $this->purifier = new HTMLPurifier($config);
    }

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $clean = $this->purifier->purify($html);
        return trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
    }
}
