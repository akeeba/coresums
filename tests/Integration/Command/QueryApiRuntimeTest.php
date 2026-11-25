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
use Akeeba\CoreSums\Command\Init;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\Command\Versions;
use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\Database\Schema;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Github\Api\Repo;
use Github\Api\Repository\Releases;
use Github\Client as GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Running the commands must not reach the deprecated getQuery(true), not even indirectly: joomla/database calls it
 * itself when DatabaseDriver::setQuery() is given a plain SQL string.
 *
 * joomla/database reports the deprecation with a silenced trigger_error(), which PHPUnit does not show; this test
 * installs its own handler, which still sees silenced errors.
 */
#[CoversClass(Init::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Dump::class)]
#[CoversClass(Generate::class)]
#[CoversClass(Sources::class)]
#[CoversClass(Versions::class)]
final class QueryApiRuntimeTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testCommandsDoNotUseDeprecatedGetQueryTrue(): void
	{
		$db          = $this->makeDatabase();
		$emptyInput  = $this->makeTempDir('coresums-queryapi-init-');
		$outDir      = $this->makeTempDir('coresums-queryapi-dump-');
		$in          = new ArrayInput([]);
		$out         = new NullOutput();
		$deprecated  = [];

		set_error_handler(
			function (int $errno, string $message) use (&$deprecated): bool {
				$caller = str_contains($message, 'createQuery') ? $this->deprecatedApiCaller(debug_backtrace()) : null;

				if ($caller !== null)
				{
					$deprecated[] = $caller;
				}

				return true;
			},
			E_USER_DEPRECATED
		);

		try
		{
			// Init creates the tables and the index.
			(new Init($db))($in, $out, $emptyInput);

			// Sources records a release and looks for its checksums.
			$releases = $this->createMock(Releases::class);
			$releases->method('all')->willReturn([
				[
					'tag_name' => '5.0.0',
					'assets'   => [
						['browser_download_url' => 'https://example.invalid/Joomla_5.0.0-Stable-Full_Package.zip'],
					],
				],
			]);
			$repoApi = $this->createMock(Repo::class);
			$repoApi->method('releases')->willReturn($releases);
			$gitHub = $this->createMock(GitHubClient::class);
			$gitHub->method('api')->willReturn($repoApi);

			(new Sources(
				$db, $this->createMock(HttpFactory::class), $gitHub, $this->createMock(Generate::class),
				$this->createMock(Dump::class)
			))($in, $out, latest: true, process: true, wordpress: false);

			// Generate looks up checksums and the download URL of a version it cannot download.
			(new Generate($db, new HttpFactory(new Container())))($in, $out, 'joomla', '9.9.9', false, true);

			// Dump reads the sources and the checksums.
			(new Dump($db))($in, $out, $outDir, true, false, false);

			// Versions lists the sources.
			(new Versions($db))($in, $out, 'joomla');
		}
		finally
		{
			restore_error_handler();
		}

		$this->assertSame(
			[], array_values(array_unique($deprecated)),
			'Use $db->createQuery() instead of $db->getQuery(true), and pass setQuery() a query object, not a string'
		);
	}

	/**
	 * Which of our source files made the deprecated call, if any.
	 *
	 * The deprecated getQuery(true) is reached either directly, or through DatabaseDriver::setQuery() given a string.
	 * Calls made from inside joomla/database itself (e.g. insertObject() reading the table columns) are its own
	 * business, not ours.
	 *
	 * @param   array  $trace  The backtrace at the deprecation notice
	 *
	 * @return  string|null  "file:line" of our offending call, NULL if the call did not come from our code
	 */
	private function deprecatedApiCaller(array $trace): ?string
	{
		$srcDir = realpath(__DIR__ . '/../../../src') . DIRECTORY_SEPARATOR;

		foreach ($trace as $frame)
		{
			if (!in_array($frame['function'] ?? '', ['getQuery', 'setQuery'], true))
			{
				continue;
			}

			$file = $frame['file'] ?? '';

			if (str_starts_with($file, $srcDir))
			{
				return substr($file, strlen($srcDir)) . ':' . $frame['line'];
			}

			// Called from joomla/database's own code: keep walking up only if this is the setQuery() -> getQuery() hop.
			if ($frame['function'] === 'getQuery' && str_ends_with($file, 'DatabaseDriver.php'))
			{
				continue;
			}

			return null;
		}

		return null;
	}
}
