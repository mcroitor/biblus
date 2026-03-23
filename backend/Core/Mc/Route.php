<?php

namespace Core\Mc;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
/**
 * Route attribute definition for function/static-method handlers.
 */
class Route
{
    /**
     * Route path or legacy label.
     * Examples: `about`, `/api/users`, `/api/users/{id}`
     * @var string
     */
	public string $path;

	/**
	 * HTTP methods bound to the route.
	 * Defaults to `['GET']`.
	 * @var array
	 */
	public array $methods;

	/**
	 * @param string $path
	 * @param array $methods
	 */
	public function __construct(string $path, array $methods = ['GET'])
	{
		$this->path = $path;

		$normalizedMethods = [];
		foreach ($methods as $method) {
			$normalizedMethod = strtoupper(trim((string) $method));
			if ($normalizedMethod !== '') {
				$normalizedMethods[] = $normalizedMethod;
			}
		}

		if (empty($normalizedMethods)) {
			$normalizedMethods = ['GET'];
		}

		$this->methods = array_values(array_unique($normalizedMethods));
	}
}
