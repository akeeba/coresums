<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Github\Client;
use Joomla\Database\DatabaseDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Sources
{
	use IoStyleTrait;

	public function __construct(
		readonly private DatabaseDriver $db,
		readonly private HttpFactory $httpFactory,
		readonly private Client $gitHub,
		readonly private Generate $generateCommand,
		readonly private Dump $dumpCommand
	) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output,
		bool $latest = false, bool $process = false, ?string $dump = null, bool $joomla = true, bool $wordpress = true
	)
	{
		$this->initIo($input, $output);

		// Process Joomla! releases
		if ($joomla)
		{
			$this->iterateJoomlaGitHubReleases($this->getGitHubReleases($latest, 'joomla/joomla-cms'), $process, $input, $output);
		}

		// Process WordPress releases
		if ($wordpress)
		{
			$this->iterateWordPress($this->getWordPressVersions($latest), $process, $input, $output);
		}

		// Should I dump the checksums to disk?
		if (!empty($dump))
		{
			call_user_func($this->dumpCommand, $input, $output, $dump, true, false, true);
		}

		return 0;
	}

	/**
	 * Get the releases from GitHub
	 *
	 * @param   bool  $latest  Are we only loading the latest 30 releases? FALSE to load all releases.
	 *
	 * @return  array  The releases information retrieved from GitHub
	 */
	private function getGitHubReleases(bool $latest = false, string $repo = 'joomla/joomla-cms'): array
	{
		[$organisation, $repository] = explode('/', $repo, 2);
		$cmsHuman = $organisation === 'joomla' ? 'Joomla!' : 'WordPress';

		$this->io->info(sprintf('Retrieving %s releases from GitHub…', $cmsHuman));

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
			$releases = $this->gitHub->api('repo')->releases()->all($organisation, $repository, $params);

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

		return $allReleases;
	}

	/**
	 * Iterate through the discovered Joomla! releases
	 *
	 * @param   array            $allReleases  All discovered releases (GitHub data)
	 * @param   bool             $process      Should I process each version's files?
	 * @param   InputInterface   $input        Symfony Input object
	 * @param   OutputInterface  $output       Symfony Output object
	 *
	 * @return array|mixed
	 */
	private function iterateJoomlaGitHubReleases(array $allReleases, bool $process, InputInterface $input, OutputInterface $output): mixed
	{
		$this->io->info('Locating Joomla! full installation ZIP files');

		$countAddedOrChanged = 0;

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

			$updatedOrAdded = $this->updateSource('joomla', $jVersion, $downloadUrl);

			if ($updatedOrAdded)
			{
				$this->io->writeln('  Added to sources');
			}

			if ($updatedOrAdded && $process)
			{
				$this->io->writeln('  Processing');
				call_user_func($this->generateCommand, $input, $output, 'joomla', $jVersion, false, true);
			}

			if ($updatedOrAdded)
			{
				$countAddedOrChanged++;
				$this->io->writeln('');
			}
		}

		return $assets;
	}

	/**
	 * Fetches the list of WordPress versions from the official WordPress API.
	 *
	 * Optionally filters the result to only include the latest and outdated versions.
	 *
	 * @param   bool  $latest  When true, filters the output to include only the latest
	 *                         and outdated WordPress versions for each supported minor branch.
	 *
	 * @return array An array of WordPress version identifiers. If the API request fails
	 *               or the response is invalid, it returns an empty array.
	 */
	private function getWordPressVersions(bool $latest = false): array
	{
		$client   = $this->httpFactory->makeClient(cache: false);
		$versionsUrl      = 'https://api.wordpress.org/core/stable-check/1.0/';
		$response = $client->get($versionsUrl);

		if ($response->getStatusCode() != 200)
		{
			$this->io->error(
				[
					sprintf(
						'Downloading WordPress version information from %s failed. HTTP %s.', $versionsUrl,
						$response->getStatusCode()
					),
				]
			);

			return [];
		}

		$body = $response->getBody()->getContents();

		try
		{
			$data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $e)
		{
			$this->io->error(
				[
					sprintf(
						'Error in downloaded WordPress version information (bad JSON data): %s', $e->getMessage(),
					),
				]
			);

			return [];
		}

		/**
		 * For WordPress, the "latest" releases filter is not a fixed date period but the last published version in each
		 * supported minor branch. This includes the actual "latest" release, and the "outdated" (latest patch release
		 * in every supported minor branch).
		 *
		 * This is deliberate. Doing date filtering would require goign through the GitHub API or parsing the WordPress
		 * download archive page, either of which is slow. Even if we did that, WordPress tends to release patch
		 * versions from the latest minor version family backwards to all past minor version branches of the past decade
		 * within a few weeks of the latest security release. Fetching the releases marked as 'latest' and 'outdated'
		 * every day after seeding the database with all versions released to-date results in all published WP versions
		 * being taken into account.
		 */
		if ($latest)
		{
			$data = array_filter($data, fn($x) => in_array($x, ['latest', 'outdated']));
		}

		return array_keys($data);
	}

	/**
	 * Iterates through a list of WordPress versions, updates their source URLs, and optionally processes them.
	 *
	 * This method locates and updates the source URLs for the provided WordPress versions. If specified,
	 * it also triggers processing for the updated versions.
	 *
	 * @param   array            $wpVersions  A list of WordPress versions to process.
	 * @param   bool             $process     Indicates whether to process the WordPress versions after updating.
	 * @param   InputInterface   $input       The input interface for handling command-line input.
	 * @param   OutputInterface  $output      The output interface for handling command-line output.
	 *
	 * @return  void  No value is returned.
	 */
	private function iterateWordPress(array $wpVersions, bool $process, InputInterface $input, OutputInterface $output): void
	{
		$this->io->info('Locating Joomla! full installation ZIP files');

		foreach ($wpVersions as $version)
		{
			$downloadUrl = sprintf('https://wordpress.org/wordpress-%s.zip', $version);

			$this->io->writeln(
				sprintf('WordPress %s -- %s', $version, $downloadUrl)
			);

			$updatedOrAdded = $this->updateSource('wordpress', $version, $downloadUrl);

			if ($updatedOrAdded)
			{
				$this->io->writeln('  Added to sources');
			}

			if ($updatedOrAdded && $process)
			{
				$this->io->writeln('  Processing');
				call_user_func($this->generateCommand, $input, $output, 'wordpress', $version, false, true);
			}

			if ($updatedOrAdded)
			{
				$this->io->writeln('');
			}
		}
	}

	/**
	 * Updates the source URL for a specific CMS and version in the database.
	 *
	 * If the provided URL is already registered for the given CMS and version, no update is performed.
	 *
	 * @param   string  $cms      The name of the content management system.
	 * @param   string  $version  The version of the CMS.
	 * @param   string  $url      The new URL to associate with the specified CMS and version.
	 *
	 * @return bool Returns true if the URL was successfully updated or added, false if no changes were made.
	 */
	private function updateSource(string $cms, string $version, string $url): bool
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
			return false;
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

		return true;
	}
}