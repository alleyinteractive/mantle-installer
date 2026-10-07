<?php
namespace Mantle\Installer\Tests;

use Mantle\Installer\InstallCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Install command that records shell commands instead of running them.
 */
class FakeInstallCommand extends InstallCommand {
	/**
	 * Commands the installer would have run.
	 *
	 * @var string[]
	 */
	public array $commands = [];

	public bool $succeed = true;

	protected function run_commands( array $commands, string $label, InputInterface $input, OutputInterface $output ): Process {
		$this->commands = [ ...$this->commands, ...$commands ];

		$process = new Process( [ $this->succeed ? 'true' : 'false' ] );
		$process->run();

		return $process;
	}

	protected function stdin_is_terminal(): bool {
		return true;
	}

	protected function check_if_hiring(): bool {
		return false;
	}
}
