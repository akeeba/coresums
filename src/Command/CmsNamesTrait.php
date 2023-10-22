<?php

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