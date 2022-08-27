<?php
namespace Mantle\Installer\Console\Tests;

use Mantle\Installer\Console\Install_Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

class Test_Install_Command extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$output = __DIR__ . '/output';

		if ( is_dir( $output . '/new-site' ) ) {
			exec( 'rm -rf ' . $output . '/new-site' );
		}

		if ( is_dir( $output . '/new-site-dev' ) ) {
			exec( 'rm -rf ' . $output . '/new-site-dev' );
		}
	}

	public function test_install_wordpress() {
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
		$this->assertFileExists( "{$output}/new-site/wp-content/plugins/new-site/mantle.php" );
		$this->assertFileExists( "{$output}/new-site/wp-content/mu-plugins/new-site-loader.php" );
	}

	public function test_install_wordpress_dev() {
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

	protected function get_tester(): CommandTester {
		$app = new Application( 'Mantle Installer' );
		$app->add( new Install_Command() );

		return new CommandTester( $app->find( 'new' ) );
	}
}
