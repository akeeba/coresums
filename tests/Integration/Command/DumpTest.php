<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Init;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Dump::class)]
final class DumpTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testDumpWithoutFlagsReportsNothingToDo(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-noop-');

		$this->seedSchema($db);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, false, true, false);

		$this->assertSame(1, $exit);
	}

	public function testDumpSourcesWritesJsonFile(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-sources-');

		$this->seedSchema($db);
		$row1 = (object) [
			'cms'     => 'joomla',
			'version' => '5.0.1',
			'url'     => 'https://example.invalid/joomla-5.0.1.zip',
		];
		$db->insertObject('sources', $row1);
		$row2 = (object) [
			'cms'     => 'joomla',
			'version' => '5.0.0',
			'url'     => 'https://example.invalid/joomla-5.0.0.zip',
		];
		$db->insertObject('sources', $row2);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, true, true, false);

		$this->assertSame(0, $exit);
		$this->assertFileExists($outDir . '/sources.json');

		$decoded = json_decode((string) file_get_contents($outDir . '/sources.json'), true);

		$this->assertIsArray($decoded);
		$this->assertCount(2, $decoded);
		$this->assertSame('5.0.0', $decoded[0]['version'], 'sources are sorted by version');
		$this->assertSame('5.0.1', $decoded[1]['version']);
	}

	public function testDumpAndReimportRoundTripPreservesChecksums(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-roundtrip-');

		$this->seedSchema($db);
		$source = (object) [
			'cms'     => 'joomla',
			'version' => '5.1.0',
			'url'     => 'https://example.invalid/joomla-5.1.0.zip',
		];
		$db->insertObject('sources', $source);

		$checksum = (object) [
			'cms'           => 'joomla',
			'version'       => '5.1.0',
			'filename'      => 'index.php',
			'md5'           => 'd41d8cd98f00b204e9800998ecf8427e',
			'sha1'          => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
			'sha256'        => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			'sha512'        => 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e',
			'md5_squash'    => '7215ee9c7d9dc229d2921a40e899ec5f',
			'sha1_squash'   => 'b858cb282617fb0956d960215c8e84d1ccf909c6',
			'sha256_squash' => '36a9e7f1c95b82ffb99743e0c5c4ce95d83c9a430aac59f84ef3cbfab6145068',
			'sha512_squash' => 'f90ddd77e400dfe6a3fcf479b00b1ee29e7015c5bb8cd70f5f15b4886cc339275ff553fc8a053f8ddc7324f45168cffaf81f8c3ac93996f6536eef38e5e40768',
		];
		$db->insertObject('checksums', $checksum);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, true, false, false);
		$this->assertSame(0, $exit);

		foreach (['md5', 'sha1', 'sha256', 'sha512', 'md5_squash', 'sha1_squash', 'sha256_squash', 'sha512_squash'] as $type)
		{
			$this->assertFileExists($outDir . '/joomla/5.1.0/' . $type . '.json');
		}

		$md5 = json_decode((string) file_get_contents($outDir . '/joomla/5.1.0/md5.json'), true);
		$this->assertSame(['index.php' => 'd41d8cd98f00b204e9800998ecf8427e'], $md5);

		$db2  = $this->makeDatabase();
		$init = new Init($db2);
		$exit = $init(new ArrayInput([]), new NullOutput(), $outDir);
		$this->assertSame(0, $exit);

		$row = $db2->setQuery(
			'SELECT * FROM checksums WHERE cms = "joomla" AND version = "5.1.0" AND filename = "index.php"'
		)->loadAssoc();

		$this->assertNotNull($row);
		$this->assertSame('d41d8cd98f00b204e9800998ecf8427e', $row['md5']);
		$this->assertSame('7215ee9c7d9dc229d2921a40e899ec5f', $row['md5_squash']);
	}

	private function seedSchema(\Joomla\Database\DatabaseDriver $db): void
	{
		$assets = realpath(__DIR__ . '/../../../assets');

		$db->setQuery(file_get_contents($assets . '/sources.sql'))->execute();
		$db->setQuery(file_get_contents($assets . '/checksums.sql'))->execute();
	}
}
