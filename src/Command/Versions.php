<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

use Joomla\Database\DatabaseDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Versions
{
	use IoStyleTrait;
	use CmsNamesTrait;

	public function __construct(private readonly DatabaseDriver $db) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output,
		?string $cms = null
	)
	{
		$this->initIo($input, $output);

		$cms = ($cms ?? '') ?: 'joomla';

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select([
				'DISTINCT ' . $db->quoteName('version')
			])
			->from('sources')
			->where($db->quoteName('cms') . ' = :cms')
			->bind(':cms', $cms);

		$versions = $db->setQuery($query)->loadColumn();

		uasort($versions, fn($a, $b) => version_compare($a, $b));

		$versions = array_map(fn($x) => [$x], $versions);

		$this->io->table(['version'], $versions);
	}
}