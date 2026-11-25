<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Database;

use Joomla\Database\DatabaseDriver;

/**
 * Schema maintenance which must also reach databases created by older versions of this application.
 */
final class Schema
{
	/**
	 * Make sure the checksums table has the index every per-version query relies on.
	 *
	 * Without it, each query for one CMS version scans the whole table (2.8 GB in production), which took minutes per
	 * query on the production server. The index also covers `ORDER BY filename`, which Dump uses.
	 *
	 * The first call on an existing database builds the index, which takes a while; later calls are instant.
	 *
	 * @param   DatabaseDriver  $db  The database to update
	 *
	 * @return  void
	 */
	public static function ensureIndexes(DatabaseDriver $db): void
	{
		if (!in_array('checksums', $db->getTableList(), true))
		{
			return;
		}

		// A query object, not a string: given a string, setQuery() calls the deprecated getQuery(true) internally.
		$db->setQuery(
			$db->createQuery()->setQuery(
				'CREATE INDEX IF NOT EXISTS ' . $db->quoteName('checksums_cms_version_filename')
				. ' ON ' . $db->quoteName('checksums') . ' ('
				. implode(', ', $db->quoteName(['cms', 'version', 'filename']))
				. ')'
			)
		)->execute();
	}
}
