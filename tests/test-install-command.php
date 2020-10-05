<?php
namespace Mantle\Installer\Console\Tests;

use Mantle\Installer\Console\Install_Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

class Test_Install_Command extends TestCase {
	public function test_install_wordpress() {
		$output = __DIR__ . '/output';

		if ( is_dir( $output . '/new-site' ) ) {
			exec( 'rm -rf ' . $output . '/new-site' );
		}

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

	protected function get_tester(): CommandTester {
		$app = new Application( 'Mantle Installer' );
		$app->add( new Install_Command() );

		return new CommandTester( $app->find( 'new' ) );
	}
}
