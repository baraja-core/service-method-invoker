<?php

declare(strict_types=1);

namespace Baraja\ServiceMethodInvoker;


use Baraja\RuntimeInvokeException;
use Tracy\Dumper;
use Tracy\Helpers;

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
				$refFileName = $ref->getFileName();
				$file = $refFileName === false ? null : $refFileName;
				if (($method = $e->getMethod()) !== null) {
					$methodRef = $ref->getMethod($method);
					$methodFileName = $methodRef->getFileName();
					$file = $methodFileName === false ? null : $methodFileName;
					$startLine = (int) $methodRef->getStartLine();
				} else {
					$startLine = (int) $ref->getStartLine();
				}
			} catch (\ReflectionException) {
				// Silence is golden.
			}
			if ($file !== null && $startLine !== null && is_file($file) === true) {
				return [
					'tab' => 'Service Invoker | ' . htmlspecialchars(get_class($service ?? '')),
					'panel' => '<p>' . Helpers::editorLink($file, $startLine) . '</p>'
						. \Tracy\BlueScreen::highlightPhp((string) file_get_contents($file), $startLine)
						. ($params !== null ? '<p>Params:</p>' . self::renderParamsTable($params) : ''),
				];
			}
		}

		return null;
	}


	/**
	 * @param mixed[] $params
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
