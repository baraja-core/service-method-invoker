<?php

declare(strict_types=1);

namespace Baraja;


use Baraja\ServiceMethodInvoker\Helpers;

final class RuntimeInvokeException extends \RuntimeException
{
	private ?string $method = null;

	/** @var mixed[]|null */
	private ?array $params = null;


	public function __construct(
		private ?object $service,
		string $message,
		?\Throwable $previous = null
	) {
		parent::__construct($message, 500, $previous);
	}


	public static function parameterDoesNotSet(object $service, string $parameter, int $position, string $method, ?string $initiator = null): void
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
			Helpers::formatServiceName($service) . ': Parameter $' . $parameter
			. ($initiator !== null ? ' (declared on "' . $initiator . '")' : '') . ' of method ' . $method
			. '(' . $methodParams . ') on position #' . $position . ' does not exist.',
		);
	}


	public static function propertyIsRequired(
		object $service,
		string $entityName,
		string $propertyName,
		bool $allowsScalar,
		string $requiredType
	): void {
		throw new self(
			$service,
			Helpers::formatServiceName($service) . ': Property "' . $propertyName . '" of entity "' . $entityName . '" is required. '
			. 'Please set some' . ($allowsScalar === true ? ' scalar' : '') . ' value type of "' . $requiredType . '".',
		);
	}


	public function getService(): ?object
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
