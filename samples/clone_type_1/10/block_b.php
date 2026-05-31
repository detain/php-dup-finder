<?php
declare(strict_types=1);

namespace Acme\Notify\Receipt;

final class ReceiptNotifier
{
  public function render(string $template, array $vars): string
  {
    $body = $template;
    foreach ($vars as $name => $value) {
      $needle = '{{' . $name . '}}';
      $replacement = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
      $body = str_replace($needle, $replacement, $body);
    }
    // strip leftover placeholders
    $body = preg_replace('/\{\{[^}]+\}\}/', '', $body);
    $body = trim((string) $body);
    if (strlen($body) === 0) {
      $body = '(empty)';
    }
    // hard limit on email body length
    if (strlen($body) > 65536) {
      $body = substr($body, 0, 65536);
    }
    return $body;
  }

  public function deliver(string $to, string $subject, string $body): void
  {
    // queue receipt email
  }
}
