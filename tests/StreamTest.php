<?php
/**
 * Stream tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Stream;

/**
 * Class StreamTest
 */
final class StreamTest extends TestCase {

	public function test_nothing_is_sent_until_the_first_byte(): void {
		$out = new Stream( array( 'Content-Type' => 'application/octet-stream' ), unbuffer: false );

		$this->assertFalse( $out->started() );

		ob_start();
		$write = $out->writer();
		$write( 'ab' );
		$write( 'cd' );
		$sent = (string) ob_get_clean();

		$this->assertTrue( $out->started() );
		$this->assertSame( 'abcd', $sent );
	}

	public function test_start_is_idempotent_and_an_empty_body_can_still_start(): void {
		$out = new Stream( array(), unbuffer: false );

		ob_start();
		$out->start();
		$out->start();
		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertTrue( $out->started() );
	}

	public function test_buffering_is_switched_off_downstream_too(): void {
		$lines = ( new Stream( array( 'Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="a.zip"' ) ) )->lines();

		$this->assertSame( array( 'Content-Type: application/zip', 'Content-Disposition: attachment; filename="a.zip"', 'X-Accel-Buffering: no' ), $lines );
		$this->assertSame( array( 'X-Accel-Buffering: yes' ), ( new Stream( array( 'X-Accel-Buffering' => 'yes' ) ) )->lines(), 'unless the caller says otherwise' );
	}

	public function test_a_malformed_header_is_refused_before_anything_is_sent(): void {
		$this->expectException( \UnexpectedValueException::class );

		new Stream( array( 'Content-Disposition' => "attachment\r\nX-Evil: 1" ) );
	}
}
