<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\Command;

use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Joomla\Database\Sqlite\SqliteDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(Generate::class)]
final class GenerateGetDownloadURLTest extends TestCase
{
	private array $tempFiles = [];

	private ?SqliteDriver $db = null;

	protected function tearDown(): void
	{
		if ($this->db !== null)
		{
			$this->db->disconnect();
			$this->db = null;
		}

		foreach ($this->tempFiles as $file)
		{
			if (file_exists($file))
			{
				@unlink($file);
			}
		}
		$this->tempFiles = [];
	}

	public function testReturnsUrlForKnownCmsVersion(): void
	{
		$generate = $this->makeGenerate();

		$this->insertSource('joomla', '5.0.0', 'https://example.invalid/joomla-5.0.0.zip');
		$this->insertSource('wordpress', '6.4.0', 'https://example.invalid/wordpress-6.4.0.zip');

		$this->assertSame(
			'https://example.invalid/joomla-5.0.0.zip',
			$this->callGetDownloadURL($generate, 'joomla', '5.0.0')
		);
		$this->assertSame(
			'https://example.invalid/wordpress-6.4.0.zip',
			$this->callGetDownloadURL($generate, 'wordpress', '6.4.0')
		);
	}

	public function testReturnsNullForUnknownPair(): void
	{
		$generate = $this->makeGenerate();

		$this->insertSource('joomla', '5.0.0', 'https://example.invalid/joomla-5.0.0.zip');

		$this->assertNull($this->callGetDownloadURL($generate, 'joomla', '99.0.0'));
		$this->assertNull($this->callGetDownloadURL($generate, 'drupal', '5.0.0'));
	}

	public function testReturnsNullWhenUrlColumnIsEmptyString(): void
	{
		$generate = $this->makeGenerate();

		// Insert a row whose URL column is an empty string. getDownloadURL uses `?: null`,
		// which coalesces '' to null.
		$this->insertSource('joomla', '4.0.0', '');

		$this->assertNull(
			$this->callGetDownloadURL($generate, 'joomla', '4.0.0'),
			'getDownloadURL should return null (not "") for an empty URL column.'
		);
	}

	private function makeGenerate(): Generate
	{
		$file              = tempnam(sys_get_temp_dir(), 'coresums-genurl-');
		$this->tempFiles[] = $file;

		$this->db = new SqliteDriver([
			'version'  => 3,
			'driver'   => 'sqlite',
			'database' => $file,
		]);

		// Use the project's schema files to set up the database.
		$schema = file_get_contents(__DIR__ . '/../../../assets/sources.sql');
		$this->db->setQuery($schema)->execute();

		$httpFactory = new HttpFactory(new Container());

		return new Generate($this->db, $httpFactory);
	}

	private function insertSource(string $cms, string $version, string $url): void
	{
		$row = (object) [
			'cms'     => $cms,
			'version' => $version,
			'url'     => $url,
		];

		// insertObject takes its second argument by reference — assign first.
		$this->db->insertObject('sources', $row);
	}

	private function callGetDownloadURL(Generate $generate, string $cms, string $version): ?string
	{
		$method = new ReflectionMethod(Generate::class, 'getDownloadURL');

		return $method->invoke($generate, $cms, $version);
	}
}
