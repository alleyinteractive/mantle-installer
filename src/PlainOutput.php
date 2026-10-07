<?php
/**
 * PlainOutput class file.
 *
 * @package Mantle
 */

declare(strict_types=1);

namespace Mantle\Installer;

use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Output that strips ANSI escape codes, for Laravel Prompts when the output isn't a terminal.
 */
class PlainOutput extends Output {
	/**
	 * Constructor.
	 *
	 * @param OutputInterface $output Output to write to.
	 */
	public function __construct( protected OutputInterface $output ) {
		parent::__construct( $output->getVerbosity(), false );
	}

	/**
	 * Write a message to the wrapped output without escape codes.
	 *
	 * @param string $message Message to write.
	 * @param bool   $newline Whether to add a newline.
	 */
	protected function doWrite( string $message, bool $newline ): void {
		$this->output->write( (string) preg_replace( '/\e\[[0-9;?]*[A-Za-z]/', '', $message ), $newline, OutputInterface::OUTPUT_RAW );
	}
}
