<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Github\Client as GitHubClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Sources::class)]
final class SourcesWordPressTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	/**
	 * Build an HttpFactory test double whose makeClient() returns a Guzzle Client
	 * backed by the supplied MockHandler.
	 */
	private function makeHttpFactory(MockHandler $handler): HttpFactory
	{
		return new class($handler) extends HttpFactory {
			public function __construct(private MockHandler $mockHandler)
			{
				// Intentionally skip parent constructor; this stub never touches the container.
			}

			public function makeClient(
				?HandlerStack $stack = null,
				array         $clientOptions = [],
				bool          $cache = true,
				?int          $cacheTTL = null,
				bool          $singleton = true
			): GuzzleClient
			{
				return new GuzzleClient(['handler' => HandlerStack::create($this->mockHandler)]);
			}
		};
	}

	private function makeSources(\Joomla\Database\DatabaseDriver $db, HttpFactory $factory): Sources
	{
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/sources.sql'))->execute();

		$github   = $this->createMock(GitHubClient::class);
		$generate = $this->createMock(Generate::class);
		$dump     = $this->createMock(Dump::class);

		return new Sources($db, $factory, $github, $generate, $dump);
	}

	/**
	 * Realistic-shape payload from https://api.wordpress.org/core/stable-check/1.0/
	 * (status tags: 'latest', 'outdated', 'insecure'). Trimmed to a handful of versions.
	 */
	private function wordPressVersionsPayload(): string
	{
		return (string) file_get_contents(__DIR__ . '/../../fixtures/wordpress-versions.json');
	}

	public function testFetchWordPressVersions(): void
	{
		$db = $this->makeDatabase();

		$handler = new MockHandler([
			new Response(200, [], $this->wordPressVersionsPayload()),
		]);

		$sources = $this->makeSources($db, $this->makeHttpFactory($handler));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: false, process: false, joomla: false);

		$this->assertSame(0, $exit);

		$rows = $db->setQuery('SELECT version FROM sources WHERE cms = "wordpress" ORDER BY version')->loadColumn();
		// All five fixture versions land in the table.
		$this->assertSame(['4.9.25', '5.8.10', '6.3.2', '6.4.1', '6.4.2'], $rows);

		// URL pattern uses the wordpress.org direct download URL.
		$url = $db->setQuery('SELECT url FROM sources WHERE cms = "wordpress" AND version = "6.4.2"')->loadResult();
		$this->assertSame('https://wordpress.org/wordpress-6.4.2.zip', $url);
	}

	public function testLatestFlagLimitsWordPress(): void
	{
		$db = $this->makeDatabase();

		$handler = new MockHandler([
			new Response(200, [], $this->wordPressVersionsPayload()),
		]);

		$sources = $this->makeSources($db, $this->makeHttpFactory($handler));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: false, joomla: false);

		$this->assertSame(0, $exit);

		// In the fixture, only 6.4.1 (outdated) and 6.4.2 (latest) carry those status tags.
		$rows = $db->setQuery('SELECT version FROM sources WHERE cms = "wordpress" ORDER BY version')->loadColumn();
		$this->assertSame(['6.4.1', '6.4.2'], $rows);
	}

	public function testWordPressApiBadJsonReturnsEmpty(): void
	{
		// NOTE: The non-200 status branch in getWordPressVersions() is effectively dead code under
		// the default Guzzle client (HTTP errors throw ServerException/ClientException before the
		// status code is inspected). This test exercises the JSON-decode error branch instead,
		// which is reachable via a 200 response with a malformed body.
		$db = $this->makeDatabase();

		$handler = new MockHandler([
			new Response(200, [], 'this is not json'),
		]);

		$sources = $this->makeSources($db, $this->makeHttpFactory($handler));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: false, process: false, joomla: false);

		$this->assertSame(0, $exit);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms = "wordpress"')->loadResult()
		);
	}
}
