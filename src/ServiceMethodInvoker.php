<?php

declare(strict_types=1);

namespace Baraja;


use Baraja\ServiceMethodInvoker\BlueScreen;
use Baraja\ServiceMethodInvoker\Helpers;
use Baraja\ServiceMethodInvoker\ProjectEntityRepository;
use Tracy\Debugger;
use Tracy\Dumper;

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


	public function __construct(
		private ?ProjectEntityRepository $projectEntityRepository = null
	) {
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
	 * @param array<string, mixed> $params
	 */
	public function invoke(
		object $service,
		string $methodName,
		array $params,
		bool $dataMustBeArray = false
	): mixed {
		if (method_exists($service, $methodName) === false) {
			throw new \InvalidArgumentException(sprintf(
				'Method "%s" in class "%s" does not exist or is not public and callable.',
				$methodName,
				get_debug_type($service),
			));
		}
		if (is_callable([$service, $methodName]) === false) {
			throw new \InvalidArgumentException(sprintf(
				'Method "%s" in class "%s" is not callable.',
				$methodName,
				get_debug_type($service),
			));
		}
		try {
			$ref = new \ReflectionMethod($service, $methodName);
		} catch (\ReflectionException $e) {
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
		$args = $this->getInvokeArgs($service, $methodName, $params, $dataMustBeArray);

		return $ref->invokeArgs($service, $args);
	}


	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function getInvokeArgs(
		object $service,
		string $methodName,
		array $params,
		bool $dataMustBeArray = false
	): array {
		if (method_exists($service, $methodName) === false) {
			throw new \InvalidArgumentException(sprintf(
				'Method "%s" in class "%s" does not exist or is not public and callable.',
				$methodName,
				get_debug_type($service),
			));
		}
		if (is_callable([$service, $methodName]) === false) {
			throw new \InvalidArgumentException(sprintf(
				'Method "%s" in class "%s" is not callable.',
				$methodName,
				get_debug_type($service),
			));
		}
		$args = [];
		try {
			$parameters = (new \ReflectionMethod($service, $methodName))->getParameters();
			if (isset($parameters[0]) === true) {
				$type = $parameters[0]->getType();
				$entityType = $type !== null ? $type->getName() : null;
			} else {
				$entityType = null;
			}
			if (
				$entityType !== null
				&& \class_exists($entityType) === true
				&& is_subclass_of($entityType, \DateTimeInterface::class) === false
			) { // entity input
				$args[$parameters[0]->getName()] = $this->hydrateDataToObject(
					service: $service,
					className: $entityType,
					params: $params[$parameters[0]->getName()] ?? $params,
					methodName: $methodName,
				);
			} else { // regular input by scalar parameters
				foreach ($parameters as $parameter) {
					$pName = $parameter->getName();
					if ($dataMustBeArray === true && $pName === 'data') {
						$type = $parameter->getType();
						$typeName = $type !== null ? $type->getName() : null;
						if (($typeName !== null && $typeName !== 'array') || $type === null) {
							throw new RuntimeInvokeException(
								$service,
								sprintf('%s: Api parameter "data" must be type of "array". ', Helpers::formatServiceName($service))
								. ($type === null
									? 'No type has been defined. Did you set PHP 7 strict data types?'
									: sprintf('Type "%s" given.', $typeName ?? '')
								),
							);
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

		return $args;
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
		if (((bool) $haystack) === false && $type->allowsNull() === true) {
			return null;
		}
		$typeName = $type->getName();
		if ($typeName === 'bool') {
			return \in_array(strtolower((string) $haystack), ['1', 'true', 'yes'], true) === true;
		}
		if ($haystack === 'null' && ($typeName === 'int' || $typeName === 'float')) {
			if ($allowsNull === false) {
				throw new \LogicException('Parameter can not be nullable.');
			}

			return null;
		}
		if ($typeName === 'int') {
			return (int) $haystack;
		}
		if ($typeName === 'float') {
			return (float) $haystack;
		}

		return $allowsNull && $haystack === 'null' ? null : $haystack;
	}


	private function returnEmptyValue(object $service, string $parameter, mixed $value, \ReflectionType $type): mixed
	{
		if ($type->allowsNull() === true) {
			if ($value === '0' || $value === 0) {
				$typeName = $type->getName();
				if ($typeName === 'bool') {
					return false;
				}
				if ($typeName === 'int' || $typeName === 'float') {
					return 0;
				}
			}

			return null;
		}
		$name = $type->getName();
		if (str_contains($name, '/') || class_exists($name) === true) {
			throw new RuntimeInvokeException(
				$service,
				sprintf(
					'%s: Parameter "%s" must be a object of type "%s", but empty value given.',
					Helpers::formatServiceName($service),
					$parameter,
					$name,
				),
			);
		}
		if (isset(self::EMPTY_TYPE_MAPPER[$name]) === true) {
			return self::EMPTY_TYPE_MAPPER[$name];
		}

		throw new RuntimeInvokeException(
			$service,
			sprintf(
				'%s: Can not create default empty value for parameter "%s" type "%s" given.',
				Helpers::formatServiceName($service),
				$parameter,
				$name,
			),
		);
	}


	/**
	 * @param array<string, bool> $recursionContext (entityName => true)
	 */
	private function hydrateDataToObject(
		object $service,
		string $className,
		mixed $params,
		?string $methodName = null,
		array $recursionContext = []
	): object {
		if (\class_exists($className) === false) {
			throw new RuntimeInvokeException(
				$service,
				sprintf('%s: Entity class "%s" does not exist.', Helpers::formatServiceName($service), $className),
			);
		}
		if (is_array($params) === false) {
			$valueInstance = $this->tryMakeEntityInstance($className, $params);
			if ($valueInstance !== null) {
				return $valueInstance;
			}
		}
		if (isset($recursionContext[$className]) === true) {
			throw new RuntimeInvokeException(
				$service,
				sprintf(
					'%s: Circular reference detected, because the entity "%s" already has been instanced.',
					Helpers::formatServiceName($service),
					$className,
				)
				. "\n" . 'Current stack trace: ' . implode(', ', array_keys($recursionContext)),
			);
		}
		$recursionContext[$className] = true;

		try {
			$ref = new \ReflectionClass($className);
		} catch (\ReflectionException $e) {
			throw new \RuntimeException(
				sprintf('Can not create reflection class of "%s": %s', $className, $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}

		$constructor = $ref->getConstructor();
		if ($constructor !== null) {
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
			$propertyName = $property->getName();
			if (isset($params[$propertyName]) === true && is_scalar($params[$propertyName]) === true) {
				$this->hydrateValueToEntity($property, $instance, $params[$propertyName]);
				continue;
			}
			if ($property->isInitialized($instance) && $property->getValue($instance) !== null) {
				continue;
			}
			if (preg_match('/@var\s+(\S+)/', (string) $property->getDocComment(), $parser) === 1) {
				$requiredType = isset($parser[1]) && $parser[1] !== ''
					? $parser[1]
					: 'null';
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
					throw new RuntimeInvokeException(
						$service,
						sprintf(
							'%s: Property type of "%s" is not supported. Did you mean a scalar type or a entity?',
							Helpers::formatServiceName($service),
							$type,
						),
					);
				}
			}
			if ($entityClass !== null) {
				$this->hydrateValueToEntity($property, $instance, $this->hydrateDataToObject(
					service: $service,
					className: $entityClass,
					params: $params[$propertyName] ?? $params,
					methodName: $methodName,
					recursionContext: $recursionContext,
				));
				continue;
			}

			RuntimeInvokeException::propertyIsRequired($service, $entityClass ?? $className, $propertyName, $allowsScalar, $requiredType);
		}

		return $instance;
	}


	/**
	 * @param array<string, mixed> $params
	 */
	private function processParameterValue(
		object $service,
		\ReflectionParameter $parameter,
		array $params,
		?string $methodName = null,
		array $recursionContext = []
	): mixed {
		$pName = $parameter->getName();
		$type = $parameter->getType();
		$parameterType = $type !== null ? $type->getName() : null;
		if ($parameterType !== null && \class_exists($parameterType) === true) {
			if (array_key_exists($pName, $params) === true) {
				if (($params[$pName] === 'null' || $params[$pName] === null) && $parameter->allowsNull() === true) {
					return null;
				}
				if ($params[$pName] instanceof $parameterType) {
					return $params[$pName];
				}
				try {
					$findInstance = $this->tryMakeEntityInstance($parameterType, $params[$pName]);
					if ($findInstance !== null) {
						return $findInstance;
					}
				} catch (\ReflectionException $e) {
					throw new \RuntimeException(
						sprintf('Parameter "%s" type of "%s" can not be instanced: %s', $pName, $parameterType, $e->getMessage()),
						$e->getCode(),
						$e,
					);
				}
			}

			return $this->hydrateDataToObject(
				service: $service,
				className: $parameterType,
				params: $params[$pName] ?? $params,
				methodName: $methodName,
				recursionContext: $recursionContext,
			);
		}
		try {
			if (isset($params[$pName]) === true) {
				$type = $parameter->getType();
				if (((bool) $params[$pName]) === true) {
					return $this->fixType(
						$params[$pName],
						$type,
						$parameter->allowsNull(),
					);
				}
				if ($type !== null) {
					return $this->returnEmptyValue($service, $pName, $params[$pName], $type);
				}
			} elseif (
				$parameter->isOptional() === true
				&& $parameter->isDefaultValueAvailable() === true
			) {
				try {
					return $parameter->getDefaultValue();
				} catch (\Throwable) {
				}
			} elseif (
				array_key_exists($pName, $params)
				&& $params[$pName] === null
				&& $parameter->allowsNull() === true
			) {
				return null;
			}
		} catch (\LogicException) {
			throw new RuntimeInvokeException(
				$service,
				Helpers::formatServiceName($service) . ': Input value (' . get_debug_type($params[$pName]) . ') of parameter $' . $pName
				. ' is not compatible with native method argument type (' . $parameter->getType() . ').'
				. (isset($params[$pName]) && class_exists(Dumper::class)
					? "\n" . 'Input value: ' . trim(Dumper::toText($params[$pName]))
					: ''
				),
			);
		}

		$initiator = $parameter->getDeclaringClass();
		RuntimeInvokeException::parameterDoesNotSet(
			service: $service,
			parameter: $parameter->getName(),
			position: $parameter->getPosition(),
			method: $methodName ?? '',
			initiator: $initiator === null ? null : $initiator->getName(),
		);

		return null;
	}


	private function hydrateValueToEntity(\ReflectionProperty $property, mixed $instance, mixed $value): void
	{
		$setProperty = static function (\ReflectionProperty $property, mixed $instance, mixed $value): void {
			try {
				$property->setValue($instance, $value);
			} catch (\TypeError) {
				// Silence is golden.
			}
		};

		try {
			$setter = 'set' . $property->getName();
			if (method_exists($instance, $setter)) {
				$ref = new \ReflectionMethod($instance, $setter);
				$param = $ref->getParameters()[0] ?? null;
				if ($param === null) {
					$setProperty($property, $instance, $value);
				} elseif (($type = $param->getType()) === null) {
					trigger_error(sprintf('Parameter type of setter "%s" is undefined.', $setter));
					$setProperty($property, $instance, $value);
				} elseif ($value === null) {
					if ($type->allowsNull()) {
						/** @phpstan-ignore-next-line */
						$instance->$setter(null);
					} else {
						throw new \InvalidArgumentException(sprintf('Value for setter "%s" is required, but null given.', $setter));
					}
				} elseif (class_exists($type->getName())) { // native object type or entity
					$valueInstance = $this->tryMakeEntityInstance($type->getName(), $value);
					if ($valueInstance === null) {
						trigger_error(
							sprintf(
								'Mandatory value (type of "%s") for setter "%s" can not be casted to "%s"',
								get_debug_type($value),
								$setter,
								$type->getName(),
							) . (class_exists('\Tracy\Dumper')
								? sprintf(', because incompatible value "%s" given.', trim(\Tracy\Dumper::toText($value)))
								: '.'
							),
						);
					} else {
						/** @phpstan-ignore-next-line */
						$instance->$setter($valueInstance);
					}
				} else { // scalar type or unknown
					try {
						/** @phpstan-ignore-next-line */
						$instance->$setter($value);
					} catch (\TypeError $e) {
						trigger_error('Incompatible type: ' . $e->getMessage());
					}
				}
			} else {
				$setProperty($property, $instance, $value);
			}
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('UserException: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}


	private function tryMakeEntityInstance(string $className, mixed $value): ?object
	{
		// 1. Native DateTime object
		/** @phpstan-ignore-next-line */
		if (isset((class_implements($className) ?: [])[\DateTimeInterface::class])) {
			/** @phpstan-ignore-next-line */
			return (new \ReflectionClass($className))->newInstance($value);
		}

		// 2. Try find instance in project specific repository by id
		if ($this->projectEntityRepository !== null && (is_int($value) || is_string($value))) {
			return $this->projectEntityRepository->find($className, $value);
		}

		return null;
	}
}
