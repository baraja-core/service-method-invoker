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


	public function __construct()
	{
		static $blueScreenRegistered = false;
		if ($blueScreenRegistered === false && \class_exists(Debugger::class) === true) {
			Debugger::getBlueScreen()->addPanel([BlueScreen::class, 'render']);
			$blueScreenRegistered = true;
		}
	}


	/**
	 * Invoke method in given service (with all params) and return method return data.
	 * If method return void, null or empty, this invoke logic return null too.
	 * This invoke method never return void data type.
	 *
	 * Before given method is invoked, this internal logic check all input parameters and validate types.
	 * In case of called method return void, invoke logic return null
	 *
	 * @param mixed[] $params
	 */
	public function invoke(Service $service, string $methodName, array $params, bool $dataMustBeArray = false): mixed
	{
		$args = [];
		try {
			$parameters = ($ref = new \ReflectionMethod($service, $methodName))->getParameters();
			if (isset($parameters[0]) === true) {
				$entityType = ($type = $parameters[0]->getType()) !== null
					? $type->getName()
					: null;
			} else {
				$entityType = null;
			}
			if ($entityType !== null && \class_exists($entityType) === true) { // entity input
				$args[$parameters[0]->getName()] = $this->hydrateDataToObject($service, $entityType, $params[$parameters[0]->getName()] ?? $params, $methodName);
			} else { // regular input by scalar parameters
				foreach ($parameters as $parameter) {
					$pName = $parameter->getName();
					if ($dataMustBeArray === true && $pName === 'data') {
						if (
							(
								($type = $parameter->getType()) !== null
								&& ($typeName = $type->getName()) !== 'array'
							)
							|| $type === null
						) {
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
	 */
	private function fixType(mixed $haystack, ?\ReflectionType $type, bool $allowsNull): mixed
	{
		if ($type === null) {
			return $allowsNull && $haystack === 'null' ? null : $haystack;
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

		return $allowsNull && $haystack === 'null' ? null : $haystack;
	}


	private function returnEmptyValue(Service $service, string $parameter, mixed $value, \ReflectionType $type): mixed
	{
		if ($type->allowsNull() === true) {
			if (($value === '0' || $value === 0) && $type->getName() === 'bool') {
				return false;
			}
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
	 * @param bool[] $recursionContext (entityName => true)
	 * @param mixed[] $params
	 * @return object
	 */
	private function hydrateDataToObject(
		Service $service,
		string $className,
		array $params,
		?string $methodName = null,
		array $recursionContext = []
	) {
		if (\class_exists($className) === false) {
			throw new RuntimeInvokeException($service, $service . ': Entity class "' . $className . '" does not exist.');
		}
		if (isset($recursionContext[$className]) === true) {
			RuntimeInvokeException::circularDependency($service, $className, array_keys($recursionContext));
		}
		$recursionContext[$className] = true;

		try {
			$ref = new \ReflectionClass($className);
		} catch (\ReflectionException $e) {
			throw new \RuntimeException('Can not reflection class "' . $className . '": ' . $e->getMessage());
		}

		if (($constructor = $ref->getConstructor()) !== null) {
			$args = [];
			foreach ($constructor->getParameters() as $parameter) {
				$args[$parameter->getName()] = $this->processParameterValue($service, $parameter, $params, $methodName, $recursionContext);
			}

			$instance = $ref->newInstanceArgs(array_values($args));
		} else {
			$instance = $ref->newInstanceArgs([]);
		}

		foreach ($ref->getProperties() as $property) {
			$property->setAccessible(true);
			if (
				isset($params[$propertyName = $property->getName()]) === true
				&& is_scalar($params[$propertyName]) === true
			) {
				$this->hydrateValueToEntity($property, $instance, $params[$propertyName]);
				continue;
			}
			if ($property->isInitialized($instance) && $property->getValue($instance) !== null) {
				continue;
			}
			if (preg_match('/@var\s+(\S+)/', $property->getDocComment() ?: '', $parser)) {
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
					continue;
				}
				$tryType = Helpers::resolvePropertyType($property);
				if ($tryType !== null && \class_exists($tryType) === true) {
					$entityClass = $tryType;
				} else {
					RuntimeInvokeException::propertyTypeIsNotSupported($service, $type);
				}
			}
			if ($entityClass !== null) {
				$this->hydrateValueToEntity($property, $instance, $this->hydrateDataToObject($service, (string) $entityClass, $params[$propertyName] ?? $params, $methodName, $recursionContext));
				continue;
			}

			RuntimeInvokeException::propertyIsRequired($service, $entityClass ?? $className, $propertyName, $allowsScalar, $requiredType);
		}

		return $instance;
	}


	/**
	 * @param mixed[] $params
	 */
	private function processParameterValue(
		Service $service,
		\ReflectionParameter $parameter,
		array $params,
		?string $methodName = null,
		array $recursionContext = []
	): mixed {
		$pName = $parameter->getName();
		if (
			($parameterType = ($type = $parameter->getType()) !== null ? $type->getName() : null) !== null
			&& \class_exists($parameterType) === true
		) {
			if (isset($params[$pName]) === true) {
				if ($params[$pName] === 'null' && $parameter->allowsNull() === true) {
					return null;
				}
				if ($params[$pName] instanceof $parameterType) {
					return $params[$pName];
				}
			}

			return $this->hydrateDataToObject($service, $parameterType, $params[$pName] ?? $params, $methodName, $recursionContext);
		}
		if (isset($params[$pName]) === true) {
			if ($params[$pName]) {
				return $this->fixType($params[$pName], (($type = $parameter->getType()) !== null) ? $type : null, $parameter->allowsNull());
			}
			if (($type = $parameter->getType()) !== null) {
				return $this->returnEmptyValue($service, $pName, $params[$pName], $type);
			}
		} elseif ($parameter->isOptional() === true && $parameter->isDefaultValueAvailable() === true) {
			try {
				return $parameter->getDefaultValue();
			} catch (\Throwable) {
			}
		} elseif ($parameter->allowsNull() === true && array_key_exists($pName, $params) && $params[$pName] === null) {
			return null;
		}

		RuntimeInvokeException::parameterDoesNotSet($service, $parameter->getName(), $parameter->getPosition(), $methodName ?? '');

		return null;
	}


	private function hydrateValueToEntity(\ReflectionProperty $property, mixed $instance, mixed $value): void
	{
		try {
			if (method_exists($instance, $setter = 'set' . $property->getName())) {
				$instance->$setter($value);
			} else {
				$property->setValue($instance, $value);
			}
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('UserException: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}
}
