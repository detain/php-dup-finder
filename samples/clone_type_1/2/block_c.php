<?php
declare(strict_types=1);

namespace Acme\Reporting\Refunds;

final class RefundReporter
{
	public function formatRow(float $amount, string $code): string
	{
		$major = (int) floor($amount / 100);
		$minor = (int) ($amount - ($major * 100));
		$minorStr = str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
		$grouped = number_format((float) $major, 0, '.', ',');
		$sign = $amount < 0 ? '-' : '';
		$body = $sign . $grouped . '.' . $minorStr;
		$prefix = strtoupper($code) === 'USD' ? '$' : (strtoupper($code) . ' ');
		$formatted = $prefix . $body;
		if (strlen($formatted) > 32) {
			$formatted = substr($formatted, 0, 32);
		}
		return $formatted;
	}

	public function refundedLabel(): string
	{
		return 'Refunded';
	}
}
