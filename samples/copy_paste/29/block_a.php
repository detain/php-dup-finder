<?php

declare(strict_types=1);

namespace App\Soap\Services;

use App\Exceptions\XmlEscapingException;

final class XmlNodeEscaper
{
    private const XML_ENTITIES = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&apos;',
    ];

    private const FORBIDDEN_CHARS = [
        "\x00" => '',
        "\x01" => '',
        "\x02" => '',
        "\x03" => '',
        "\x04" => '',
        "\x05" => '',
        "\x06" => '',
        "\x07" => '',
        "\x08" => '',
        "\x0B" => '',
        "\x0C" => '',
        "\x0E" => '',
        "\x0F" => '',
        "\x10" => '',
        "\x11" => '',
        "\x12" => '',
        "\x13" => '',
        "\x14" => '',
        "\x15" => '',
        "\x16" => '',
        "\x17" => '',
        "\x18" => '',
        "\x19" => '',
        "\x1A" => '',
        "\x1B" => '',
        "\x1C" => '',
        "\x1D" => '',
        "\x1E" => '',
        "\x1F" => '',
    ];

    public function escapeElementValue(string $value): string
    {
        $value = $this->removeForbiddenCharacters($value);
        $value = $this->encodeXmlEntities($value);

        return $value;
    }

    public function escapeAttributeValue(string $value): string
    {
        $value = $this->removeForbiddenCharacters($value);
        $value = $this->encodeXmlEntities($value);
        $value = $this->normalizeWhitespace($value);

        return $value;
    }

    public function escapeCdataContent(string $content): string
    {
        $content = $this->removeForbiddenCharacters($content);

        if (str_contains($content, ']]>')) {
            throw new XmlEscapingException('CDATA content cannot contain "]]>"');
        }

        return $content;
    }

    public function escapeComment(string $comment): string
    {
        $comment = $this->removeForbiddenCharacters($comment);

        if (str_contains($comment, '--')) {
            throw new XmlEscapingException('Comment cannot contain "--"');
        }

        if (str_ends_with($comment, '-')) {
            throw new XmlEscapingException('Comment cannot end with "-"');
        }

        return $comment;
    }

    public function escapeProcessingInstruction(string $data): string
    {
        $data = $this->removeForbiddenCharacters($data);

        if (str_starts_with($data, '?>') || str_starts_with($data, '<?')) {
            throw new XmlEscapingException('PI data cannot start with "?>" or "<?');
        }

        return $data;
    }

    public function escapeElementName(string $name): string
    {
        $this->validateElementName($name);

        return $this->escapeNcName($name);
    }

    public function escapeAttributeName(string $name): string
    {
        $this->validateAttributeName($name);

        return $this->escapeNcName($name);
    }

    public function escapeNamespacePrefix(string $prefix): string
    {
        $this->validateNcName($prefix);

        return $prefix;
    }

    public function escapeNamespaceUri(string $uri): string
    {
        $uri = $this->removeForbiddenCharacters($uri);
        $uri = $this->encodeXmlEntities($uri);

        return $uri;
    }

    public function escapeTextContent(string $content): string
    {
        $content = $this->removeForbiddenCharacters($content);
        $content = $this->encodeXmlEntities($content);
        $content = $this->collapseWhitespace($content);

        return $content;
    }

    public function escapeNumericEntity(string $value): string
    {
        $chars = [];
        $length = mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1, 'UTF-8');
            $ord = mb_ord($char, 'UTF-8');

            if ($ord < 32 || $ord > 55295) {
                $chars[] = '&#' . $ord . ';';
            } else {
                $chars[] = $char;
            }
        }

        return implode('', $chars);
    }

    private function removeForbiddenCharacters(string $input): string
    {
        $result = strtr($input, self::FORBIDDEN_CHARS);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $result) ?: '';
    }

    private function encodeXmlEntities(string $input): string
    {
        return str_replace(
            array_keys(self::XML_ENTITIES),
            array_values(self::XML_ENTITIES),
            $input
        );
    }

    private function normalizeWhitespace(string $input): string
    {
        return preg_replace('/\s+/', ' ', $input);
    }

    private function collapseWhitespace(string $input): string
    {
        return preg_replace('/\s+/', ' ', trim($input));
    }

    private function escapeNcName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
    }

    private function validateElementName(string $name): void
    {
        if (empty($name)) {
            throw new XmlEscapingException('Element name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            throw new XmlEscapingException('Element name must start with letter or underscore');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $name)) {
            throw new XmlEscapingException('Element name contains invalid characters');
        }
    }

    private function validateAttributeName(string $name): void
    {
        if (empty($name)) {
            throw new XmlEscapingException('Attribute name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            throw new XmlEscapingException('Attribute name must start with letter or underscore');
        }
    }

    private function validateNcName(string $name): void
    {
        if (empty($name)) {
            throw new XmlEscapingException('NCName cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $name)) {
            throw new XmlEscapingException('NCName contains invalid characters');
        }
    }
}
