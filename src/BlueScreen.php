<?php

declare(strict_types=1);

namespace Baraja;


use Tracy\Dumper;

final class BlueScreen
{
	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . static::class . ' is static and cannot be instantiated.');
	}


	/**
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
				$file = $ref->getFileName() ?: null;
				if (($method = $e->getMethod()) !== null) {
					$methodRef = $ref->getMethod($method);
					$file = $methodRef->getFileName() ?: null;
					$startLine = $methodRef->getStartLine();
				} else {
					$startLine = $ref->getStartLine();
				}
			} catch (\ReflectionException $e) {
			}
			if ($file !== null && $startLine !== null && \is_file((string) $file) === true) {
				return [
					'tab' => 'Service Invoker | ' . htmlspecialchars((string) (\get_class($service ?? ''))),
					'panel' => '<p>' . htmlspecialchars($file . ':' . $startLine) . '</p>'
						. \Tracy\BlueScreen::highlightPhp((string) file_get_contents((string) $file), (int) $startLine, 15)
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
			return '<i>Parameters were not passed.</i>';
		}

		$return = '';
		foreach ($params as $key => $value) {
			$return .= '<tr>'
				. '<th>$' . htmlspecialchars($key, ENT_IGNORE, 'UTF-8') . '</th>'
				. '<td>' . Dumper::toHtml($value) . '</td>'
				. '</tr>';
		}

		return '<table>' . $return . '</table>';
	}
}
