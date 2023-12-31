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

class Dump
{
	use IoStyleTrait;
	use CmsNamesTrait;

	public function __construct(private readonly DatabaseDriver $db) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output,
		?string $outDir = null, bool $sources = false, bool $noSums = false, $gzip = false
	)
	{
		$this->initIo($input, $output);

		$outDir = !empty($outDir) ? realpath($outDir) : false;

		if ($outDir === false)
		{
			$outDir = __DIR__ . '/../../tmp/dump';

			if (is_dir($outDir))
			{
				mkdir($outDir, 0755, true);
			}
		}

		$thingsDone = 0;

		if ($sources)
		{
			$thingsDone++;

			$targetFile = $outDir . '/sources.json';

			$this->io->info(sprintf('Dumping sources to %s', $targetFile));

			$this->dumpSources($targetFile, $gzip);
		}

		if (!$noSums)
		{
			$thingsDone++;

			$this->io->info(sprintf('Dumping checksums to %s', $outDir));

			$this->dumpAllChecksums($outDir, $gzip);
		}

		if (empty($thingsDone))
		{
			$this->io->warning('You told me to do nothing.');
		}
		else
		{
			$this->io->success('Successful dump');
		}
	}

	private function dumpSources(string $targetFile, bool $gzip = false)
	{
		if (file_exists($targetFile))
		{
			@unlink($targetFile);
		}

		mkdir(dirname($targetFile), 0755, true);

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select('*')
			->from('sources');
		$data  = $db->setQuery($query)->loadAssocList();

		uasort($data, fn($a, $b) => version_compare($a['version'], $b['version']));

		$json = json_encode(array_values($data));

		if ($gzip)
		{
			file_put_contents($targetFile . '.gz', gzcompress($json, 9, ZLIB_ENCODING_GZIP));
		}
		else
		{
			file_put_contents($targetFile, $json);
		}
	}

	private function dumpAllChecksums(string $outDir, bool $gzip = false)
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
				sprintf('Dumping checksums for %s %s', $this->getCmsName($what->cms), $what->version)
			);

			$this->dumpCmsVersionChecksums($what->cms, $what->version, $outDir, $gzip);
		}
	}

	private function dumpCmsVersionChecksums($cms, $version, string $outDir, bool $gzip = false)
	{
		$subDir = $outDir . '/' . $cms . '/' . $version;
		@mkdir($subDir, 0755, true);

		$db = $this->db;

		$this->io->write('  -> ');

		foreach (
			[
				'md5',
				'sha1',
				'sha256',
				'sha512',
				'md5_squash',
				'sha1_squash',
				'sha256_squash',
				'sha512_squash',
			] as $type
		)
		{
			$this->io->write(' ' . $type);
			$query = $db->getQuery(true)
				->select(
					[
						$db->quoteName('filename'),
						$db->quoteName($type, 'hash'),
					]
				)
				->from($db->quoteName('checksums'))
				->where([
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				])
				->order($db->quoteName('filename') . ' ASC')
				->bind(':cms', $cms)
				->bind(':version', $version);

			$data = $db->setQuery($query)->loadAssocList('filename', 'hash');

			$json       = json_encode($data);
			$targetFile = $subDir . '/' . $type . '.json';

			if ($gzip)
			{
				file_put_contents($targetFile . '.gz', gzcompress($json, 9, ZLIB_ENCODING_GZIP));
			}
			else
			{
				file_put_contents($targetFile, $json);
			}
		}

		$this->io->writeln('');
	}

}