<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Init;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Init::class)]
final class InitTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testInitCreatesEmptyDatabaseWhenNoFixturesProvided(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-empty-');

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), new NullOutput(), $sources);

		$this->assertSame(0, $exit);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult()
		);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM checksums')->loadResult()
		);
	}

	public function testInitImportsSourcesAndChecksumsFromJsonFixtures(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-fixtures-');

		file_put_contents($sources . '/sources.json', json_encode([
			[
				'cms'     => 'joomla',
				'version' => '5.0.0',
				'url'     => 'https://example.invalid/joomla-5.0.0.zip',
			],
		]));

		$cmsDir = $sources . '/joomla/5.0.0';
		mkdir($cmsDir, 0755, true);

		$fixtures = [
			'md5'           => ['index.php' => 'd41d8cd98f00b204e9800998ecf8427e'],
			'sha1'          => ['index.php' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
			'sha256'        => ['index.php' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
			'sha512'        => ['index.php' => 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e'],
			'md5_squash'    => ['index.php' => '7215ee9c7d9dc229d2921a40e899ec5f'],
			'sha1_squash'   => ['index.php' => 'b858cb282617fb0956d960215c8e84d1ccf909c6'],
			'sha256_squash' => ['index.php' => '36a9e7f1c95b82ffb99743e0c5c4ce95d83c9a430aac59f84ef3cbfab6145068'],
			'sha512_squash' => ['index.php' => 'f90ddd77e400dfe6a3fcf479b00b1ee29e7015c5bb8cd70f5f15b4886cc339275ff553fc8a053f8ddc7324f45168cffaf81f8c3ac93996f6536eef38e5e40768'],
		];

		foreach ($fixtures as $type => $rows)
		{
			file_put_contents($cmsDir . '/' . $type . '.json', json_encode($rows));
		}

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), new NullOutput(), $sources);

		$this->assertSame(0, $exit);

		$source = $db->setQuery('SELECT * FROM sources')->loadAssoc();
		$this->assertSame('joomla', $source['cms']);
		$this->assertSame('5.0.0', $source['version']);
		$this->assertSame('https://example.invalid/joomla-5.0.0.zip', $source['url']);

		$row = $db->setQuery('SELECT * FROM checksums WHERE filename = "index.php"')->loadAssoc();
		$this->assertNotNull($row);
		$this->assertSame($fixtures['md5']['index.php'], $row['md5']);
		$this->assertSame($fixtures['sha512_squash']['index.php'], $row['sha512_squash']);
	}

	public function testInitReadsGzippedSourcesFixture(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-gzip-');

		$payload = json_encode([
			[
				'cms'     => 'wordpress',
				'version' => '6.4.0',
				'url'     => 'https://example.invalid/wordpress-6.4.0.zip',
			],
		]);

		file_put_contents($sources . '/sources.json.gz', gzencode($payload));

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), new NullOutput(), $sources);

		$this->assertSame(0, $exit);
		$this->assertSame('wordpress', $db->setQuery('SELECT cms FROM sources')->loadResult());
	}
}
