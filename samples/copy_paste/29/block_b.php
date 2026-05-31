<?php

declare(strict_types=1);

namespace App\Xml\Processing;

use App\Exceptions\XmlEncodingException;

final class SoapXmlEncoder
{
    private const ENTITY_MAP = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        '\'' => '&apos;',
    ];

    private const INVALID_XML_CHARS = [
        "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
        "\x08", "\x0B", "\x0C", "\x0E", "\x0F", "\x10", "\x11", "\x12",
        "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A",
        "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
    ];

    public function encodeNodeValue(string $value): string
    {
        $value = $this->stripInvalidChars($value);
        $value = $this->replaceEntities($value);

        return $value;
    }

    public function encodeAttributeValue(string $value): string
    {
        $value = $this->stripInvalidChars($value);
        $value = $this->replaceEntities($value);
        $value = $this->trimInternalWhitespace($value);

        return $value;
    }

    public function encodeCharacterData(string $data): string
    {
        $data = $this->stripInvalidChars($data);

        if (preg_match('/]]>/', $data)) {
            throw new XmlEncodingException('CDATA cannot contain "]]>"');
        }

        return $this->replaceEntities($data);
    }

    public function encodeXmlComment(string $comment): string
    {
        $comment = $this->stripInvalidChars($comment);

        if (preg_match('/--/', $comment)) {
            throw new XmlEncodingException('XML comment cannot contain "--"');
        }

        if (preg_match('/-$/', $comment)) {
            throw new XmlEncodingException('XML comment cannot end with "-"');
        }

        return $comment;
    }

    public function encodeTagName(string $name): string
    {
        $this->checkTagNameValidity($name);

        return $this->sanitizeNcName($name);
    }

    public function encodePropertyName(string $name): string
    {
        $this->checkPropertyNameValidity($name);

        return $this->sanitizeNcName($name);
    }

    public function encodeNamespacePrefix(string $prefix): string
    {
        $this->checkPrefixValidity($prefix);

        return $prefix;
    }

    public function encodeTextNode(string $content): string
    {
        $content = $this->stripInvalidChars($content);
        $content = $this->replaceEntities($content);
        $content = $this->normalizeAllWhitespace($content);

        return $content;
    }

    public function encodeCharacterReference(string $content): string
    {
        $result = '';
        $length = mb_strlen($content, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($content, $i, 1, 'UTF-8');
            $cp = mb_ord($char, 'UTF-8');

            if ($cp < 32 || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0xFFFD) {
                $result .= '&#' . $cp . ';';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function stripInvalidChars(string $input): string
    {
        foreach (self::INVALID_XML_CHARS as $char) {
            $input = str_replace($char, '', $input);
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $input) ?: '';
    }

    private function replaceEntities(string $input): string
    {
        return strtr($input, self::ENTITY_MAP);
    }

    private function trimInternalWhitespace(string $input): string
    {
        return preg_replace('/\s+/', ' ', $input);
    }

    private function normalizeAllWhitespace(string $input): string
    {
        return preg_replace('/\s+/', ' ', trim($input));
    }

    private function sanitizeNcName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
    }

    private function checkTagNameValidity(string $name): void
    {
        if (empty($name)) {
            throw new XmlEncodingException('Tag name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            throw new XmlEncodingException('Tag name must begin with letter or underscore');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $name)) {
            throw new XmlEncodingException('Tag name contains invalid characters');
        }
    }

    private function checkPropertyNameValidity(string $name): void
    {
        if (empty($name)) {
            throw new XmlEncodingException('Property name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            throw new XmlEncodingException('Property name must start with letter or underscore');
        }
    }

    private function checkPrefixValidity(string $prefix): void
    {
        if (empty($prefix)) {
            throw new XmlEncodingException('Prefix cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $prefix)) {
            throw new XmlEncodingException('Prefix contains invalid characters');
        }
    }
}
