<?php

declare(strict_types=1);

namespace App\Errors;

use Illuminate\Support\Facades\Lang;

final class UserErrorMessageFormatter
{
    public function format(string $messageKey, array $replacements = [], string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $message = $this->translate($messageKey, $locale);

        if (empty($replacements)) {
            return $message;
        }

        return $this->interpolate($message, $replacements);
    }

    public function formatValidationError(string $field, string $rule, array $params = []): string
    {
        $key = "validation.{$rule}";
        $replacements = array_merge(['field' => $this->humanizeField($field)], $params);

        return $this->format($key, $replacements);
    }

    public function formatAuthError(string $errorCode): string
    {
        return $this->format("auth.errors.{$errorCode}");
    }

    public function formatApiError(int $statusCode, array $context = []): string
    {
        $key = "api.errors.http_{$statusCode}";
        return $this->format($key, $context);
    }

    public function formatModelError(string $model, string $operation, array $context = []): string
    {
        $key = "models.errors.{$model}.{$operation}";
        return $this->format($key, $context);
    }

    public function formatDatabaseError(string $operation, array $context = []): string
    {
        $key = "database.errors.{$operation}";
        $replacements = [
            'operation' => $this->humanizeOperation($operation),
            'table' => $context['table'] ?? 'unknown',
        ];

        return $this->format($key, $replacements);
    }

    public function formatBusinessRuleError(string $rule, array $entities = []): string
    {
        $key = "business_rules.{$rule}";
        $replacements = [];

        foreach ($entities as $entity => $value) {
            $replacements[$entity] = $this->formatEntityValue($value);
        }

        return $this->format($key, $replacements);
    }

    public function formatResourceError(string $resource, string $action, array $context = []): string
    {
        $key = "resources.errors.{$resource}.{$action}";
        return $this->format($key, $context);
    }

    public function formatInputError(string $inputName, string $reason, array $details = []): string
    {
        $key = "inputs.errors.{$reason}";
        $replacements = array_merge(['input' => $this->humanizeField($inputName)], $details);

        return $this->format($key, $replacements);
    }

    public function formatPermissionError(string $permission, array $context = []): string
    {
        $key = 'permissions.errors.access_denied';
        $replacements = array_merge(['permission' => $this->humanizePermission($permission)], $context);

        return $this->format($key, $replacements);
    }

    public function formatRateLimitError(int $secondsRemaining, int $maxAttempts): string
    {
        return $this->format('rate_limit.exceeded', [
            'seconds' => $secondsRemaining,
            'attempts' => $maxAttempts,
        ]);
    }

    public function formatMaintenanceError(string $scheduledAt): string
    {
        return $this->format('maintenance.scheduled', [
            'datetime' => $scheduledAt,
        ]);
    }

    private function translate(string $key, string $locale): string
    {
        $translated = Lang::get($key, [], $locale);

        if ($translated === $key) {
            return $this->getDefaultMessage($key);
        }

        return $translated;
    }

    private function interpolate(string $template, array $replacements): string
    {
        return preg_replace_callback(
            '/:([a-z_]+)/',
            fn(array $matches) => $replacements[$matches[1]] ?? $matches[0],
            $template
        );
    }

    private function humanizeField(string $field): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $field));
    }

    private function humanizeOperation(string $operation): string
    {
        return ucfirst(str_replace('_', ' ', $operation));
    }

    private function humanizePermission(string $permission): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $permission));
    }

    private function formatEntityValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) $value;
    }

    private function getDefaultMessage(string $key): string
    {
        return 'An error occurred. Please try again later.';
    }
}
