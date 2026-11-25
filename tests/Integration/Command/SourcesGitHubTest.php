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
use Github\Api\Repo;
use Github\Api\Repository\Releases;
use Github\Client as GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Sources::class)]
final class SourcesGitHubTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	private function makeReleaseFixture(string $version): array
	{
		return [
			'tag_name' => $version,
			'assets'   => [
				[
					'browser_download_url' => sprintf(
						'https://downloads.example.invalid/Joomla_%s-Stable-Full_Package.zip',
						$version
					),
				],
			],
		];
	}

	/**
	 * Builds a fake GitHub client whose api('repo')->releases()->all($org, $repo, $params) returns
	 * sequential pages from the supplied $pages array.
	 *
	 * @param  array  $pages  Array of arrays (one entry per page). Empty array marks end of pagination.
	 */
	private function makeGitHubClientReturningPages(array $pages): GitHubClient
	{
		$releases = $this->createMock(Releases::class);
		$releases->method('all')->willReturnCallback(
			function (string $org, string $repo, array $params) use (&$pages): array {
				$page = $params['page'] ?? 1;
				return $pages[$page - 1] ?? [];
			}
		);

		$repoApi = $this->createMock(Repo::class);
		$repoApi->method('releases')->willReturn($releases);

		$client = $this->createMock(GitHubClient::class);
		$client->method('api')->willReturn($repoApi);

		return $client;
	}

	private function makeSources(
		\Joomla\Database\DatabaseDriver $db, GitHubClient $github, ?Generate $generate = null, ?Dump $dump = null
	): Sources
	{
		// Init the tables.
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/sources.sql'))->execute();
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/checksums.sql'))->execute();

		// HttpFactory not used when wordpress=false; pass a real instance but it won't be called.
		// We still need a non-null typed object: build it without invoking it.
		$httpFactory = $this->createMock(HttpFactory::class);

		// Generate / Dump are typed but not invoked when --process is false.
		$generate ??= $this->createMock(Generate::class);
		$dump     ??= $this->createMock(Dump::class);

		return new Sources($db, $httpFactory, $github, $generate, $dump);
	}

	public function testFetchAllJoomlaReleases(): void
	{
		$db = $this->makeDatabase();

		// 3 pages: page 1 full (100), page 2 full (100), page 3 partial (5)
		$pages = [
			array_map(fn(int $i) => $this->makeReleaseFixture(sprintf('5.0.%d', $i)), range(0, 99)),
			array_map(fn(int $i) => $this->makeReleaseFixture(sprintf('5.1.%d', $i)), range(0, 99)),
			array_map(fn(int $i) => $this->makeReleaseFixture(sprintf('5.2.%d', $i)), range(0, 4)),
		];

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages($pages));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: false, process: false, wordpress: false);

		$this->assertSame(0, $exit);
		$this->assertSame(
			205,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms = "joomla"')->loadResult()
		);
	}

	public function testLatestFlagLimitsToThirty(): void
	{
		$db = $this->makeDatabase();

		// With --latest, code requests per_page=30 and breaks after the first page,
		// so even if we return 50 in the first slot, only 30 fit the per-page contract.
		$pages = [
			array_map(fn(int $i) => $this->makeReleaseFixture(sprintf('4.4.%d', $i)), range(0, 29)),
		];

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages($pages));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: false, wordpress: false);

		$this->assertSame(0, $exit);
		$this->assertSame(
			30,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms = "joomla"')->loadResult()
		);
	}

	public function testReleaseWithoutDownloadableAssetIsSkipped(): void
	{
		$db = $this->makeDatabase();

		// One valid release; one with no Full_Package zip asset; one with no assets at all.
		$pages = [
			[
				$this->makeReleaseFixture('5.0.0'),
				[
					'tag_name' => '5.0.1-rc',
					'assets'   => [
						[
							'browser_download_url' => 'https://downloads.example.invalid/Joomla_5.0.1-rc-Stable-Update_Package.zip',
						],
					],
				],
				[
					'tag_name' => '5.0.2-empty',
					'assets'   => [],
				],
			],
		];

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages($pages));
		$exit    = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: false, wordpress: false);

		$this->assertSame(0, $exit);

		$rows = $db->setQuery('SELECT version FROM sources WHERE cms = "joomla" ORDER BY version')->loadColumn();
		$this->assertSame(['5.0.0'], $rows);
	}

	public function testIdempotentReRun(): void
	{
		$db = $this->makeDatabase();

		$pages = [
			[
				$this->makeReleaseFixture('5.0.0'),
				$this->makeReleaseFixture('5.0.1'),
			],
		];

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages($pages));
		$sources(new ArrayInput([]), new NullOutput(), latest: true, process: false, wordpress: false);
		$sources(new ArrayInput([]), new NullOutput(), latest: true, process: false, wordpress: false);

		$this->assertSame(
			2,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms = "joomla"')->loadResult()
		);
	}

	/**
	 * The source row is recorded before Generate runs. If that run dies, the next run must still process the
	 * version, even though the source is already known.
	 */
	public function testProcessRetriesKnownSourceWithoutChecksums(): void
	{
		$db      = $this->makeDatabase();
		$release = $this->makeReleaseFixture('5.0.0');

		$generate = $this->createMock(Generate::class);
		$generate->expects($this->once())
			->method('__invoke')
			->with($this->anything(), $this->anything(), 'joomla', '5.0.0', false, true)
			->willReturn(0);

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages([[$release]]), $generate);
		$this->insertSource($db, '5.0.0', $release['assets'][0]['browser_download_url']);

		$exit = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: true, wordpress: false);

		$this->assertSame(0, $exit);
	}

	public function testProcessSkipsKnownSourceWithChecksums(): void
	{
		$db      = $this->makeDatabase();
		$release = $this->makeReleaseFixture('5.0.0');

		$generate = $this->createMock(Generate::class);
		$generate->expects($this->never())->method('__invoke');

		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages([[$release]]), $generate);
		$this->insertSource($db, '5.0.0', $release['assets'][0]['browser_download_url']);

		$checksum = (object) [
			'cms'           => 'joomla',
			'version'       => '5.0.0',
			'filename'      => 'index.php',
			'md5'           => str_repeat('a', 32),
			'sha1'          => str_repeat('a', 40),
			'sha256'        => str_repeat('a', 64),
			'sha512'        => str_repeat('a', 128),
			'md5_squash'    => str_repeat('b', 32),
			'sha1_squash'   => str_repeat('b', 40),
			'sha256_squash' => str_repeat('b', 64),
			'sha512_squash' => str_repeat('b', 128),
		];
		$db->insertObject('checksums', $checksum);

		$exit = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: true, wordpress: false);

		$this->assertSame(0, $exit);
	}

	/**
	 * One version failing (e.g. a download error) must not stop the other versions from being processed, nor the
	 * dump from being written. The command still reports the failure through its exit code.
	 */
	public function testFailingVersionDoesNotStopOtherVersionsOrTheDump(): void
	{
		$db        = $this->makeDatabase();
		$processed = [];

		$generate = $this->createMock(Generate::class);
		$generate->method('__invoke')->willReturnCallback(
			function ($input, $output, string $cms, string $version) use (&$processed): int {
				$processed[] = $version;

				if ($version === '5.0.1')
				{
					throw new \RuntimeException('Simulated download failure');
				}

				return 0;
			}
		);

		$dump = $this->createMock(Dump::class);
		$dump->expects($this->once())->method('__invoke')->willReturn(0);

		$pages   = [[$this->makeReleaseFixture('5.0.1'), $this->makeReleaseFixture('5.0.0')]];
		$sources = $this->makeSources($db, $this->makeGitHubClientReturningPages($pages), $generate, $dump);

		$exit = $sources(
			new ArrayInput([]), new NullOutput(), latest: true, process: true, dump: '/nonexistent', wordpress: false
		);

		$this->assertSame(['5.0.1', '5.0.0'], $processed);
		$this->assertSame(1, $exit, 'A failed version must be reported through the exit code');
	}

	/**
	 * An empty release list (e.g. a GitHub hiccup) must be handled without PHP warnings.
	 */
	public function testEmptyReleaseListRaisesNoWarnings(): void
	{
		$db       = $this->makeDatabase();
		$sources  = $this->makeSources($db, $this->makeGitHubClientReturningPages([[]]));
		$warnings = [];

		set_error_handler(
			function (int $errno, string $message) use (&$warnings): bool {
				$warnings[] = $message;

				return true;
			},
			E_WARNING | E_NOTICE
		);

		try
		{
			$exit = $sources(new ArrayInput([]), new NullOutput(), latest: true, process: true, wordpress: false);
		}
		finally
		{
			restore_error_handler();
		}

		$this->assertSame(0, $exit);
		$this->assertSame([], $warnings);
	}

	private function insertSource(\Joomla\Database\DatabaseDriver $db, string $version, string $url): void
	{
		$source = (object) [
			'cms'     => 'joomla',
			'version' => $version,
			'url'     => $url,
		];
		$db->insertObject('sources', $source);
	}
}
