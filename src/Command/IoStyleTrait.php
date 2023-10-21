<?php

namespace Akeeba\CoreSums\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

trait IoStyleTrait
{
	protected SymfonyStyle $io;

	private function initIo(InputInterface $input, OutputInterface $output)
	{
		$this->io = new SymfonyStyle($input, $output);
	}
}