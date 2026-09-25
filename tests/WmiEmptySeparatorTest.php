<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression: Linux_WMI::$separator is a public, mutable property that
 * defaults to '|+|' but can be set to ''. explode('', ...) throws a PHP 8
 * ValueError, and even after guarding that, the guard has to be applied
 * consistently: the value sent to the transport (Wmi_Request::$separator)
 * and the value used to parse the transport's output must be the SAME
 * effective separator, or a wmic/PowerShell request built with '' would
 * emit unsplit rows while the parser splits on the fallback '|+|'.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($s) {
		return escapeshellarg($s);
	}
}

require_once __DIR__ . '/../linux_wmi.php';

/** A transport stub that records the request it received and returns canned rows built with a given separator. */
class Wmi_Test_Recording_Transport implements Wmi_Transport {
	public ?Wmi_Request $seen = null;

	public function __construct(private string $built_with_separator) {
	}

	public function query(Wmi_Request $request): array|false {
		$this->seen = $request;

		return [
			'Win32_Service',
			implode($this->built_with_separator, ['Name', 'State']),
			implode($this->built_with_separator, ['Spooler', 'Running']),
		];
	}

	public function error(): ?string {
		return null;
	}
}

describe('Linux_WMI empty separator handling', function () {
	it('falls back to the default separator for both the transport request and result parsing when $separator is empty', function () {
		$transport = new Wmi_Test_Recording_Transport('|+|');

		$w              = new Linux_WMI('', $transport);
		$w->username    = 'u';
		$w->password    = 'p';
		$w->hostname    = 'host';
		$w->command     = 'SELECT Name,State FROM Win32_Service';
		$w->separator   = '';

		$results = $w->fetch();

		expect($transport->seen)->not->toBeNull();
		expect($transport->seen->separator)->toBe('|+|');
		expect($results)->toBe([
			['Win32_Service'],
			['Name', 'State'],
			['Spooler', 'Running'],
		]);
	});

	it('uses a custom non-empty separator consistently for both the request and result parsing', function () {
		$transport = new Wmi_Test_Recording_Transport('##');

		$w              = new Linux_WMI('', $transport);
		$w->username    = 'u';
		$w->password    = 'p';
		$w->hostname    = 'host';
		$w->command     = 'SELECT Name,State FROM Win32_Service';
		$w->separator   = '##';

		$results = $w->fetch();

		expect($transport->seen->separator)->toBe('##');
		expect($results)->toBe([
			['Win32_Service'],
			['Name', 'State'],
			['Spooler', 'Running'],
		]);
	});
});
