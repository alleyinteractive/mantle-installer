<?php
namespace Mantle\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class Install_Command extends Command {
	protected function configure() {
		$this->setName( 'new' )
			->setDescription( 'Create a new Mantle application' )
			->addArgument( 'name', InputOption::VALUE_OPTIONAL, 'Name of the folder to install in, optional.' )
			->addOption( 'dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release' )
			->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Install even if the directory already exists' )
			->addOption( 'install', 'i', InputOption::VALUE_NONE, 'Install WordPress in the current location if it doesn\'t exist.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$output->write(
			PHP_EOL .
			"<fg=red>
  __  __             _   _
 |  \/  |           | | | |
 | \  / | __ _ _ __ | |_| | ___
 | |\/| |/ _` | '_ \| __| |/ _ \
 | |  | | (_| | | | | |_| |  __/
 |_|  |_|\__,_|_| |_|\__|_|\___|</> \n\n"
		);

		// Determine if we're in a WordPress project already.
		$wordpress_root = $this->get_wordpress_root( $input, $output );

		if ( ! $wordpress_root ) {
			return static::FAILURE;
		}

		$output->write('USING   ' . $wordpress_root);
		return static::SUCCESS;
	}

	protected function get_wordpress_root( InputInterface $input, OutputInterface $output ): ?string {
		$cwd     = getcwd();
		$abspath = $cwd;

		// Check if 'wp-content' is in the current path.
		if ( false !== strpos( '/wp-content/', $cwd ) ) {
			$abspath = preg_replace( '#/wp-content/.*$#', '/wp-config.php', getcwd() );
			$output->writeln( "Using [<fg=yellow>{$abspath}</>] as the WordPress installation." );
			return $abspath;
		}

		// Check if a root-level file exists.
		if ( file_exists( $cwd . '/wp-settings.php' ) ) {
			$output->writeln( "Using [<fg=yellow>{$cwd}</>] as the WordPress installation." );
			return $abspath;
		}

		$style = new SymfonyStyle( $input, $output );

		// Figure out where to install WordPress.
		$name    = $input->getArgument( 'name' )[0] ?? null;
		$abspath = $name && '.' !== $name ? $cwd . '/' . $name : $cwd;

		// Bail if the folder already exists.
		if ( $name && is_dir( $name ) && ! $input->getOption( 'force' ) ) {
			$style->error( 'Directory already exists: ' . $abspath );
			return null;
		}

		if ( $input->getOption( 'install' ) ) {
			$this->install_wordpress( $abspath, $input, $output );
			return $abspath;
		}

		// Ask the user if we should be installing.
		if ( $style->confirm( "Would you like to install WordPress at [<fg=yellow>{$abspath}</>]", true ) ) {
			$this->install_wordpress( $abspath, $input, $output );
			return $abspath;
		}

		return $style->ask(
			'Please specify your WordPress installation:',
			null,
			function ( $dir ) {
				if ( ! is_dir( $dir ) ) {
					throw new RuntimeException( 'Directory not found.' );
				}

				if ( ! file_exists( $dir . ' /wp-settings.php' ) ) {
					throw new RuntimeException( 'Invalid WordPress installation.' );
				}

				return true;
			}
		);
	}

	/**
	 * Install WordPress at a given directory.
	 *
	 * @param string $dir Directory to install at.
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return bool
	 */
	protected function install_wordpress( string $dir, InputInterface $input, OutputInterface $output ): bool {
		$output->writeln( "Installing WordPress at <fg=yellow>{$dir}</>..." );

		$wp_cli = $this->find_wp_cli();

		$process = Process::fromShellCommandline( $wp_cli . ' core download --path=' . $dir );
		$process->run(
			function ( $type, $line ) use ( $output ) {
				$output->write('    '.$line);
			}
		);

		$style = new SymfonyStyle( $input, $output );

		if ( ! $process->isSuccessful() ) {
			$style->error( 'Error downloading WordPress.' );
		}

		return true;
	}

	/**
	 * Find wp-cli.
	 *
	 * @return string
	 */
	protected function find_wp_cli() {
		$path = getcwd() . '/wp-cli.phar';

		if ( file_exists( $path ) ) {
			return '"' . PHP_BINARY . '" ' . $path;
		}

		return 'wp';
	}
}
