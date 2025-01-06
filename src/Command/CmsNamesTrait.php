<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

trait CmsNamesTrait
{
	private function getCmsName(string $tag): string
	{
		return match ($tag)
		{
			'joomla' => 'Joomla!',
			'wordpress' => 'WordPress',
			default => ucfirst($tag)
		};
	}

}