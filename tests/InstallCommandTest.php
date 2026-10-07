<?php
namespace Mantle\Installer\Tests;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Mantle\Installer\InstallCommand;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

class InstallCommandTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected string $sandbox;

	protected string $original_cwd;

	protected FakeInstallCommand $command;

	protected function setUp(): void {
		parent::setUp();

		$this->original_cwd = (string) getcwd();
		$this->sandbox      = __DIR__ . '/output/sandbox';
		$this->command      = new FakeInstallCommand();

		exec( 'rm -rf ' . escapeshellarg( $this->sandbox ) );
		mkdir( $this->sandbox, 0777, true );
		chdir( $this->sandbox );
	}

	protected function tearDown(): void {
		chdir( $this->original_cwd );
		putenv( 'COMPOSER_PATH' );

		parent::tearDown();
	}

	#[Group( 'network' )]
	public function test_install_wordpress_and_mantle(): void {
		$site   = "{$this->sandbox}/new-site";
		$tester = $this->get_tester( new InstallCommand() );

		try {
			$status_code = $tester->execute( [ 'name' => 'new-site', '--install' => true ], [ 'interactive' => false ] );
		} catch ( Throwable $e ) {
			echo $tester->getDisplay( true );
			throw $e;
		}

		$this->assertSame( Command::SUCCESS, $status_code );
		$this->assertFileExists( "{$site}/wp-settings.php" );
		$this->assertFileExists( "{$site}/wp-content/plugins/new-site/new-site.php" );
		$this->assertLoaderRequires( "{$site}/wp-content/mu-plugins/new-site-loader.php", 'new-site/new-site.php' );
	}

	public function test_install_into_wordpress_path(): void {
		$site = $this->make_wordpress( 'existing-site' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => 'my-plugin', '--wordpress-path' => $site ] ) );
		$this->assertCommandRan( "create-project 'alleyinteractive/mantle' '{$site}/wp-content/plugins/my-plugin'" );
		$this->assertCommandRan( "mv '{$site}/wp-content/plugins/my-plugin'/mantle.php '{$site}/wp-content/plugins/my-plugin/my-plugin.php'" );
		$this->assertLoaderRequires( "{$site}/wp-content/mu-plugins/my-plugin-loader.php", 'my-plugin/my-plugin.php' );
	}

	public function test_invalid_wordpress_path(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid WordPress installation' );

		$this->run_installer( [ '--wordpress-path' => __DIR__ ] );
	}

	public function test_detect_wordpress_from_inside_wp_content(): void {
		$site = $this->make_wordpress( 'site' );

		chdir( "{$site}/wp-content/plugins" );

		$this->assertSame( Command::SUCCESS, $this->run_installer() );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/mantle' --remove-vcs" );
		$this->assertNoCommandContains( 'mv ' );
		$this->assertLoaderRequires( "{$site}/wp-content/mu-plugins/mantle-loader.php", 'mantle/mantle.php' );
	}

	public function test_detect_wordpress_from_wp_content(): void {
		$site = $this->make_wordpress( 'site' );

		chdir( "{$site}/wp-content" );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => 'app' ] ) );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/app' --remove-vcs" );
	}

	public function test_detect_homestead_layout(): void {
		$site = "{$this->sandbox}/site";

		mkdir( "{$site}/wp", 0777, true );
		mkdir( "{$site}/wp-content", 0777, true );
		touch( "{$site}/wp/wp-settings.php" );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => 'site' ] ) );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/site' --remove-vcs" );
	}

	public function test_dot_name_uses_the_default_name(): void {
		$site = $this->make_wordpress( 'site' );

		chdir( "{$site}/wp-content" );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => '.' ] ) );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/mantle' --remove-vcs" );
	}

	public function test_install_wordpress_when_not_found(): void {
		$site = "{$this->sandbox}/new-site";

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => 'new-site', '--install' => true ] ) );
		$this->assertCommandRan( 'curl -fsSL -o ' );
		$this->assertCommandRan( "-C '{$site}'" );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/new-site' --remove-vcs" );
		$this->assertFileExists( "{$site}/wp-content/mu-plugins/new-site-loader.php" );
	}

	public function test_refuse_existing_directory_without_force(): void {
		mkdir( "{$this->sandbox}/new-site" );

		$this->assertSame( Command::FAILURE, $this->run_installer( [ 'name' => 'new-site', '--install' => true ] ) );
		$this->assertSame( [], $this->command->commands );
	}

	public function test_refuse_existing_mantle_install(): void {
		$site = $this->make_wordpress( 'site' );

		mkdir( "{$site}/wp-content/plugins/mantle" );
		touch( "{$site}/wp-content/plugins/mantle/composer.json" );

		$this->assertSame( Command::FAILURE, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertSame( [], $this->command->commands );
	}

	public function test_refuse_non_empty_plugin_directory(): void {
		$site = $this->make_wordpress( 'site' );

		mkdir( "{$site}/wp-content/plugins/mantle" );
		touch( "{$site}/wp-content/plugins/mantle/readme.txt" );

		$this->assertSame( Command::FAILURE, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertSame( [], $this->command->commands );
	}

	public function test_refuse_existing_loader_before_installing(): void {
		$site = $this->make_wordpress( 'site' );

		mkdir( "{$site}/wp-content/mu-plugins" );
		touch( "{$site}/wp-content/mu-plugins/mantle-loader.php" );

		$this->assertSame( Command::FAILURE, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertSame( [], $this->command->commands );
	}

	public function test_prefer_client_mu_plugins(): void {
		$site = $this->make_wordpress( 'site' );

		mkdir( "{$site}/wp-content/client-mu-plugins" );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertFileExists( "{$site}/wp-content/client-mu-plugins/mantle-loader.php" );
		$this->assertDirectoryDoesNotExist( "{$site}/wp-content/mu-plugins" );
	}

	public function test_no_must_use_skips_the_loader(): void {
		$site = $this->make_wordpress( 'site' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ '--wordpress-path' => $site, '--no-must-use' => true ] ) );
		$this->assertDirectoryDoesNotExist( "{$site}/wp-content/mu-plugins" );
	}

	public function test_mantle_version(): void {
		$site = $this->make_wordpress( 'site' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ '--wordpress-path' => $site, '--mantle-version' => '^1.0' ] ) );
		$this->assertCommandRan( "create-project 'alleyinteractive/mantle:^1.0'" );
	}

	public function test_failed_install_returns_failure(): void {
		$site = $this->make_wordpress( 'site' );

		$this->command->succeed = false;

		$this->assertSame( Command::FAILURE, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertFileDoesNotExist( "{$site}/wp-content/mu-plugins/mantle-loader.php" );
	}

	public function test_dev_loader_requires_mantle_php(): void {
		$site = $this->make_wordpress( 'site' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ 'name' => 'my-plugin', '--wordpress-path' => $site, '--dev' => true ] ) );
		$this->assertCommandRan( "git clone https://github.com/alleyinteractive/mantle.git '{$site}/wp-content/plugins/my-plugin'" );
		$this->assertCommandRan( "config repositories.mantle-framework '{\"type\":\"path\",\"url\":\"../my-plugin-framework\",\"options\":{\"symlink\":true}}'" );
		$this->assertNoCommandContains( 'mv ' );
		$this->assertLoaderRequires( "{$site}/wp-content/mu-plugins/my-plugin-loader.php", 'my-plugin/mantle.php' );
	}

	public function test_dev_uses_composer_path(): void {
		$site = $this->make_wordpress( 'site' );

		putenv( 'COMPOSER_PATH=/opt/bin/composer' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ '--wordpress-path' => $site, '--dev' => true ] ) );
		$this->assertCommandRan( "cd '{$site}/wp-content/plugins/mantle-framework' && /opt/bin/composer install" );
		$this->assertNoCommandContains( '&& composer ' );
	}

	public function test_quote_paths_with_spaces(): void {
		$site = $this->make_wordpress( 'my site' );

		$this->assertSame( Command::SUCCESS, $this->run_installer( [ '--wordpress-path' => $site ] ) );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/mantle' --remove-vcs" );
	}

	public function test_reject_invalid_name(): void {
		$this->assertSame( Command::FAILURE, $this->run_installer( [ 'name' => 'app;touch pwned', '--install' => true ] ) );
		$this->assertSame( [], $this->command->commands );
	}

	public function test_plain_output_has_no_escape_codes(): void {
		$site   = $this->make_wordpress( 'site' );
		$tester = $this->get_tester( $this->command );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--wordpress-path' => $site ], [ 'interactive' => false ] ) );
		$this->assertStringContainsString( 'Mantle is ready', $tester->getDisplay() );
		$this->assertStringContainsString( "Loader    {$site}/wp-content/mu-plugins/mantle-loader.php", $tester->getDisplay() );
		$this->assertStringNotContainsString( "\e[", $tester->getDisplay() );
	}

	public function test_prompt_for_name(): void {
		$site = $this->make_wordpress( 'site' );

		Prompt::fake( [ ...array_fill( 0, 6, Key::BACKSPACE ), 'a', 'p', 'p', Key::ENTER ] );

		$this->assertSame( Command::SUCCESS, $this->run_interactive( [ '--wordpress-path' => $site ] ) );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/app' --remove-vcs" );
	}

	public function test_prompt_for_name_rejects_invalid_names(): void {
		$site = $this->make_wordpress( 'site' );

		Prompt::fake( [ ' ', Key::ENTER, Key::BACKSPACE, Key::ENTER ] );

		$tester = $this->get_tester( $this->command );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--wordpress-path' => $site ] ) );
		$this->assertStringContainsString( 'Use only letters, numbers, dots, dashes, and underscores.', $tester->getDisplay() );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/mantle' --remove-vcs" );
	}

	public function test_prompt_for_wordpress_path_when_install_declined(): void {
		$site = $this->make_wordpress( 'site' );

		chdir( "{$this->sandbox}" );

		Prompt::fake( [ Key::RIGHT, Key::ENTER, ...str_split( $site ), Key::ENTER, Key::ENTER ] );

		$this->assertSame( Command::SUCCESS, $this->run_interactive() );
		$this->assertNoCommandContains( 'curl' );
		$this->assertCommandRan( "'{$site}/wp-content/plugins/mantle' --remove-vcs" );
	}

	protected function get_tester( InstallCommand $command ): CommandTester {
		$app = new Application( 'Mantle Installer' );
		$app->add( $command );

		return new CommandTester( $app->find( 'new' ) );
	}

	/**
	 * Run the fake installer with prompts disabled.
	 *
	 * @param array<string, mixed> $input Command input.
	 */
	protected function run_installer( array $input = [] ): int {
		return $this->get_tester( $this->command )->execute( $input, [ 'interactive' => false ] );
	}

	/**
	 * Run the fake installer with prompts answered by Prompt::fake().
	 *
	 * @param array<string, mixed> $input Command input.
	 */
	protected function run_interactive( array $input = [] ): int {
		return $this->get_tester( $this->command )->execute( $input );
	}

	protected function make_wordpress( string $name ): string {
		$site = "{$this->sandbox}/{$name}";

		mkdir( "{$site}/wp-content/plugins", 0777, true );
		touch( "{$site}/wp-settings.php" );

		return $site;
	}

	protected function assertCommandRan( string $needle ): void {
		foreach ( $this->command->commands as $command ) {
			if ( str_contains( $command, $needle ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "No command contains [{$needle}]. Commands run:\n" . implode( "\n", $this->command->commands ) );
	}

	protected function assertNoCommandContains( string $needle ): void {
		foreach ( $this->command->commands as $command ) {
			$this->assertStringNotContainsString( $needle, $command );
		}
	}

	protected function assertLoaderRequires( string $loader, string $plugin_file ): void {
		$this->assertFileExists( $loader );
		$this->assertStringContainsString( "require_once WP_CONTENT_DIR . '/plugins/{$plugin_file}';", (string) file_get_contents( $loader ) );
	}
}
