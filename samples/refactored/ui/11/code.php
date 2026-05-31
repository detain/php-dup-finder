<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedFieldRenderer
{
    /** @var array<string, FieldRendererConfig> */
    private array $fieldConfigs = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->fieldConfigs['text'] = new FieldRendererConfig(
            type: 'text',
            defaultMaxLength: 100,
            defaultAutocomplete: 'off',
            wrapperClass: 'form-field',
            inputClass: 'field-input',
            errorClass: 'field-error',
            errorElement: 'span',
            errorClassAttr: 'error-text',
        );

        $this->fieldConfigs['email'] = new FieldRendererConfig(
            type: 'email',
            defaultMaxLength: 255,
            defaultAutocomplete: 'email',
            wrapperClass: 'form-field',
            inputClass: 'field-input',
            errorClass: 'field-error',
            errorElement: 'span',
            errorClassAttr: 'error-text',
        );

        $this->fieldConfigs['tel'] = new FieldRendererConfig(
            type: 'tel',
            defaultMaxLength: 20,
            defaultAutocomplete: 'tel',
            wrapperClass: 'form-field',
            inputClass: 'field-input',
            errorClass: 'field-error',
            errorElement: 'span',
            errorClassAttr: 'error-text',
        );
    }

    public function render(string $fieldType, FieldRenderOptions $options): string
    {
        $config = $this->fieldConfigs[$fieldType] ?? $this->fieldConfigs['text'];

        $options->maxLength ??= $config->defaultMaxLength;
        $options->autocomplete ??= $config->defaultAutocomplete;

        $hasErrors = !empty($options->errors);
        $wrapperClass = $hasErrors ? $config->wrapperClass . ' ' . $config->errorClass : $config->wrapperClass;
        $inputClass = $config->inputClass;

        $html = '<div class="' . htmlspecialchars($wrapperClass) . '" data-field="' . htmlspecialchars($options->id) . '">';
        $html .= $this->renderLabel($options, $config);
        $html .= '<input type="' . $config->type . '"';
        $html .= ' id="' . htmlspecialchars($options->id) . '"';
        $html .= ' name="' . htmlspecialchars($options->name) . '"';
        $html .= ' value="' . htmlspecialchars($options->value) . '"';
        $html .= ' placeholder="' . htmlspecialchars($options->placeholder ?? '') . '"';
        $html .= ' maxlength="' . $options->maxLength . '"';
        if ($options->required) {
            $html .= ' required aria-required="true"';
        }
        $html .= ' autocomplete="' . $options->autocomplete . '"';
        $html .= ' class="' . htmlspecialchars($inputClass) . '"';
        $html .= '/>';
        $html .= $this->renderErrors($options->errors, $config);
        $html .= '</div>';

        return $html;
    }

    private function renderLabel(FieldRenderOptions $options, FieldRendererConfig $config): string
    {
        $html = '<label for="' . htmlspecialchars($options->id) . '" class="field-label">';
        if ($options->required) {
            $html .= '<span class="required-indicator">*</span>';
        }
        $html .= htmlspecialchars($options->label) . '</label>';
        return $html;
    }

    private function renderErrors(array $errors, FieldRendererConfig $config): string
    {
        if (empty($errors)) {
            return '';
        }

        return '<' . $config->errorElement . ' class="' . htmlspecialchars($config->errorClassAttr) . '">' .
               htmlspecialchars($errors[0]) .
               '</' . $config->errorElement . '>';
    }

    public function renderForm(FormRenderOptions $options): string
    {
        $html = '<form';
        $html .= ' class="' . htmlspecialchars($options->formClass) . '"';
        $html .= ' method="' . htmlspecialchars($options->method) . '"';
        $html .= ' action="' . htmlspecialchars($options->action) . '"';
        if ($options->novalidate) {
            $html .= ' novalidate';
        }
        $html .= '>';

        foreach ($options->fields as $field) {
            $html .= $this->render($field->type, $field->toOptions());
        }

        $html .= '<button type="submit" class="' . htmlspecialchars($options->submitClass) . '">' .
                 htmlspecialchars($options->submitLabel) . '</button>';
        $html .= '</form>';

        return $html;
    }
}

final class FieldRendererConfig
{
    public function __construct(
        public readonly string $type,
        public readonly int $defaultMaxLength,
        public readonly string $defaultAutocomplete,
        public readonly string $wrapperClass,
        public readonly string $inputClass,
        public readonly string $errorClass,
        public readonly string $errorElement,
        public readonly string $errorClassAttr,
    ) {}
}

final class FieldRenderOptions
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly string $value = '',
        public readonly string $placeholder = '',
        public readonly bool $required = false,
        public readonly ?int $maxLength = null,
        public readonly ?string $autocomplete = null,
        public readonly array $errors = [],
    ) {}

    public function toOptions(): self
    {
        return $this;
    }
}
