<?php
namespace Mantle\Installer\Tests;

use Mantle\Installer\InstallCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

class InstallCommandTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$output = __DIR__ . '/output';

		if ( is_dir( $output . '/new-site' ) ) {
			exec( 'rm -rf ' . $output . '/new-site' );
		}

		if ( is_dir( $output . '/new-site-dev' ) ) {
			exec( 'rm -rf ' . $output . '/new-site-dev' );
		}

		if ( is_dir( $output . '/existing-site' ) ) {
			exec( 'rm -rf ' . $output . '/existing-site' );
		}
	}

	public function test_install_wordpress(): void {
		$output = __DIR__ . '/output';

		chdir( $output );

		$tester = $this->get_tester();

		try {
			$status_code = $tester->execute(
				[
					'name' => [ 'new-site' ],
				],
				[
					'i',
					'f',
				]
			);
		} catch ( Throwable $e ) {
			echo $tester->getDisplay( true );
			throw $e;
		}

		$this->assertEquals( 0, $status_code );
		$this->assertDirectoryExists( "{$output}/new-site" );
		$this->assertFileExists( "{$output}/new-site/wp-settings.php" );
		$this->assertFileExists( "{$output}/new-site/wp-content/plugins/new-site/new-site.php" );
		$this->assertFileExists( "{$output}/new-site/wp-content/mu-plugins/new-site-loader.php" );
	}

	public function test_install_wordpress_dev(): void {
		$output = __DIR__ . '/output';

		chdir( $output );

		$tester = $this->get_tester();

		try {
			$status_code = $tester->execute(
				[
					'name' => [ 'new-site-dev' ],
					'--install' => true,
					'--force' => true,
					'--dev' => true,
				],
				[
					'i',
					'f',
					'd',
				]
			);
		} catch ( Throwable $e ) {
			echo $tester->getDisplay( true );
			throw $e;
		}

		$this->assertEquals( 0, $status_code );
		$this->assertDirectoryExists( "{$output}/new-site-dev" );
		$this->assertFileExists( "{$output}/new-site-dev/wp-settings.php" );
		$this->assertFileExists( "{$output}/new-site-dev/wp-content/plugins/new-site-dev/mantle.php" );
		$this->assertFileExists( "{$output}/new-site-dev/wp-content/plugins/new-site-dev-framework/composer.json" );
		$this->assertFileExists( "{$output}/new-site-dev/wp-content/mu-plugins/new-site-dev-loader.php" );
	}

	public function test_install_into_existing_wordpress_path_without_prompts(): void {
		$output = __DIR__ . '/output';
		$site   = "{$output}/existing-site";

		mkdir( "{$site}/wp-content/plugins", 0777, true );
		touch( "{$site}/wp-settings.php" );

		chdir( $output );

		$tester = $this->get_tester();

		try {
			$status_code = $tester->execute(
				[
					'name'             => [ 'my-plugin' ],
					'--wordpress-path' => $site,
				],
				[
					'interactive' => false,
				]
			);
		} catch ( Throwable $e ) {
			echo $tester->getDisplay( true );
			throw $e;
		}

		$this->assertEquals( 0, $status_code );
		$this->assertFileExists( "{$site}/wp-content/plugins/my-plugin/my-plugin.php" );
		$this->assertFileExists( "{$site}/wp-content/mu-plugins/my-plugin-loader.php" );
	}

	public function test_invalid_wordpress_path(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid WordPress installation' );

		$this->get_tester()->execute(
			[
				'--wordpress-path' => __DIR__,
			],
			[
				'interactive' => false,
			]
		);
	}

	protected function get_tester(): CommandTester {
		$app = new Application( 'Mantle Installer' );
		$app->add( new InstallCommand() );

		return new CommandTester( $app->find( 'new' ) );
	}
}
