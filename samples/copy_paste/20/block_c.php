<?php

declare(strict_types=1);

namespace App\Messages;

use Illuminate\Support\Facades\Lang;

final class DisplayMessageBuilder
{
    public function compose(string $translationKey, array $bindings = [], string $lang = null): string
    {
        $lang = $lang ?? application()->getLocale();
        $template = $this->lookupTranslation($translationKey, $lang);

        return $this->substituteVariables($template, $bindings);
    }

    public function composeValidationIssue(string $attribute, string $ruleName, array $ruleParameters = []): string
    {
        $key = "validation_messages.{$ruleName}";
        $vars = array_merge(['attribute' => $this->makeReadable($attribute)], $ruleParameters);

        return $this->compose($key, $vars);
    }

    public function composeAuthIssue(string $issueKey): string
    {
        return $this->compose("auth_issues.{$issueKey}");
    }

    public function composeHttpIssue(int $statusCode, array $context = []): string
    {
        return $this->compose("http_issues.{$statusCode}", $context);
    }

    public function composeDomainIssue(string $domain, string $issueType, array $domainData = []): string
    {
        return $this->compose("domain_issues.{$domain}.{$issueType}", $domainData);
    }

    public function composeDataOperationIssue(string $operation, array $operationData = []): string
    {
        $key = "data_operation_issues.{$operation}";
        $data = [
            'operation_name' => $this->makeReadable($operation),
            'affected_table' => $operationData['table'] ?? 'unknown',
        ];

        return $this->compose($key, $data);
    }

    public function composeRuleViolation(string $rule, array $ruleData = []): string
    {
        return $this->compose("rule_violations.{$rule}", $ruleData);
    }

    public function composeResourceIssue(string $resource, string $action, array $resourceContext = []): string
    {
        return $this->compose("resource_issues.{$resource}.{$action}", $resourceContext);
    }

    public function composeInputIssue(string $inputField, string $reason, array $reasonData = []): string
    {
        $key = "input_issues.{$reason}";
        $vars = array_merge(['field_name' => $this->makeReadable($inputField)], $reasonData);

        return $this->compose($key, $vars);
    }

    public function composeAccessIssue(string $privilege, array $privilegeContext = []): string
    {
        return $this->compose('access_issues.unauthorized', array_merge(
            ['privilege_name' => $this->makeReadable($privilege)],
            $privilegeContext
        ));
    }

    public function composeRateLimitIssue(int $remainingSeconds, int $limit): string
    {
        return $this->compose('rate_limit_issues.exceeded', [
            'remaining' => $remainingSeconds,
            'limit' => $limit,
        ]);
    }

    public function composeScheduledOutageIssue(string $returnTime): string
    {
        return $this->compose('outage_issues.scheduled', ['return_time' => $returnTime]);
    }

    private function lookupTranslation(string $key, string $locale): string
    {
        $translation = Lang::get($key, [], $locale);

        if ($translation === $key) {
            return $this->defaultErrorText($key);
        }

        return $translation;
    }

    private function substituteVariables(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/%([a-zA-Z_]+)%/',
            fn(array $matches) => $variables[$matches[1]] ?? $matches[0],
            $template
        );
    }

    private function makeReadable(string $input): string
    {
        return ucfirst(trim(str_replace(['_', '-', '.'], ' ', $input)));
    }

    private function defaultErrorText(string $key): string
    {
        return 'An unexpected error occurred. Please try again or contact support.';
    }
}
