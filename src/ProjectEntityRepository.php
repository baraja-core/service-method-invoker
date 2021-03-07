<?php

declare(strict_types=1);

namespace Baraja\ServiceMethodInvoker;


interface ProjectEntityRepository
{
	public function find(string $className, int|string $id): ?object;
}
