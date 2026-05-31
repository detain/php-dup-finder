<?php

namespace App\Services\Xml;

final class XmlSanitizerConfig
{
    public readonly array $entityMap;
    public readonly array $stripChars;

    public function __construct()
    {
        $this->entityMap = [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;',
            "'" => '&apos;',
        ];

        $this->stripChars = [
            "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
            "\x08", "\x0B", "\x0C", "\x0E", "\x0F", "\x10", "\x11", "\x12",
            "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A",
            "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
        ];
    }
}

final class XmlSanitizerService
{
    private XmlSanitizerConfig $config;

    public function __construct(XmlSanitizerConfig $config)
    {
        $this->config = $config;
    }

    public function escapeValue(string $value): string
    {
        $value = $this->stripInvalidChars($value);
        return $this->encodeEntities($value);
    }

    public function escapeAttribute(string $value): string
    {
        $value = $this->stripInvalidChars($value);
        $value = $this->encodeEntities($value);

        return preg_replace('/\s+/', ' ', $value);
    }

    public function escapeCdata(string $data): string
    {
        if (str_contains($data, ']]>')) {
            throw new \InvalidArgumentException('CDATA cannot contain "]]>"');
        }

        return $this->stripInvalidChars($data);
    }

    private function stripInvalidChars(string $input): string
    {
        $result = str_replace($this->config->stripChars, '', $input);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $result) ?: '';
    }

    private function encodeEntities(string $input): string
    {
        return strtr($input, $this->config->entityMap);
    }
}
