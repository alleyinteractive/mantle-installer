<?php
/**
 * Install_Command class file.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
 *
 * @package Mantle
 */

namespace Mantle\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Installation Command for Mantle
 */
class Install_Command extends Command {
	/**
	 * Configure the install command.
	 */
	protected function configure() {
		$this->setName( 'new' )
			->setDescription( 'Create a new Mantle application' )
			->addArgument( 'name', InputOption::VALUE_OPTIONAL, 'Name of the folder to install WordPress in, optional.', null )
			->addOption( 'dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release' )
			->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Install even if the directory already exists' )
			->addOption( 'install', 'i', InputOption::VALUE_NONE, 'Install WordPress in the current location if it doesn\'t exist.' )
			->addOption( 'no-must-use', 'no-mu', InputOption::VALUE_OPTIONAL, 'Don\'t load Mantle as a must-use plugin.', false );
	}

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 * @return int
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
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

		$this->install_mantle( $wordpress_root, $input, $output );

		return static::SUCCESS;
	}

	/**
	 * Determine the WordPress root installation.
	 *
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 * @return string|null
	 *
	 * @throws RuntimeException Thrown on error.
	 */
	protected function get_wordpress_root( InputInterface $input, OutputInterface $output ): ?string {
		$cwd     = getcwd();
		$name    = $input->getArgument( 'name' )[0] ?? null;
		$abspath = $name && '.' !== $name ? $cwd . '/' . $name : $cwd;

		// Check if 'wp-content' is in the current path.
		if ( false !== strpos( '/wp-content/', $abspath ) ) {
			$abspath = preg_replace( '#/wp-content/.*$#', '/wp-config.php', $abspath );
			$output->writeln( "Using [<fg=yellow>{$abspath}</fg=yellow>] as the WordPress installation." );
			return $abspath;
		}

		// Check if a root-level file exists.
		if ( file_exists( $abspath . '/wp-settings.php' ) ) {
			$output->writeln( "Using [<fg=yellow>{$abspath}</fg=yellow>] as the WordPress installation." );
			return $abspath;
		}

		$style = new SymfonyStyle( $input, $output );

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
	 * @param string          $dir Directory to install at.
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 * @return bool
	 *
	 * @throws RuntimeException Thrown on error.
	 */
	protected function install_wordpress( string $dir, InputInterface $input, OutputInterface $output ): bool {
		$output->writeln( "Installing WordPress at <fg=yellow>{$dir}</>...\n\n" );

		$process = $this->run_commands( [ $this->find_wp_cli() . ' core download --force --path=' . $dir ], $input, $output );

		if ( ! $process->isSuccessful() ) {
			throw new RuntimeException( 'Error downloading WordPress: ' . $process->getExitCodeText() );
		}

		return true;
	}

	/**
	 * Find wp-cli.
	 *
	 * @return string
	 */
	protected function find_wp_cli(): string {
		$path = getcwd() . '/wp-cli.phar';

		if ( file_exists( $path ) ) {
			return '"' . PHP_BINARY . '" ' . $path;
		}

		$vendor_wp_cli = __DIR__ . '/../vendor/wp-cli/wp-cli/bin/wp';
		if ( file_exists( $vendor_wp_cli ) ) {
			return $vendor_wp_cli;
		}

		return 'wp';
	}

	/**
	 * Get the composer command for the environment.
	 *
	 * @return string
	 */
	protected function find_composer(): string {
		$composer_path = getcwd() . '/composer.phar';

		if ( file_exists( $composer_path ) ) {
				return '"' . PHP_BINARY . '" ' . $composer_path;
		}

		return 'composer';
	}

	/**
	 * Run a set of shell commands.
	 *
	 * @param string[]        $commands Commands to run.
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 * @return Process
	 *
	 * @throws RuntimeException Thrown on error.
	 */
	protected function run_commands( array $commands, InputInterface $input, OutputInterface $output ): Process {
		$process = Process::fromShellCommandline( implode( ' && ', $commands ), null, null, null, null );

		$output->write( "\n\n" );

		$process->run(
			function ( $type, $line ) use ( $output ) {
				$output->write( '    ' . $line );
			}
		);

		$output->write( "\n\n" );

		return $process;
	}

	/**
	 * Install Mantle in a WordPress installation.
	 *
	 * @param string          $dir Directory to install into.
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 *
	 * @throws RuntimeException Thrown on error.
	 */
	protected function install_mantle( string $dir, InputInterface $input, OutputInterface $output ) {
		$wp_content = $dir . '/wp-content';

		$name       = $input->getArgument( 'name' )[0] ?? 'mantle';
		$mantle_dir = "{$wp_content}/plugins/{$name}";

		// Check if Mantle exists at the current location.
		if ( is_dir( $mantle_dir ) && file_exists( $mantle_dir . '/composer.json' ) ) {
			throw new RuntimeException( "Mantle is already installed: [{$mantle_dir}]" );
		}

		$composer = $this->find_composer();
		$commands = [
			$composer . " create-project alleyinteractive/mantle {$mantle_dir} --remove-vcs --stability=dev --no-interaction --no-scripts",
			"rm -rf {$mantle_dir}/docs",
		];

		$output->writeln( 'Installing Mantle...' );

		$process = $this->run_commands( $commands, $input, $output );

		if ( ! $process->isSuccessful() ) {
			throw new RuntimeException( 'Error installing Mantle: ' . $process->getExitCodeText() );
		}

		$output->writeln( "Mantle installed successfully at <fg=yellow>{$mantle_dir}</>." );

		// Add Mantle as a must-use plugin.
		if ( false === $input->getOption( 'no-must-use' ) ) {
			$mu_plugins = "{$wp_content}/mu-plugins";

			// Check for client-mu-plugins and use it if it exists.
			if ( is_dir( "{$wp_content}/client-mu-plugins" ) ) {
				$mu_plugins = "{$wp_content}/client-mu-plugins";
			} elseif ( ! is_dir( $mu_plugins ) ) {
				mkdir( $mu_plugins ); // phpcs:ignore
			}

			$mu_plugin = "{$mu_plugins}/{$name}-loader.php";

			if ( file_exists( $mu_plugin ) ) {
				throw new RuntimeException( "Mantle MU Plugin loader already exists: [{$mu_plugin}]" );
			}

			if ( false === file_put_contents( $mu_plugin, trim( $this->get_mu_plugin_loader( $name ) ) ) ) { // phpcs:ignore
				throw new RuntimeException( "Error writing must-use plugin loader: [{$mu_plugin}]" );
			}

			$output->writeln( "Must-use plugin created: <fg=yellow>{$mu_plugin}</>" );
		}
	}

	/**
	 * Get the Must-use plugin loader.
	 *
	 * @param string $plugin_name Name of the plugin WordPress was installed at.
	 * @return string
	 */
	protected function get_mu_plugin_loader( string $plugin_name ): string {
		return <<<EOT
<?php
/*
	Plugin Name: Mantle Loader
	Plugin URI: https://github.com/alleyinteractive/mantle
	Description: A plugin to automatically load the Mantle framework.
	Version: 0.1
	Author: Alley Interactive
	Author URI: http://www.alley.co/
*/

if ( function_exists( 'wpcom_vip_load_plugin' ) ) {
	wpcom_vip_load_plugin( '$plugin_name' );
} else {
	require_once WP_CONTENT_DIR . '/plugins/$plugin_name/mantle.php';
}
EOT;
	}
}
