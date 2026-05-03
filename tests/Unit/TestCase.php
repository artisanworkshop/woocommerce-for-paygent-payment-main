<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for all unit tests.
 * Sets up and tears down Brain\Monkey on each test.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub common WordPress functions used throughout the plugin.
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		// apply_filters must be stubbed via Brain\Monkey (not bootstrap) so that
		// Patchwork can redefine it per-test when needed.
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
