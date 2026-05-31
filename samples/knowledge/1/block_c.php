<?php

declare(strict_types=1);

namespace App\Http\Forms;

final class RegistrationFormBuilder
{
    public function buildHtml(string $csrfToken): string
    {
        $html  = '<form method="post" action="/register" class="registration-form">';
        $html .= '<input type="hidden" name="_token" value="' . htmlspecialchars($csrfToken) . '">';

        $html .= '<label for="reg-email">Email</label>';
        $html .= '<input type="email" id="reg-email" name="email" required maxlength="255">';

        $html .= '<label for="reg-username">Username</label>';
        $html .= '<input type="text" id="reg-username" name="username" required maxlength="64" pattern="[A-Za-z0-9_]+">';

        $html .= '<label for="reg-password">Password</label>';
        $html .= '<input type="password" id="reg-password" name="password" '
              .  'required minlength="8" maxlength="32" '
              .  'pattern=".{8,32}" '
              .  'title="Password must be 8-32 characters" '
              .  'autocomplete="new-password">';
        $html .= '<p class="hint">Use 8 to 32 characters, including upper, lower, digit, and symbol.</p>';

        $html .= '<label for="reg-password-confirm">Confirm Password</label>';
        $html .= '<input type="password" id="reg-password-confirm" name="password_confirm" '
              .  'required minlength="8" maxlength="32">';

        $html .= '<button type="submit">Create account</button>';
        $html .= '</form>';

        return $html;
    }
}
