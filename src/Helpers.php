<?php

declare(strict_types=1);

namespace Baraja\ServiceMethodInvoker;


final class Helpers
{
	private const BUILTIN_TYPES = [
		'string' => 1, 'int' => 1, 'float' => 1, 'bool' => 1, 'array' => 1, 'object' => 1,
		'callable' => 1, 'iterable' => 1, 'void' => 1, 'null' => 1, 'mixed' => 1, 'false' => 1,
	];


	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . get_class($this) . ' is static and cannot be instantiated.');
	}


	public static function resolvePropertyType(\ReflectionProperty $property): ?string
	{
		if ($classType = self::getPropertyType($property)) {
			return $classType;
		}
		if ($classType = self::parseAnnotation($property, 'var')) {
			return self::expandClassName($classType, self::getPropertyDeclaringClass($property));
		}

		return null;
	}


	/**
	 * Returns the type of given property and normalizes `self` and `parent` to the actual class names.
	 * If the property does not have a type, it returns null.
	 */
	public static function getPropertyType(\ReflectionProperty $prop): ?string
	{
		$type = PHP_VERSION_ID >= 70400 ? $prop->getType() : null;

		return $type instanceof \ReflectionNamedType
			? self::normalizeType($type->getName(), $prop)
			: null;
	}


	/**
	 * Returns an annotation value.
	 *
	 * @param  \ReflectionFunctionAbstract|\ReflectionProperty|\ReflectionClass $ref
	 */
	public static function parseAnnotation(\Reflector $ref, string $name): ?string
	{
		if (!self::areCommentsAvailable()) {
			throw new \RuntimeException('You have to enable phpDoc comments in opcode cache.');
		}
		$re = '#[\s*]@' . preg_quote($name, '#') . '(?=\s|$)(?:[ \t]+([^@\s]\S*))?#';
		if ($ref->getDocComment() && preg_match($re, trim($ref->getDocComment(), '/*'), $m)) {
			return $m[1] ?? '';
		}

		return null;
	}


	/**
	 * Returns a reflection of a class or trait that contains a declaration of given property. Property can also be declared in the trait.
	 */
	private static function getPropertyDeclaringClass(\ReflectionProperty $prop): \ReflectionClass
	{
		foreach ($prop->getDeclaringClass()->getTraits() as $trait) {
			if ($trait->hasProperty($prop->name)
				// doc-comment guessing as workaround for insufficient PHP reflection
				&& $trait->getProperty($prop->name)->getDocComment() === $prop->getDocComment()
			) {
				return self::getPropertyDeclaringClass($trait->getProperty($prop->name));
			}
		}

		return $prop->getDeclaringClass();
	}


	/**
	 * Finds out if reflection has access to PHPdoc comments. Comments may not be available due to the opcode cache.
	 */
	private static function areCommentsAvailable(): bool
	{
		static $res;

		try {
			return $res ?? $res = (bool) (new \ReflectionMethod(__METHOD__))->getDocComment();
		} catch (\ReflectionException $e) {
			throw new \RuntimeException('Reflection is broken: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}


	/**
	 * @param \ReflectionMethod|\ReflectionParameter|\ReflectionProperty $reflection
	 */
	private static function normalizeType(string $type, $reflection): string
	{
		if (($lower = strtolower($type)) === 'self' || $lower === 'static') {
			return $reflection->getDeclaringClass()->name;
		}
		if ($lower === 'parent' && $reflection->getDeclaringClass()->getParentClass()) {
			return $reflection->getDeclaringClass()->getParentClass()->name;
		}

		return $type;
	}


	/**
	 * Expands the name of the class to full name in the given context of given class.
	 * Thus, it returns how the PHP parser would understand $name if it were written in the body of the class $context.
	 */
	private static function expandClassName(string $name, \ReflectionClass $context): string
	{
		$lower = strtolower($name);
		if (empty($name)) {
			throw new \InvalidArgumentException('Class name must not be empty.');
		}
		if (isset(self::BUILTIN_TYPES[$lower])) {
			return $lower;
		}
		if ($lower === 'self' || $lower === 'static') {
			return $context->name;
		}
		if ($name[0] === '\\') { // fully qualified name
			return ltrim($name, '\\');
		}

		$uses = self::getUseStatements($context);
		$parts = explode('\\', $name, 2);
		if (isset($uses[$parts[0]])) {
			$parts[0] = $uses[$parts[0]];

			return implode('\\', $parts);
		}
		if ($context->inNamespace()) {
			return $context->getNamespaceName() . '\\' . $name;
		}

		return $name;
	}


	/** @return string[] of [alias => class] */
	private static function getUseStatements(\ReflectionClass $class): array
	{
		if ($class->isAnonymous()) {
			throw new \LogicException('Anonymous classes are not supported.');
		}
		static $cache = [];
		if (!isset($cache[$name = $class->name])) {
			if ($class->isInternal()) {
				$cache[$name] = [];
			} else {
				$code = file_get_contents($class->getFileName());
				$cache = self::parseUseStatements($code, $name) + $cache;
			}
		}

		return $cache[$name];
	}


	/**
	 * Parses PHP code to [class => [alias => class, ...]]
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function parseUseStatements(string $code, string $forClass = null): array
	{
		try {
			$tokens = token_get_all($code, TOKEN_PARSE);
		} catch (\ParseError $e) {
			trigger_error($e->getMessage(), E_USER_NOTICE);
			$tokens = [];
		}
		$namespace = $class = $classLevel = $level = null;
		$res = $uses = [];

		$nameTokens = PHP_VERSION_ID < 80000
			? [T_STRING, T_NS_SEPARATOR]
			: [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

		while ($token = current($tokens)) {
			next($tokens);
			switch (is_array($token) ? $token[0] : $token) {
				case T_NAMESPACE:
					$namespace = ltrim(self::fetch($tokens, $nameTokens) . '\\', '\\');
					$uses = [];
					break;

				case T_CLASS:
				case T_INTERFACE:
				case T_TRAIT:
					if ($name = self::fetch($tokens, T_STRING)) {
						$class = $namespace . $name;
						$classLevel = $level + 1;
						$res[$class] = $uses;
						if ($class === $forClass) {
							return $res;
						}
					}
					break;

				case T_USE:
					while (!$class && ($name = self::fetch($tokens, $nameTokens))) {
						$name = ltrim($name, '\\');
						if (self::fetch($tokens, '{')) {
							while ($suffix = self::fetch($tokens, $nameTokens)) {
								if (self::fetch($tokens, T_AS)) {
									$uses[self::fetch($tokens, T_STRING)] = $name . $suffix;
								} else {
									$tmp = explode('\\', $suffix);
									$uses[end($tmp)] = $name . $suffix;
								}
								if (!self::fetch($tokens, ',')) {
									break;
								}
							}

						} elseif (self::fetch($tokens, T_AS)) {
							$uses[self::fetch($tokens, T_STRING)] = $name;

						} else {
							$tmp = explode('\\', $name);
							$uses[end($tmp)] = $name;
						}
						if (!self::fetch($tokens, ',')) {
							break;
						}
					}
					break;

				case T_CURLY_OPEN:
				case T_DOLLAR_OPEN_CURLY_BRACES:
				case '{':
					$level++;
					break;

				case '}':
					if ($level === $classLevel) {
						$class = $classLevel = null;
					}
					$level--;
			}
		}

		return $res;
	}


	/**
	 * @param mixed[] $take
	 */
	private static function fetch(array &$tokens, $take): ?string
	{
		$res = null;
		while ($token = current($tokens)) {
			[$token, $s] = is_array($token) ? $token : [$token, $token];
			if (in_array($token, (array) $take, true)) {
				$res .= $s;
			} elseif (!in_array($token, [T_DOC_COMMENT, T_WHITESPACE, T_COMMENT], true)) {
				break;
			}
			next($tokens);
		}

		return $res;
	}
}
