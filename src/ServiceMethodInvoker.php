<?php

declare(strict_types=1);

namespace Baraja;


use Baraja\ServiceMethodInvoker\Helpers;
use Tracy\Debugger;

final class ServiceMethodInvoker
{
	private const EMPTY_TYPE_MAPPER = [
		'string' => '',
		'bool' => false,
		'int' => 0,
		'float' => 0.0,
		'array' => [],
		'null' => null,
	];

	/** @var bool[] (entityName => true) */
	private array $recursionDetector = [];


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
	 * @param mixed[] $params
	 * @return mixed|null (in case of called method return void, invoke logic return null)
	 */
	public function invoke(Service $service, string $methodName, array $params, bool $dataMustBeArray = false)
	{
		$args = [];
		try {
			$parameters = ($ref = new \ReflectionMethod($service, $methodName))->getParameters();
			if (isset($parameters[0]) === true) {
				$entityType = ($type = $parameters[0]->getType()) !== null ? $type->getName() : null;
			} else {
				$entityType = null;
			}
			if ($entityType !== null && \class_exists($entityType) === true) { // entity input
				$this->recursionDetector = [];
				$args[$parameters[0]->getName()] = $this->hydrateDataToObject($service, $entityType, $params, $methodName);
			} else { // regular input by scalar parameters
				foreach ($parameters as $parameter) {
					$pName = $parameter->getName();
					if ($dataMustBeArray === true && $pName === 'data') {
						if ((($type = $parameter->getType()) !== null && ($typeName = $type->getName()) !== 'array') || $type === null) {
							RuntimeInvokeException::propertyDataMustBeArray($service, $type === null ? null : $typeName ?? '');
						}
						$args[$pName] = $params;
					} else {
						$args[$pName] = $this->processParameterValue($service, $parameter, $params, $methodName);
					}
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
		if (isset(self::EMPTY_TYPE_MAPPER[$name]) === true) {
			return self::EMPTY_TYPE_MAPPER[$name];
		}

		RuntimeInvokeException::canNotCreateEmptyValueByType($service, $parameter, $name);

		return null;
	}


	/**
	 * @param mixed[] $params
	 * @return object
	 */
	private function hydrateDataToObject(Service $service, string $className, array $params, ?string $methodName = null)
	{
		if (\class_exists($className) === false) {
			RuntimeInvokeException::entityClassDoesNotExist($service, $className);
		}
		if (isset($this->recursionDetector[$className]) === true) {
			RuntimeInvokeException::circularDependency($service, $className, array_keys($this->recursionDetector));
		}

		$this->recursionDetector[$className] = true;

		try {
			$ref = new \ReflectionClass($className);
		} catch (\ReflectionException $e) {
			throw new \RuntimeException('Can not reflection class "' . $className . '": ' . $e->getMessage());
		}

		if (($constructor = $ref->getConstructor()) !== null) {
			$args = [];
			foreach ($constructor->getParameters() as $parameter) {
				$args[$parameter->getName()] = $this->processParameterValue($service, $parameter, $params, $methodName);
			}

			$instance = $ref->newInstanceArgs(array_values($args));
		} else {
			$instance = $ref->newInstanceArgs([]);
		}

		foreach ($ref->getProperties() as $property) {
			if (isset($params[$propertyName = $property->getName()]) === true) {
				$property->setValue($instance, $params[$propertyName]);
				continue;
			}
			if ($property->getValue($instance) !== null) {
				// TODO: Validate if current type match
				continue;
			}
			if (preg_match('/\@var\s+(\S+)/', $property->getDocComment() ?? '', $parser)) {
				$requiredType = $parser[1] ?: 'null';
			} else {
				$requiredType = 'null';
			}

			$allowsScalar = false;
			$entityClass = null;
			foreach (explode('|', $requiredType) as $type) {
				if ($type === 'null') { // allows null
					continue 2;
				}
				if (isset(self::EMPTY_TYPE_MAPPER[$type]) === true) {
					$allowsScalar = true;
				} elseif (\class_exists($tryType = Helpers::resolvePropertyType($property)) === true) {
					$entityClass = $tryType;
				} else {
					RuntimeInvokeException::propertyTypeIsNotSupported($service, $type);
				}
			}
			if ($entityClass !== null) {
				$property->setValue($instance, $this->hydrateDataToObject($service, (string) $entityClass, $params, $methodName));
				continue;
			}

			RuntimeInvokeException::propertyIsRequired($service, $entityClass ?? $className, $propertyName, $allowsScalar, $requiredType);
		}

		return $instance;
	}


	/**
	 * @param mixed[] $params
	 * @return mixed|null
	 */
	private function processParameterValue(Service $service, \ReflectionParameter $parameter, array $params, ?string $methodName = null)
	{
		if (($parameterType = ($type = $parameter->getType()) !== null ? $type->getName() : null) !== null && \class_exists($parameterType) === true) {
			return $this->hydrateDataToObject($service, $parameterType, $params, $methodName);
		}
		if (isset($params[$pName = $parameter->getName()]) === true) {
			if ($params[$pName]) {
				return $this->fixType($params[$pName], (($type = $parameter->getType()) !== null) ? $type : null);
			}
			if (($type = $parameter->getType()) !== null) {
				return $this->returnEmptyValue($service, $pName, $type);
			}
		} elseif ($parameter->isOptional() === true && $parameter->isDefaultValueAvailable() === true) {
			try {
				return $parameter->getDefaultValue();
			} catch (\Throwable $e) {
			}
		} elseif ($parameter->allowsNull() === true && array_key_exists($pName, $params) && $params[$pName] === null) {
			return null;
		}

		RuntimeInvokeException::parameterDoesNotSet($service, $parameter->getName(), $parameter->getPosition(), $methodName ?? '');

		return null;
	}
}
