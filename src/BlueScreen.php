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
		throw new \Error(sprintf('Class "%s" is static and cannot be instantiated.', static::class));
	}


	/**
	 * @return array{tab: string, panel: string}|null
	 */
	public static function render(?\Throwable $e): ?array
	{
		$invokeException = self::resolveRuntimeInvokeException($e);
		$service = $invokeException !== null ? $invokeException->getService() : null;
		if ($service === null) {
			return null;
		}
		$file = null;
		$startLine = null;
		$params = $invokeException->getParams();

		try {
			$ref = new \ReflectionClass($service);
			$refFileName = $ref->getFileName();
			$file = $refFileName === false ? null : $refFileName;
			$method = $invokeException->getMethod();
			if ($method !== null) {
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
				'panel' => sprintf('<p>%s</p>', Helpers::editorLink($file, $startLine))
					. \Tracy\BlueScreen::highlightPhp((string) file_get_contents($file), $startLine)
					. ($params !== null ? '<p>Input params:</p>' . self::renderParamsTable($params) : ''),
			];
		}

		return null;
	}


	private static function resolveRuntimeInvokeException(?\Throwable $exception): ?RuntimeInvokeException
	{
		$return = $exception;
		do {
			if ($return instanceof RuntimeInvokeException) {
				return $return;
			}
			$return = $return !== null ? $return->getPrevious() : null;
		} while ($return !== null);

		return null;
	}


	/**
	 * @param array<string, mixed> $params
	 */
	private static function renderParamsTable(array $params): string
	{
		if ($params === []) {
			return '<i>Parameters were not passed.</i>';
		}

		$return = '';
		foreach ($params as $key => $value) {
			$return .= '<tr>'
				. sprintf('<th>$%s</th>', htmlspecialchars($key, ENT_IGNORE, 'UTF-8'))
				. sprintf('<td>%s</td>', Dumper::toHtml($value))
				. '</tr>';
		}

		return '<table>' . $return . '</table>';
	}
}
