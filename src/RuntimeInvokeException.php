<?php

declare(strict_types=1);

namespace Baraja;


final class RuntimeInvokeException extends \RuntimeException
{

	/** @var Service|null */
	private $service;

	/** @var string|null */
	private $method;

	/** @var mixed[]|null */
	private $params;


	/**
	 * @param Service|null $service
	 * @param string $message
	 * @param \Throwable|null $previous
	 */
	public function __construct(?Service $service, string $message, ?\Throwable $previous = null)
	{
		$this->service = $service;
		parent::__construct($message, 500, $previous);
	}


	/**
	 * @param Service $service
	 * @param string $parameter
	 * @param int $position
	 * @param string $method
	 */
	public static function parameterDoesNotSet(Service $service, string $parameter, int $position, string $method): void
	{
		$methodParams = '';
		try { // Rewrite to real method name + try render method parameters.
			$ref = new \ReflectionMethod($service, $method);
			$method = $ref->getName();
			foreach ($ref->getParameters() as $refParameter) {
				$methodParams .= ($methodParams !== '' ? ', ' : '')
					. (($type = $refParameter->getType()) !== null ? $type->getName() . ' ' : '')
					. '$' . $refParameter->getName();
			}
		} catch (\ReflectionException $e) {
		}

		throw new self(
			$service, $service . ': Parameter $' . $parameter . ' of method ' . $method . '(' . $methodParams . ') '
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
			$service, $service . ': Api parameter "data" must be type of "array". '
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
			$service, $service . ': Parameter "' . $parameter . '" must be object '
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
			$service, $service . ': Can not create default empty value for parameter "' . $parameter . '"'
			. ' type "' . $typeName . '" given.'
		);
	}


	/**
	 * @param Service $service
	 * @param string $className
	 */
	public static function entityClassDoesNotExist(Service $service, string $className): void
	{
		throw new self($service, $service . ': Entity class "' . $className . '" does not exist.');
	}


	/**
	 * @param Service $service
	 * @param string $className
	 * @param string[] $stackTrace
	 */
	public static function circularDependency(Service $service, string $className, array $stackTrace): void
	{
		throw new self(
			$service, $service . ': Circular dependence has been discovered, because entity "' . $className . '" already was instanced.'
			. "\n" . 'Current stack trace: ' . implode(', ', $stackTrace)
		);
	}


	/**
	 * @param Service $service
	 * @param string $type
	 */
	public static function propertyTypeIsNotSupported(Service $service, string $type): void
	{
		throw new self($service, $service . ': Property type "' . $type . '" is not supported. Did you mean some scalar type or entity?');
	}


	/**
	 * @param Service $service
	 * @param string $entityName
	 * @param string $propertyName
	 * @param bool $allowsScalar
	 * @param string $requiredType
	 */
	public static function propertyIsRequired(Service $service, string $entityName, string $propertyName, bool $allowsScalar, string $requiredType): void
	{
		throw new self(
			$service, $service . ': Property "' . $propertyName . '" of entity "' . $entityName . '" is required. '
			. 'Please set some' . ($allowsScalar === true ? ' scalar' : '') . ' value type of "' . $requiredType . '".'
		);
	}


	/**
	 * @return Service|null
	 */
	public function getService(): ?Service
	{
		return $this->service;
	}


	/**
	 * @return string|null
	 */
	public function getMethod(): ?string
	{
		return $this->method;
	}


	/**
	 * @param string|null $method
	 * @return RuntimeInvokeException
	 */
	public function setMethod(?string $method): self
	{
		$this->method = $method;

		return $this;
	}


	/**
	 * @return mixed[]|null
	 */
	public function getParams(): ?array
	{
		return $this->params;
	}


	/**
	 * @param mixed[]|null $params
	 * @return RuntimeInvokeException
	 */
	public function setParams(?array $params): self
	{
		$this->params = $params;

		return $this;
	}
}