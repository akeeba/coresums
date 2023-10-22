<?php

namespace Akeeba\CoreSums\Command;

use Github\Client;
use Joomla\Database\DatabaseDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Sources
{
	use IoStyleTrait;

	public function __construct(private DatabaseDriver $db, private Client $gitHub, private Generate $generateCommand) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output,
		bool $latest = false, bool $process = false
	)
	{
		$this->initIo($input, $output);

		$this->io->info('Retrieving Joomla! releases from GitHub…');

		$allReleases = [];
		$page        = 1;

		while (true)
		{
			$this->io->writeln(sprintf('Retrieving page %d', $page));

			/** @see https://developer.github.com/v3/repos/releases/ */
			$params   = [
				'per_page' => $latest ? 30 : 100,
				'page'     => $page++,
			];
			$releases = $this->gitHub->api('repo')->releases()->all('joomla', 'joomla-cms', $params);

			if (empty($releases))
			{
				break;
			}

			$allReleases = array_merge($allReleases, $releases);

			if ($latest)
			{
				break;
			}
		}

		$this->io->info(sprintf('Found %d releases in total', count($allReleases)));

		$this->io->info('Locating full installation ZIP files');

		/**
		 * @var array $release
		 * @see https://docs.github.com/en/rest/releases/releases?apiVersion=2022-11-28
		 */
		foreach ($allReleases as $release)
		{
			$jVersion = $release['tag_name'] ?? null;
			$assets   = $release['assets'] ?? [];

			if (empty($jVersion) || empty($assets) || !is_array($assets))
			{
				continue;
			}

			$assets = array_filter(
				$assets,
				fn(array $asset) => str_contains($asset['browser_download_url'], 'Full_Package')
				                    && str_ends_with($asset['browser_download_url'], '.zip')
			);

			if (empty($assets))
			{
				continue;
			}

			$firstItem   = array_shift($assets);
			$downloadUrl = $firstItem['browser_download_url'];

			$this->io->writeln(
				sprintf('Joomla! %s -- %s', $jVersion, $downloadUrl)
			);

			$this->updateSource('joomla', $jVersion, $downloadUrl);

			if ($process)
			{
				call_user_func($this->generateCommand, 'joomla', $jVersion, false, true);
			}
		}
	}

	private function updateSource(string $cms, string $version, string $url): void
	{
		$db = $this->db;

		$query = $db->getQuery(true)
			->select($db->quoteName('url'))
			->from($db->quoteName('sources'))
			->where(
				[
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				]
			)
			->bind(':cms', $cms)
			->bind(':version', $version);

		$existingUrl = $db->setQuery($query)->loadResult() ?? null;

		if ($existingUrl === $url)
		{
			return;
		}

		$query = $db->getQuery(true)
			->delete($db->quoteName('sources'))
			->where(
				[
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				]
			)
			->bind(':cms', $cms)
			->bind(':version', $version);
		$db->setQuery($query)->execute();

		$o = (object) [
			'cms'     => $cms,
			'version' => $version,
			'url'     => $url,
		];

		$db->insertObject('sources', $o);
	}
}