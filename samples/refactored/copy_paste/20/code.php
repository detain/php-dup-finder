<?php

declare(strict_types=1);

namespace App\Services\Messages;

use Illuminate\Translation\Translator;

final class ErrorMessageConfig
{
    public readonly string $defaultLocale;
    public readonly string $fallbackMessage;

    public function __construct(
        string $defaultLocale = 'en',
        string $fallbackMessage = 'An error occurred. Please try again.'
    ) {
        $this->defaultLocale = $defaultLocale;
        $this->fallbackMessage = $fallbackMessage;
    }
}

final class ErrorMessageService
{
    private ErrorMessageConfig $config;
    private Translator $translator;

    public function __construct(ErrorMessageConfig $config, Translator $translator)
    {
        $this->config = $config;
        $this->translator = $translator;
    }

    public function format(string $key, array $replacements = []): string
    {
        $message = $this->translator->get($key, [], $this->config->defaultLocale);

        if ($message === $key) {
            return $this->config->fallbackMessage;
        }

        return $this->interpolate($message, $replacements);
    }

    public function formatValidation(string $field, string $rule, array $params = []): string
    {
        return $this->format("validation.{$rule}", array_merge(
            ['field' => $this->humanize($field)],
            $params
        ));
    }

    private function interpolate(string $template, array $replacements): string
    {
        return preg_replace_callback(
            '/:([a-z_]+)/',
            fn(array $matches) => $replacements[$matches[1]] ?? $matches[0],
            $template
        );
    }

    private function humanize(string $input): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $input));
    }
}
