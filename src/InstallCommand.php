<?php
/**
 * InstallCommand class file.
 *
 * @package Mantle
 */

declare(strict_types=1);

namespace Mantle\Installer;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Installation Command for Mantle
 */
class InstallCommand extends Command {
	/**
	 * Symfony Style instance.
	 *
	 * @var SymfonyStyle
	 */
	protected SymfonyStyle $style;

	/**
	 * Configure the install command.
	 */
	protected function configure(): void {
		$this->setName( 'new' )
			->setDescription( 'Create a new Mantle application' )
			->addArgument( 'name', InputArgument::OPTIONAL, 'Name of the folder to install WordPress in and of the Mantle plugin, optional.' )
			->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Install even if the directory already exists' )
			->addOption( 'install', 'i', InputOption::VALUE_NONE, 'Install WordPress in the current location if it doesn\'t exist.' )
			->addOption( 'no-must-use', null, InputOption::VALUE_NONE, 'Don\'t load Mantle as a must-use plugin.' )
			->addOption( 'dev', 'd', InputOption::VALUE_NONE, 'Setup mantle for development on the framework.' )
			->addOption( 'mantle-version', null, InputOption::VALUE_REQUIRED, 'Version of alleyinteractive/mantle to install.' )
			->addOption( 'wordpress-path', 'w', InputOption::VALUE_REQUIRED, 'Path to an existing WordPress installation to install Mantle into.' );
	}

	/**
	 * Disable prompts when STDIN is not a terminal so scripts and agents never hang waiting for input.
	 *
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		if ( defined( 'STDIN' ) && ! stream_isatty( STDIN ) ) {
			$input->setInteractive( false );
		}
	}

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input Input interface.
	 * @param OutputInterface $output Output interface.
	 * @return int
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$this->style = new SymfonyStyle( $input, $output );

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

		$name = $this->get_name( $input );

		if ( null !== $name && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name ) ) {
			$this->style->error( "Invalid name: [{$name}] Use only letters, numbers, dots, dashes, and underscores." );

			return static::FAILURE;
		}

		if ( $this->check_if_hiring() ) {
			$output->write(
				"Alley is hiring! Apply today at <href=https://alley.com/careers/>https://alley.com/careers/</>. \n\n"
			);
		}

		// Determine if we're in a WordPress project already.
		$wordpress_root = $this->get_wordpress_root( $input, $output );

		if ( ! $wordpress_root ) {
			return static::FAILURE;
		}

		return $this->install_mantle( $wordpress_root, $input, $output );
	}

	/**
	 * Get the name passed to the installer, treating "." as no name.
	 *
	 * @param InputInterface $input Input interface.
	 * @return string|null
	 */
	protected function get_name( InputInterface $input ): ?string {
		$name = $input->getArgument( 'name' );

		return $name && '.' !== $name ? $name : null;
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
		$wordpress_path = $input->getOption( 'wordpress-path' );

		if ( $wordpress_path ) {
			$wordpress_path = rtrim( $wordpress_path, '/' );

			$this->validate_wordpress_root( $wordpress_path );

			$output->writeln( "Using [<fg=yellow>{$wordpress_path}</fg=yellow>] as the WordPress installation." );
			return $wordpress_path;
		}

		$cwd     = (string) getcwd();
		$name    = $this->get_name( $input );
		$abspath = $name ? $cwd . '/' . $name : $cwd;

		// Check if 'wp-content' is in the current path.
		if ( str_contains( $abspath, '/wp-content/' ) ) {
			$root = rtrim( (string) preg_replace( '#/wp-content/.*$#', '/', $abspath ), '/' );

			if ( is_dir( $root ) && file_exists( $root . '/wp-settings.php' ) ) {
				$output->writeln( "Using [<fg=yellow>{$root}</fg=yellow>] as the WordPress installation." );
				return $root;
			}
		}

		// If the current directory is 'wp-content', use the parent directory.
		if ( 'wp-content' === basename( $abspath ) && file_exists( dirname( $abspath ) . '/wp-settings.php' ) ) {
			$abspath = dirname( $abspath );

			$output->writeln( "Using [<fg=yellow>{$abspath}</fg=yellow>] as the WordPress installation." );
			return $abspath;
		}

		// Check if we are inside of the default Homestead WordPress environment.
		if (
			is_dir( $abspath . '/wp-content/' ) &&
			is_dir( $abspath . '/wp/' ) &&
			file_exists( $abspath . '/wp/wp-settings.php' )
		) {
			$output->writeln( "Using [<fg=yellow>{$abspath}/wp/</fg=yellow>] as the WordPress installation, and [<fg=yellow>{$abspath}/wp-content/</fg=yellow>] as the WP Content directory." );
			return $abspath;
		}

		// Bail if the folder already exists.
		if ( $name && is_dir( $abspath ) && ! $input->getOption( 'force' ) ) {
			$this->style->error( "Directory already exists: [{$abspath}] Use --force to override." );
			return null;
		}

		// Ask the user if we should be installing if not already specified.
		if (
			$input->getOption( 'install' )
			|| $this->style->confirm( "Would you like to install WordPress at [<fg=yellow>{$abspath}</fg=yellow>]", true )
		) {
			$this->install_wordpress( $abspath, $input, $output );
			return $abspath;
		}

		return $this->style->ask( 'Please specify your WordPress installation:', null, [ $this, 'validate_wordpress_root' ] );
	}

	/**
	 * Validate that a directory is a WordPress installation.
	 *
	 * @param string|null $dir Path to the WordPress installation.
	 * @return string
	 *
	 * @throws RuntimeException Thrown if the directory is not a WordPress installation.
	 */
	public function validate_wordpress_root( ?string $dir ): string {
		if ( ! $dir || ! is_dir( $dir ) ) {
			throw new RuntimeException( "Directory not found: [{$dir}]" );
		}

		if ( ! file_exists( $dir . '/wp-settings.php' ) ) {
			throw new RuntimeException( "Invalid WordPress installation: [{$dir}]" );
		}

		return $dir;
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

		$archive = tempnam( sys_get_temp_dir(), 'mantle-wordpress-' ); // phpcs:ignore

		if ( false === $archive ) {
			throw new RuntimeException( 'Unable to create a temporary file for the WordPress download.' );
		}

		$commands = [
			'curl -fsSL -o ' . escapeshellarg( $archive ) . ' https://wordpress.org/latest.tar.gz',
			'mkdir -p ' . escapeshellarg( $dir ),
			'tar --strip-components=1 -zxmf ' . escapeshellarg( $archive ) . ' -C ' . escapeshellarg( $dir ),
		];

		try {
			$process = $this->run_commands( $commands, $input, $output );
		} finally {
			@unlink( $archive ); // phpcs:ignore
		}

		if ( ! $process->isSuccessful() ) {
			throw new RuntimeException( 'Error downloading WordPress: ' . $process->getExitCodeText() );
		}

		return true;
	}

	/**
	 * Get the composer command for the environment.
	 *
	 * @return string
	 */
	protected function find_composer(): string {
		// Check if the composer path was set in an environment variable.
		if ( $composer = getenv( 'COMPOSER_PATH' ) ) {
			return $composer;
		}

		$composer_path = getcwd() . '/composer.phar';

		if ( file_exists( $composer_path ) ) {
			return escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $composer_path );
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
	protected function install_mantle( string $dir, InputInterface $input, OutputInterface $output ): int {
		$wp_content = $dir . '/wp-content';
		$name       = $this->get_name( $input );
		$dev        = (bool) $input->getOption( 'dev' );

		if ( ! $name ) {
			$name = 'mantle';

			// Confirm the argument 'name' if not passed before assuming a default.
			if ( ! $this->style->confirm( "No name was passed to the installer. Do you want to install Mantle at <fg=yellow>{$wp_content}/plugins/{$name}</>", true ) ) {
				$this->style->error( 'Mantle installation aborted. To specify a name, pass it as an argument: `mantle new my-plugin`.' );

				return self::FAILURE;
			}
		}

		$mantle_dir    = "{$wp_content}/plugins/{$name}";
		$framework_dir = "{$wp_content}/plugins/{$name}-framework";

		// The dev install is a git checkout of Mantle, so its main file keeps its original name.
		$plugin_file = $dev ? 'mantle.php' : "{$name}.php";

		// Check if Mantle exists at the current location.
		if ( is_dir( $mantle_dir ) && file_exists( $mantle_dir . '/composer.json' ) ) {
			$this->style->error( "Mantle is already installed: [{$mantle_dir}]" );

			return self::FAILURE;
		}

		// Check if the directory is empty.
		if ( is_dir( $mantle_dir ) && count( (array) scandir( $mantle_dir ) ) > 2 ) {
			$this->style->error( "Directory is not empty: [{$mantle_dir}]" );

			return self::FAILURE;
		}

		$mu_plugin = null;

		if ( ! $input->getOption( 'no-must-use' ) ) {
			// Check for client-mu-plugins and use it if it exists.
			$mu_plugins = is_dir( "{$wp_content}/client-mu-plugins" ) ? "{$wp_content}/client-mu-plugins" : "{$wp_content}/mu-plugins";
			$mu_plugin  = "{$mu_plugins}/{$name}-loader.php";

			if ( file_exists( $mu_plugin ) ) {
				$this->style->error( "Mantle MU Plugin loader already exists: [{$mu_plugin}]" );

				return self::FAILURE;
			}
		}

		$composer       = $this->find_composer();
		$version        = $input->getOption( 'mantle-version' ) ? ':' . $input->getOption( 'mantle-version' ) : '';
		$mantle_dir_arg = escapeshellarg( $mantle_dir );
		$commands       = [
			$composer . ' create-project ' . escapeshellarg( "alleyinteractive/mantle{$version}" ) . " {$mantle_dir_arg} --remove-vcs --stability=dev --no-interaction --no-scripts",
		];

		// GNU mv fails when the source and destination are the same file.
		if ( 'mantle.php' !== $plugin_file ) {
			$commands[] = "mv {$mantle_dir_arg}/mantle.php " . escapeshellarg( "{$mantle_dir}/{$plugin_file}" );
		}

		// Setup the application for local development on the framework.
		if ( $dev ) {
			if ( is_dir( $framework_dir ) && file_exists( "{$framework_dir}/composer.json" ) ) {
				$this->style->error( "Mantle Framework is already installed: [{$framework_dir}]" );

				return self::FAILURE;
			}

			$framework_dir_arg = escapeshellarg( $framework_dir );
			$repository        = escapeshellarg(
				(string) json_encode(
					[
						'type'    => 'path',
						'url'     => "../{$name}-framework",
						'options' => [ 'symlink' => true ],
					],
					JSON_UNESCAPED_SLASHES
				)
			);

			$commands = [
				"git clone https://github.com/alleyinteractive/mantle-framework.git {$framework_dir_arg}",
				"cd {$framework_dir_arg} && git remote set-url origin git@github.com:alleyinteractive/mantle-framework.git",
				"cd {$framework_dir_arg} && {$composer} install --no-interaction",
				"git clone https://github.com/alleyinteractive/mantle.git {$mantle_dir_arg}",
				"cd {$mantle_dir_arg} && git remote set-url origin git@github.com:alleyinteractive/mantle.git",
				"cd {$mantle_dir_arg} && {$composer} config repositories.mantle-framework {$repository} --file composer.json",
				// Update Mantle to accept any version of these dependencies.
				"cd {$mantle_dir_arg} && {$composer} require alleyinteractive/mantle-framework:\"*\" alleyinteractive/composer-wordpress-autoloader:\"*\" --no-update --no-scripts --no-interaction",
				"cd {$mantle_dir_arg} && {$composer} install --no-scripts --no-interaction",
			];
		}

		$output->writeln( 'Installing Mantle...' );

		$process = $this->run_commands( $commands, $input, $output );

		if ( ! $process->isSuccessful() ) {
			$this->style->error( 'Error installing Mantle: ' . $process->getExitCodeText() );

			return self::FAILURE;
		}

		$output->writeln( "Mantle installed successfully at <fg=yellow>{$mantle_dir}</>." );

		if ( $dev ) {
			$output->writeln( "Mantle Framework installed successfully at <fg=yellow>{$framework_dir}</>." );
		}

		// Add Mantle as a must-use plugin.
		if ( $mu_plugin ) {
			$mu_plugins = dirname( $mu_plugin );

			if ( ! is_dir( $mu_plugins ) ) {
				mkdir( $mu_plugins, 0777, true ); // phpcs:ignore
			}

			if ( false === file_put_contents( $mu_plugin, trim( $this->get_mu_plugin_loader( "{$name}/{$plugin_file}" ) ) ) ) { // phpcs:ignore
				$this->style->error( "Error writing must-use plugin loader: [{$mu_plugin}]" );

				return self::FAILURE;
			}

			$output->writeln( "Must-use plugin created: <fg=yellow>{$mu_plugin}</>" );
		}

		return self::SUCCESS;
	}

	/**
	 * Get the Must-use plugin loader.
	 *
	 * @param string $plugin_file Path to the plugin's main file, relative to the plugins directory.
	 * @return string
	 */
	protected function get_mu_plugin_loader( string $plugin_file ): string {
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
	wpcom_vip_load_plugin( '$plugin_file' );
} else {
	require_once WP_CONTENT_DIR . '/plugins/$plugin_file';
}
EOT;
	}

	/**
	 * Check if Alley is hiring and throw a prompt.
	 *
	 * @return boolean
	 */
	protected function check_if_hiring(): bool {
		try {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://careers.alley.com/wp-json/wp/v2/job/' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 3 );

			$response = curl_exec( $ch );
			$feed     = is_string( $response ) ? json_decode( $response, true ) : null;
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( empty( $feed ) || ! is_array( $feed ) ) {
			return false;
		}

		// Check if there is a developer posting in the feed.
		foreach ( $feed as $posting ) {
			$title = strtolower( $posting['title']['rendered'] ?? '' );

			if ( str_contains( $title, 'developer' ) ) {
				return true;
			}
		}

		return false;
	}
}
