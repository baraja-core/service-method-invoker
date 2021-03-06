<?php

declare(strict_types=1);

namespace Baraja;


final class RuntimeInvokeException extends \RuntimeException
{
	private ?string $method = null;

	/** @var mixed[]|null */
	private ?array $params = null;


	public function __construct(
		private ?Service $service,
		string $message,
		?\Throwable $previous = null
	) {
		parent::__construct($message, 500, $previous);
	}


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
		} catch (\ReflectionException) {
			// Silence is golden.
		}

		throw new self(
			$service,
			$service . ': Parameter $' . $parameter . ' of method ' . $method . '(' . $methodParams . ') '
			. 'on position #' . $position . ' does not exist.',
		);
	}


	public static function propertyDataMustBeArray(Service $service, ?string $type): void
	{
		throw new self(
			$service,
			$service . ': Api parameter "data" must be type of "array". '
			. ($type === null ? 'No type has been defined. Did you set PHP 7 strict data types?' : 'Type "' . $type . '" given.'),
		);
	}


	public static function parameterMustBeObject(Service $service, string $parameter, string $class): void
	{
		throw new self(
			$service,
			$service . ': Parameter "' . $parameter . '" must be object '
			. 'of type "' . $class . '" but empty value given.',
		);
	}


	public static function canNotCreateEmptyValueByType(Service $service, string $parameter, string $typeName): void
	{
		throw new self(
			$service,
			$service . ': Can not create default empty value for parameter "' . $parameter . '"'
			. ' type "' . $typeName . '" given.',
		);
	}


	/**
	 * @param string[] $stackTrace
	 */
	public static function circularDependency(Service $service, string $className, array $stackTrace): void
	{
		throw new self(
			$service,
			$service . ': Circular dependence has been discovered, because entity "' . $className . '" already was instanced.'
			. "\n" . 'Current stack trace: ' . implode(', ', $stackTrace),
		);
	}


	public static function propertyTypeIsNotSupported(Service $service, string $type): void
	{
		throw new self($service, $service . ': Property type "' . $type . '" is not supported. Did you mean some scalar type or entity?');
	}


	public static function propertyIsRequired(
		Service $service,
		string $entityName,
		string $propertyName,
		bool $allowsScalar,
		string $requiredType
	): void {
		throw new self(
			$service,
			$service . ': Property "' . $propertyName . '" of entity "' . $entityName . '" is required. '
			. 'Please set some' . ($allowsScalar === true ? ' scalar' : '') . ' value type of "' . $requiredType . '".',
		);
	}


	public function getService(): ?Service
	{
		return $this->service;
	}


	public function getMethod(): ?string
	{
		return $this->method;
	}


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
	 */
	public function setParams(?array $params): self
	{
		$this->params = $params;

		return $this;
	}
}
