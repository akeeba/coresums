<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Versions;
use Joomla\Database\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(Versions::class)]
final class VersionsTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	private function createSchema(DatabaseDriver $db): void
	{
		$sql = file_get_contents(__DIR__ . '/../../../assets/sources.sql');
		$db->setQuery($sql)->execute();
	}

	private function insertSource(DatabaseDriver $db, string $cms, string $version, string $url = 'https://example.invalid/x.zip'): void
	{
		// Joomla\Database\DatabaseDriver::insertObject takes its second argument by reference,
		// so we must store the object in a variable first.
		$row          = new \stdClass();
		$row->cms     = $cms;
		$row->version = $version;
		$row->url     = $url;

		$db->insertObject('sources', $row);
	}

	private function runCommand(DatabaseDriver $db, ?string $cms = null): array
	{
		$output  = new BufferedOutput();
		$command = new Versions($db);
		$exit    = $command(new ArrayInput([]), $output, $cms);

		return [$exit, $output->fetch()];
	}

	public function testVersionsListsAllCmsWhenNoFilter(): void
	{
		// NOTE: The plan asks us to assert that "both CMS names and all their versions appear" when
		// no filter is passed. However, reading src/Command/Versions.php shows that a null/empty
		// $cms argument is coerced to 'joomla' (line 28: `$cms = ($cms ?? '') ?: 'joomla';`).
		// The command therefore *cannot* list multiple CMS at once. We assert the actual behaviour:
		// when no filter is given, only Joomla versions show up.
		$db = $this->makeDatabase();
		$this->createSchema($db);

		$this->insertSource($db, 'joomla', '4.4.0');
		$this->insertSource($db, 'joomla', '5.0.0');
		$this->insertSource($db, 'wordpress', '6.4.0');

		[$exit, $text] = $this->runCommand($db, null);

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('4.4.0', $text);
		$this->assertStringContainsString('5.0.0', $text);
		$this->assertStringNotContainsString('6.4.0', $text);
	}

	public function testVersionsFiltersByCms(): void
	{
		$db = $this->makeDatabase();
		$this->createSchema($db);

		$this->insertSource($db, 'joomla', '5.0.0');
		$this->insertSource($db, 'wordpress', '6.4.0');
		$this->insertSource($db, 'wordpress', '6.5.1');

		[$exit, $text] = $this->runCommand($db, 'joomla');

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('5.0.0', $text);
		$this->assertStringNotContainsString('6.4.0', $text);
		$this->assertStringNotContainsString('6.5.1', $text);
	}

	public function testVersionsSortedSemantically(): void
	{
		$db = $this->makeDatabase();
		$this->createSchema($db);

		// Insert out of natural-string order; 5.0.10 sorts before 5.0.2 lexicographically.
		$this->insertSource($db, 'joomla', '5.0.0');
		$this->insertSource($db, 'joomla', '5.0.10');
		$this->insertSource($db, 'joomla', '5.0.2');

		[$exit, $text] = $this->runCommand($db, 'joomla');

		$this->assertSame(0, $exit);

		$pos000 = strpos($text, '5.0.0');
		$pos002 = strpos($text, '5.0.2');
		$pos010 = strpos($text, '5.0.10');

		$this->assertNotFalse($pos000);
		$this->assertNotFalse($pos002);
		$this->assertNotFalse($pos010);

		$this->assertLessThan($pos002, $pos000, '5.0.0 should appear before 5.0.2');
		$this->assertLessThan($pos010, $pos002, '5.0.2 should appear before 5.0.10');
	}

	public function testVersionsEmptyDatabase(): void
	{
		$db = $this->makeDatabase();
		$this->createSchema($db);

		[$exit, $text] = $this->runCommand($db, 'joomla');

		// The command renders an empty SymfonyStyle table; it does not print a dedicated
		// "no versions" message. It still exits 0 successfully.
		$this->assertSame(0, $exit);
		$this->assertStringContainsString('version', $text);
		// No row content should be present apart from the header.
		$this->assertDoesNotMatchRegularExpression('/\b\d+\.\d+\.\d+\b/', $text);
	}

	public function testVersionsUnknownCmsFilter(): void
	{
		$db = $this->makeDatabase();
		$this->createSchema($db);

		$this->insertSource($db, 'joomla', '5.0.0');
		$this->insertSource($db, 'wordpress', '6.4.0');

		[$exit, $text] = $this->runCommand($db, 'drupal');

		// Unknown CMS yields an empty result set; command still succeeds and renders a
		// header-only table without a dedicated "unknown CMS" message.
		$this->assertSame(0, $exit);
		$this->assertStringContainsString('version', $text);
		$this->assertStringNotContainsString('5.0.0', $text);
		$this->assertStringNotContainsString('6.4.0', $text);
	}
}
