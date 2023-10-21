<?php

namespace Akeeba\CoreSums\Container;

use Dotenv\Dotenv;
use Github\Client as GitHubClient;
use Joomla\Database\Sqlite\SqliteDriver;
use Pimple\Container as PimpleContainer;
use Psr\Cache\CacheItemPoolInterface;

/**
 * DI container / service locator
 *
 * @property-read SqliteDriver           $db
 * @property-read Dotenv                 $dotenv
 * @property-read CacheItemPoolInterface $cachePool
 * @property-read GitHubClient           $gitHub
 *
 * @since  1.0.0
 */
class Container extends PimpleContainer
{
	public function __construct(array $values = [])
	{
		parent::__construct($values);

		$this->register(new DatabaseProvider());
		$this->register(new DotenvProvider());
		$this->register(new CachePoolProvider());
		$this->register(new GitHubProvider());

		$this->register(new CommandsProvider());
	}

	/**
	 * Magic getter for alternative syntax, e.g. $container->foo instead of $container['foo']
	 *
	 * @param   string  $name
	 *
	 * @return  mixed
	 *
	 * @throws \InvalidArgumentException if the identifier is not defined
	 */
	#[\ReturnTypeWillChange]
	function __get($name)
	{
		return $this->offsetGet($name);
	}

	/**
	 * Magic setter for alternative syntax, e.g. $container->foo instead of $container['foo']
	 *
	 * @param   string  $name   The unique identifier for the parameter or object
	 * @param   mixed   $value  The value of the parameter or a closure for a service
	 *
	 * @throws \RuntimeException Prevent override of a frozen service
	 */
	#[\ReturnTypeWillChange]
	function __set($name, $value)
	{
		$this->offsetSet($name, $value);
	}
}