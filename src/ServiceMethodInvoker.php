<?php

declare(strict_types=1);

namespace Baraja;


use Tracy\Debugger;

final class ServiceMethodInvoker
{
	/** @var mixed[] */
	private static $emptyTypeMapper = [
		'string' => '',
		'bool' => false,
		'int' => 0,
		'float' => 0.0,
		'array' => [],
		'null' => null,
	];


	public function __construct()
	{
		if (\class_exists(Debugger::class) === true) {
			Debugger::getBlueScreen()->addPanel([BlueScreen::class, 'render']);
		}
	}


	/**
	 * Invoke method in given service (with all params) and return method return data.
	 * If method return void, null or empty, this invoke logic return null too.
	 * This invoke method never return void data type.
	 *
	 * Before given method is invoked, this internal logic check all input parameters and validate types.
	 *
	 * @param Service $service
	 * @param string $methodName
	 * @param mixed[] $params
	 * @param bool $dataMustBeArray
	 * @return mixed|null (in case of called method return void, invoke logic return null)
	 */
	public function invoke(Service $service, string $methodName, array $params, bool $dataMustBeArray = false)
	{
		$ref = null;
		$args = [];

		try {
			foreach (($ref = new \ReflectionMethod($service, $methodName))->getParameters() as $parameter) {
				$pName = $parameter->getName();
				if ($dataMustBeArray === true && $pName === 'data') {
					if ((($type = $parameter->getType()) !== null && ($typeName = $type->getName()) !== 'array') || $type === null) {
						RuntimeInvokeException::propertyDataMustBeArray($service, $type === null ? null : $typeName ?? '');
					}

					$args[$pName] = $params;
				} elseif (isset($params[$pName]) === true) {
					if ($params[$pName]) {
						$args[$pName] = $this->fixType($params[$pName], (($type = $parameter->getType()) !== null) ? $type : null);
					} elseif (($type = $parameter->getType()) !== null) {
						$args[$pName] = $this->returnEmptyValue($service, $pName, $type);
					}
				} elseif ($parameter->isOptional() === true && $parameter->isDefaultValueAvailable() === true) {
					try {
						$args[$pName] = $parameter->getDefaultValue();
					} catch (\Throwable $e) {
					}
				} else {
					RuntimeInvokeException::parameterDoesNotSet($service, $parameter->getName(), $parameter->getPosition(), $methodName ?? '');
				}
			}
		} catch (RuntimeInvokeException $e) {
			$e->setMethod($methodName);
			$e->setParams($params);
			throw $e;
		} catch (\ReflectionException $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}

		return $ref->invokeArgs($service, $args);
	}


	/**
	 * Rewrite given type to preference type by annotation.
	 *
	 * 1. If type is nullable, keep original haystack
	 * 2. Empty value rewrite to null, if null is supported
	 * 3. Scalar types
	 * 4. Other -> keep original
	 *
	 * @param mixed $haystack
	 * @param \ReflectionType|null $type
	 * @return mixed
	 */
	private function fixType($haystack, ?\ReflectionType $type)
	{
		if ($type === null) {
			return $haystack;
		}

		if (!$haystack && $type->allowsNull()) {
			return null;
		}

		if ($type->getName() === 'bool') {
			return \in_array(strtolower((string) $haystack), ['1', 'true', 'yes'], true) === true;
		}

		if ($type->getName() === 'int') {
			return (int) $haystack;
		}

		if ($type->getName() === 'float') {
			return (float) $haystack;
		}

		return $haystack;
	}


	/**
	 * @param Service $service
	 * @param string $parameter
	 * @param \ReflectionType $type
	 * @return mixed|null
	 */
	private function returnEmptyValue(Service $service, string $parameter, \ReflectionType $type)
	{
		if ($type->allowsNull() === true) {
			return null;
		}

		if (strpos($name = $type->getName(), '/') !== false || class_exists($name) === true) {
			RuntimeInvokeException::parameterMustBeObject($service, $parameter, $name);
		}

		if (isset(self::$emptyTypeMapper[$name]) === true) {
			return self::$emptyTypeMapper[$name];
		}

		RuntimeInvokeException::canNotCreateEmptyValueByType($service, $parameter, $name);

		return null;
	}
}