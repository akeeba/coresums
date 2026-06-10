<?php
/*
 * @package   coresums-tests
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\HttpFactory;

use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

#[CoversClass(HttpFactory::class)]
class HttpFactoryTest extends TestCase
{
	private string $tmpCacheDir;

	private FilesystemAdapter $cachePool;

	protected function setUp(): void
	{
		$this->tmpCacheDir = sys_get_temp_dir() . '/coresums-httpfactory-' . bin2hex(random_bytes(6));
		mkdir($this->tmpCacheDir, 0755, true);

		$this->cachePool = new FilesystemAdapter(
			namespace: '',
			defaultLifetime: 3600,
			directory: $this->tmpCacheDir
		);

		// Reset HttpFactory's internal singleton cache between tests to avoid cross-test bleed.
		$ref = new \ReflectionClass(HttpFactory::class);
		$prop = $ref->getProperty('instances');
		$prop->setAccessible(true);
		$prop->setValue(null, []);
	}

	protected function tearDown(): void
	{
		$this->cachePool->clear();
		$this->rrmdir($this->tmpCacheDir);
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		foreach (scandir($dir) as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $entry;

			if (is_dir($path))
			{
				$this->rrmdir($path);
			}
			else
			{
				@unlink($path);
			}
		}

		@rmdir($dir);
	}

	private function makeContainer(): Container
	{
		$container = new Container();
		// Override the cachePool service with our temp-directory-backed FilesystemAdapter.
		// Pimple allows overriding a service definition before it has been resolved.
		$container['cachePool'] = function () {
			return $this->cachePool;
		};

		return $container;
	}

	/**
	 * Build a HandlerStack wrapping the given MockHandler so we can supply it
	 * to HttpFactory::makeClient() as the base $stack — the factory will then
	 * push the cache middleware onto it.
	 *
	 * NOTE: HttpFactory::makeClient() calls serialize($stack) to compute its
	 * singleton signature, which blows up on closures. HandlerStack::create()
	 * pushes default middleware (closures) onto the stack, so we hand back a
	 * bare HandlerStack with just the MockHandler as the leaf handler — no
	 * middleware, hence no closures — so serialize() does not throw.
	 */
	private function makeStack(MockHandler $mock): HandlerStack
	{
		return new HandlerStack($mock);
	}

	public function testMakeClientReturnsGuzzleClient(): void
	{
		$factory = new HttpFactory($this->makeContainer());

		$client = $factory->makeClient(cache: false, singleton: false);

		$this->assertInstanceOf(Client::class, $client);
	}

	public function testClientCachesGetResponses(): void
	{
		// Headers that satisfy PrivateCacheStrategy so the response is cached.
		$cacheableHeaders = [
			'Cache-Control' => 'private, max-age=3600',
			'Content-Type'  => 'text/plain',
		];

		$mock = new MockHandler([
			new Response(200, $cacheableHeaders, 'first-body'),
			new Response(200, $cacheableHeaders, 'second-body'),
		]);

		$factory = new HttpFactory($this->makeContainer());
		$client  = $factory->makeClient(
			stack: $this->makeStack($mock),
			cache: true,
			singleton: false
		);

		$first  = (string) $client->get('http://example.test/resource')->getBody();
		$second = (string) $client->get('http://example.test/resource')->getBody();

		$this->assertSame('first-body', $first);
		$this->assertSame(
			'first-body',
			$second,
			'Second GET to the same URL should be served from cache, not hit the transport again.'
		);
		// The MockHandler should still have one queued response left, proving the
		// second request never went through the transport.
		$this->assertSame(1, $mock->count(), 'Cache should have absorbed the second request.');
	}

	public function testCacheBypassedForNonCacheableMethods(): void
	{
		$cacheableHeaders = [
			'Cache-Control' => 'private, max-age=3600',
			'Content-Type'  => 'text/plain',
		];

		$mock = new MockHandler([
			new Response(200, $cacheableHeaders, 'post-1'),
			new Response(200, $cacheableHeaders, 'post-2'),
		]);

		$factory = new HttpFactory($this->makeContainer());
		$client  = $factory->makeClient(
			stack: $this->makeStack($mock),
			cache: true,
			singleton: false
		);

		$first  = (string) $client->post('http://example.test/resource', ['body' => 'payload'])->getBody();
		$second = (string) $client->post('http://example.test/resource', ['body' => 'payload'])->getBody();

		$this->assertSame('post-1', $first);
		$this->assertSame('post-2', $second, 'POST responses must not be cached.');
		$this->assertSame(0, $mock->count(), 'Both POSTs should have hit the transport.');
	}

	public function testCacheKeyIncludesUrl(): void
	{
		$cacheableHeaders = [
			'Cache-Control' => 'private, max-age=3600',
			'Content-Type'  => 'text/plain',
		];

		$mock = new MockHandler([
			new Response(200, $cacheableHeaders, 'body-A'),
			new Response(200, $cacheableHeaders, 'body-B'),
		]);

		$factory = new HttpFactory($this->makeContainer());
		$client  = $factory->makeClient(
			stack: $this->makeStack($mock),
			cache: true,
			singleton: false
		);

		$a = (string) $client->get('http://example.test/alpha')->getBody();
		$b = (string) $client->get('http://example.test/beta')->getBody();

		$this->assertSame('body-A', $a);
		$this->assertSame('body-B', $b, 'Different URLs must produce different cache keys.');
		$this->assertSame(0, $mock->count(), 'Both distinct URLs should have hit the transport.');
	}
}
