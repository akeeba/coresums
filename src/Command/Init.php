<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

use Joomla\Database\DatabaseDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Init
{
	use IoStyleTrait;
	use CmsNamesTrait;

	public function __construct(private readonly DatabaseDriver $db) {}

	public function __invoke(InputInterface $input, OutputInterface $output, ?string $sourceFolder = null)
	{
		$this->initIo($input, $output);

		$sqlPath      = realpath(__DIR__ . '/../../assets');
		$sourceFolder ??= $sqlPath;

		$this->io->info('Initialising database');

		$db          = $this->db;
		$sqlCommands = file_get_contents($sqlPath . '/sources.sql');
		$db->setQuery($sqlCommands)->execute();

		$sqlCommands = file_get_contents($sqlPath . '/checksums.sql');
		$db->setQuery($sqlCommands)->execute();

		$this->io->info('Importing sources');

		$this->importSources($sourceFolder);

		$this->io->info('Importing checksums');

		$this->importAllChecksums($sourceFolder);
	}

	private function importSources(string $sourceFolder): void
	{
		if (file_exists($sourceFolder . '/sources.json.gz'))
		{
			$compressed = file_get_contents($sourceFolder . '/sources.json.gz');
			$json       = gzdecode($compressed);
		}
		elseif (file_exists($sourceFolder . '/sources.json'))
		{
			$json = file_get_contents($sourceFolder . '/sources.json');
		}
		else
		{
			$this->io->warning('No sources.json[.gz] file found; cannot import sources');

			return;
		}

		$data = json_decode($json);

		unset($json);

		$db = $this->db;
		$db->transactionStart();

		foreach ($data as $object)
		{
			$query = $db->getQuery(true)
				->delete($db->quoteName('sources'))
				->where(
					[
						$db->quoteName('cms') . ' = :cms',
						$db->quoteName('version') . ' = :version',
					]
				)
				->bind(':cms', $object->cms)
				->bind(':version', $object->version);
			$db->setQuery($query)->execute();

			$db->insertObject('sources', $object);
		}

		$db->transactionCommit();
	}

	private function importAllChecksums(false|string $sourceFolder)
	{
		$db    = $this->db;
		$query = $db->getQuery(true)
			->select(
				[
					$db->quoteName('cms'),
					$db->quoteName('version'),
				]
			)
			->from('sources');

		foreach ($db->setQuery($query)->loadObjectList() ?: [] as $what)
		{
			$this->io->writeln(
				sprintf('Importing checksums for %s %s', $this->getCmsName($what->cms), $what->version)
			);

			$this->importCmsVersionChecksums($what->cms, $what->version, $sourceFolder);
		}

	}

	private function importCmsVersionChecksums($cms, $version, string $sourceFolder): void
	{
		$subDir = sprintf("%s/%s/%s", $sourceFolder, $cms, $version);

		if (!is_dir($subDir))
		{
			$this->io->writeln(
				sprintf(
					'  <error>No checksums for %s %s</error>',
					$this->getCmsName($cms),
					$version
				)
			);

			return;
		}

		$checksums     = [];
		$checksumTypes = [
			'md5',
			'sha1',
			'sha256',
			'sha512',
			'md5_squash',
			'sha1_squash',
			'sha256_squash',
			'sha512_squash',
		];

		foreach ($checksumTypes as $type)
		{
			$filePath = $subDir . '/' . $type . '.json';

			if (file_exists($filePath . '.gz'))
			{
				$compressed = file_get_contents($filePath . '.gz');
				$json = gzdecode($compressed);
			}
			elseif (file_exists($filePath))
			{
				$json = file_get_contents($filePath);
			}
			else
			{
				continue;
			}

			$tempArray = json_decode($json, true);

			foreach ($tempArray as $filename => $checksum)
			{
				$checksums[$filename] ??= (object) [
					'cms'           => $cms,
					'version'       => $version,
					'filename'      => $filename,
					'md5'           => '',
					'sha1'          => '',
					'sha256'        => '',
					'sha512'        => '',
					'md5_squash'    => '',
					'sha1_squash'   => '',
					'sha256_squash' => '',
					'sha512_squash' => '',
				];

				$checksums[$filename]->{$type} = $checksum;
			}
		}

		if (empty($checksums))
		{
			return;
		}

		$db = $this->db;

		$db->transactionStart();

		foreach ($checksums as $o)
		{
			$db->insertObject('checksums', $o);
		}

		$db->transactionCommit();
	}
}