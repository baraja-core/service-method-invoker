<?php

declare(strict_types=1);

namespace Baraja;


final class RuntimeInvokeException extends \RuntimeException
{

	/**
	 * @param Service $service
	 * @param string $parameter
	 * @param int $position
	 * @param string $method
	 */
	public static function parameterDoesNotSet(Service $service, string $parameter, int $position, string $method): void
	{
		throw new self(
			$service . ': Parameter $' . $parameter . ' of method "' . $method . '" '
			. 'on position #' . $position . ' does not exist.'
		);
	}


	/**
	 * @param Service $service
	 * @param string|null $type
	 */
	public static function propertyDataMustBeArray(Service $service, ?string $type): void
	{
		throw new self(
			$service . ': Api parameter "data" must be type of "array". '
			. ($type === null ? 'No type has been defined. Did you set PHP 7 strict data types?' : 'Type "' . $type . '" given.')
		);
	}


	/**
	 * @param Service $service
	 * @param string $parameter
	 * @param string $class
	 */
	public static function parameterMustBeObject(Service $service, string $parameter, string $class): void
	{
		throw new self(
			$service . ': Parameter "' . $parameter . '" must be object '
			. 'of type "' . $class . '" but empty value given.'
		);
	}


	/**
	 * @param Service $service
	 * @param string $parameter
	 * @param string $typeName
	 */
	public static function canNotCreateEmptyValueByType(Service $service, string $parameter, string $typeName): void
	{
		throw new self(
			$service . ': Can not create default empty value for parameter "' . $parameter . '"'
			. ' type "' . $typeName . '" given.'
		);
	}


	/**
	 * @param \Throwable $e
	 */
	public static function reflectionException(\Throwable $e): void
	{
		throw new self($e->getMessage(), $e->getCode(), $e);
	}
}