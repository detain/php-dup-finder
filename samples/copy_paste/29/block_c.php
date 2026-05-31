<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Exceptions\XmlSanitizationException;

final class XmlContentSanitizer
{
    private const CHARS_TO_ESCAPE = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&apos;',
    ];

    private const CONTROL_CHARACTERS = [
        "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
        "\x08", "\x0B", "\x0C", "\x0E", "\x0F", "\x10", "\x11", "\x12",
        "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A",
        "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
    ];

    public function sanitizeValue(string $value): string
    {
        $value = $this->stripControlChars($value);
        $value = $this->escapeSpecialChars($value);

        return $value;
    }

    public function sanitizeAttribute(string $value): string
    {
        $value = $this->stripControlChars($value);
        $value = $this->escapeSpecialChars($value);
        $value = $this->collapseSpaces($value);

        return $value;
    }

    public function sanitizeCdata(string $data): string
    {
        $data = $this->stripControlChars($data);

        if (strpos($data, ']]>') !== false) {
            throw new XmlSanitizationException('CDATA section contains forbidden sequence');
        }

        return $data;
    }

    public function sanitizeComment(string $comment): string
    {
        $comment = $this->stripControlChars($comment);

        if (strpos($comment, '--') !== false) {
            throw new XmlSanitizationException('Comment contains consecutive hyphens');
        }

        if (substr($comment, -1) === '-') {
            throw new XmlSanitizationException('Comment ends with hyphen');
        }

        return $comment;
    }

    public function sanitizeElementName(string $name): string
    {
        $this->validateElementNameSyntax($name);

        return $this->escapeNcName($name);
    }

    public function sanitizeLocalName(string $name): string
    {
        $this->validateLocalNameSyntax($name);

        return $this->escapeNcName($name);
    }

    public function sanitizePrefix(string $prefix): string
    {
        $this->validatePrefixSyntax($prefix);

        return $prefix;
    }

    public function sanitizeTextNode(string $text): string
    {
        $text = $this->stripControlChars($text);
        $text = $this->escapeSpecialChars($text);
        $text = $this->collapseSpaces($text);

        return trim($text);
    }

    public function sanitizeHexBinary(string $data): string
    {
        return preg_replace('/[^a-fA-F0-9]/', '', $data);
    }

    private function stripControlChars(string $input): string
    {
        $result = str_replace(self::CONTROL_CHARACTERS, '', $input);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $result) ?: '';
    }

    private function escapeSpecialChars(string $input): string
    {
        return strtr($input, self::CHARS_TO_ESCAPE);
    }

    private function collapseSpaces(string $input): string
    {
        return preg_replace('/\s+/', ' ', $input);
    }

    private function escapeNcName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
    }

    private function validateElementNameSyntax(string $name): void
    {
        if (empty($name)) {
            throw new XmlSanitizationException('Element name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            throw new XmlSanitizationException('Element name must begin with letter or underscore');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $name)) {
            throw new XmlSanitizationException('Element name contains invalid characters');
        }
    }

    private function validateLocalNameSyntax(string $name): void
    {
        if (empty($name)) {
            throw new XmlSanitizationException('Local name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_][:a-zA-Z0-9_\-\.]*$/', $name)) {
            throw new XmlSanitizationException('Local name has invalid syntax');
        }
    }

    private function validatePrefixSyntax(string $prefix): void
    {
        if (empty($prefix)) {
            throw new XmlSanitizationException('Prefix cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $prefix)) {
            throw new XmlSanitizationException('Prefix contains invalid characters');
        }
    }
}
