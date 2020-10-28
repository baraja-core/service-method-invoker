<?php

declare(strict_types=1);

namespace Baraja;


interface Service
{
	/** Return user friendly content or self name. It will be used for exceptions. */
	public function __toString(): string;
}
