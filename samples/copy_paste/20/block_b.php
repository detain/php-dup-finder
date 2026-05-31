<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Translation\Translator;

final class ErrorMessageRenderer
{
    public function render(string $messageId, array $params = [], ?string $language = null): string
    {
        $language = $language ?? app()->getLocale();
        $template = $this->fetchTranslation($messageId, $language);

        return $this->replacePlaceholders($template, $params);
    }

    public function renderFieldError(string $fieldName, string $validationRule, array $ruleParams = []): string
    {
        $translationKey = "validation_rules.{$validationRule}";
        $params = array_merge(['field' => $this->prettifyFieldName($fieldName)], $ruleParams);

        return $this->render($translationKey, $params);
    }

    public function renderAuthenticationError(string $errorType): string
    {
        return $this->render("auth_messages.{$errorType}");
    }

    public function renderHttpError(int $statusCode, array $additionalContext = []): string
    {
        $key = "http_errors.{$statusCode}";
        return $this->render($key, $additionalContext);
    }

    public function renderEntityError(string $entityType, string $action, array $contextData = []): string
    {
        $key = "entity_messages.{$entityType}.{$action}";
        return $this->render($key, $contextData);
    }

    public function renderQueryError(string $queryType, array $queryContext = []): string
    {
        $key = "query_messages.{$queryType}";
        $context = [
            'query_type' => $this->prettifyOperation($queryType),
            'table' => $queryContext['table'] ?? 'unknown',
        ];

        return $this->render($key, $context);
    }

    public function renderPolicyError(string $policyName, string $violation, array $policyContext = []): string
    {
        $key = "policy_messages.{$policyName}.{$violation}";
        return $this->render($key, $policyContext);
    }

    public function renderServiceError(string $serviceName, string $errorCode, array $serviceContext = []): string
    {
        $key = "service_errors.{$serviceName}.{$errorCode}";
        return $this->render($key, $serviceContext);
    }

    public function renderFormError(string $inputIdentifier, string $errorReason, array $errorDetails = []): string
    {
        $key = "form_errors.{$errorReason}";
        $params = array_merge(['input' => $this->prettifyFieldName($inputIdentifier)], $errorDetails);

        return $this->render($key, $params);
    }

    public function renderAccessError(string $requiredPermission, array $accessContext = []): string
    {
        $key = 'access_errors.permission_denied';
        $params = array_merge(['permission' => $this->prettifyPermission($requiredPermission)], $accessContext);

        return $this->render($key, $params);
    }

    public function renderThrottleError(int $waitSeconds, int $maxAttempts): string
    {
        return $this->render('throttle_messages.too_many_attempts', [
            'wait_seconds' => $waitSeconds,
            'max_attempts' => $maxAttempts,
        ]);
    }

    public function renderDowntimeError(string $resumeTime): string
    {
        return $this->render('downtime_messages.scheduled_maintenance', [
            'resume_time' => $resumeTime,
        ]);
    }

    private function fetchTranslation(string $key, string $locale): string
    {
        $translated = trans($key, [], $locale);

        if ($translated === $key) {
            return $this->fallbackMessage($key);
        }

        return $translated;
    }

    private function replacePlaceholders(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            fn(array $matches) => $values[trim($matches[1])] ?? $matches[0],
            $template
        );
    }

    private function prettifyFieldName(string $field): string
    {
        return ucwords(str_replace(['_', '-', '.'], ' ', $field));
    }

    private function prettifyOperation(string $operation): string
    {
        return ucwords(str_replace('_', ' ', $operation));
    }

    private function prettifyPermission(string $permission): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $permission));
    }

    private function fallbackMessage(string $key): string
    {
        return 'Something went wrong. Please contact support if the problem persists.';
    }
}
