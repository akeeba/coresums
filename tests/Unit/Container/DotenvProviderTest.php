<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\DotenvProvider;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

#[CoversClass(DotenvProvider::class)]
class DotenvProviderTest extends TestCase
{
	/**
	 * The provider only constructs a Dotenv instance; it does not call load().
	 * Resolving the 'dotenv' service should therefore not throw, even with no .env present.
	 *
	 * Note (divergence from plan): DotenvProvider hard-codes the path to src/Container,
	 * so we cannot point it at a temp directory without modifying production code.
	 * We therefore only assert the service factory returns a Dotenv instance and does
	 * not throw. Populating $_ENV would require calling load() on a real .env, which
	 * is not exercised by the provider itself.
	 */
	public function testProviderReturnsDotenvWithoutThrowing(): void
	{
		$c = new Container();
		$c->register(new DotenvProvider());

		$this->assertContains('dotenv', $c->keys());
		$this->assertInstanceOf(Dotenv::class, $c['dotenv']);
	}

	public function testDotenvCanLoadFromStubbedDirectory(): void
	{
		// Demonstrate that, given a directory containing a .env, Dotenv populates $_ENV.
		// This documents the intended behavior even though the provider itself does not call load().
		$tmpDir = sys_get_temp_dir() . '/coresums-dotenv-' . uniqid();
		mkdir($tmpDir);
		$envKey = 'CORESUMS_TEST_DOTENV_' . strtoupper(bin2hex(random_bytes(4)));
		file_put_contents($tmpDir . '/.env', $envKey . '=hello-world' . PHP_EOL);

		try
		{
			$dotenv = Dotenv::createImmutable($tmpDir);
			$dotenv->load();

			$this->assertSame('hello-world', $_ENV[$envKey] ?? null);
		}
		finally
		{
			unset($_ENV[$envKey]);
			@unlink($tmpDir . '/.env');
			@rmdir($tmpDir);
		}
	}
}
