<?php

declare(strict_types=1);

namespace Baraja;


use Tracy\Dumper;

final class BlueScreen
{
	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . get_class($this) . ' is static and cannot be instantiated.');
	}


	/**
	 * @param \Throwable|null $e
	 * @return string[]|null
	 */
	public static function render(?\Throwable $e): ?array
	{
		if ($e !== null && !$e instanceof RuntimeInvokeException && ($previous = $e->getPrevious()) !== null) {
			$e = $previous;
		}

		if ($e instanceof RuntimeInvokeException && ($service = $e->getService()) !== null) {
			$file = null;
			$startLine = null;
			$params = $e->getParams();

			try {
				$ref = new \ReflectionClass($service);
				$file = $ref->getFileName();
				$startLine = ($method = $e->getMethod()) !== null
					? $ref->getMethod($method)->getStartLine()
					: $ref->getStartLine();
			} catch (\ReflectionException $e) {
			}

			if ($file !== null && $startLine !== null && \is_file($file) === true) {
				return [
					'tab' => 'Service Invoker | ' . \get_class($service ?? ''),
					'panel' => \Tracy\BlueScreen::highlightPhp(file_get_contents($file), $startLine, 15, $params ?? [])
						. ($params !== null ? '<p>Params:</p>' . self::renderParamsTable($params) : ''),
				];
			}
		}

		return null;
	}


	/**
	 * @param mixed[] $params
	 * @return string
	 */
	private static function renderParamsTable(array $params): string
	{
		if ($params === []) {
			return '<i>Empty array.</i>';
		}

		$return = '';
		foreach ($params as $key => $value) {
			$return .= '<tr>'
				. '<th>' . htmlspecialchars($key, ENT_IGNORE, 'UTF-8') . '</th>'
				. '<td>' . Dumper::toHtml($value) . '</td>'
				. '</tr>';
		}

		return '<table>' . $return . '</table>';
	}
}
