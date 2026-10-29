<?php
namespace JT\Tests\FromQr;

use InvalidArgumentException;
use JT\Totp;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TotpTest extends TestCase {

	// Public RFC 6238 Appendix B test keys, not account credentials.
	private const SHA1_KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
	private const SHA256_KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZA';
	private const SHA512_KEY = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNA';

	public static function vectors(): iterable {
		$rows = [
			[ 59, '94287082', '46119246', '90693936' ],
			[ 1111111109, '07081804', '68084774', '25091201' ],
			[ 1111111111, '14050471', '67062674', '99943326' ],
			[ 1234567890, '89005924', '91819424', '93441116' ],
			[ 2000000000, '69279037', '90698825', '38618901' ],
			[ 20000000000, '65353130', '77737706', '47863826' ],
		];
		foreach ( $rows as [ $time, $sha1, $sha256, $sha512 ] ) {
			foreach ( [ [ 'SHA1', self::SHA1_KEY, $sha1 ], [ 'SHA256', self::SHA256_KEY, $sha256 ], [ 'SHA512', self::SHA512_KEY, $sha512 ] ] as [ $algorithm, $key, $code ] ) {
				yield "{$time}-{$algorithm}" => [ $time, $algorithm, $key, $code ];
			}
		}
	}

	#[DataProvider( 'vectors' )]
	public function testMatchesRfc6238( int $time, string $algorithm, string $key, string $expected ): void {
		$totp = new Totp( static fn() => $time );
		$this->assertSame( $expected, $totp->code( "otpauth://totp/Example:test?secret={$key}&algorithm={$algorithm}&digits=8" ) );
	}

	public function testDefaultsAndCustomPeriodRespectTimeBoundary(): void {
		$uri = 'otpauth://totp/Example:test?secret=' . self::SHA1_KEY;
		$this->assertSame( '287082', ( new Totp( static fn() => 59 ) )->code( $uri ) );
		$this->assertSame( '359152', ( new Totp( static fn() => 60 ) )->code( $uri ) );
		$this->assertSame( '287082', ( new Totp( static fn() => 60 ) )->code( $uri . '&period=60' ) );
	}

	public function testAcceptsLowercaseAndPaddedBase32(): void {
		$totp = new Totp( static fn() => 59 );
		$uri = 'otpauth://totp/Example:test?secret=';
		$this->assertSame( $totp->code( $uri . self::SHA256_KEY ), $totp->code( $uri . strtolower( self::SHA256_KEY ) . '====' ) );
	}

	public static function invalidUris(): iterable {
		$base = 'otpauth://totp/Example:test?secret=' . self::SHA1_KEY;
		foreach ( [
			'https://example.com/',
			str_replace( '/totp/', '/hotp/', $base ),
			'otpauth://totp/test',
			'otpauth://totp/test?secret=',
			'otpauth://totp/test?secret=ABC!INVALID',
			'otpauth://totp/test?secret=A',
			'otpauth://totp/test?secret=MZ', // Nonzero unused Base32 bits.
			'otpauth://totp/test?secret=MY=', // Incorrect padding.
			$base . '&secret=' . self::SHA1_KEY,
			$base . '&secret[]=test',
			$base . '&algorithm=MD5',
			$base . '&digits=7',
			$base . '&period=0',
			$base . '&period=-1',
			$base . '&period=1.5',
			$base . '&period=999999999999999999999999999',
			$base . '&encoder=steam',
			$base . "\n" . $base,
			$base . '#fragment',
		] as $i => $uri ) {
			yield "invalid-{$i}" => [ $uri ];
		}
	}

	#[DataProvider( 'invalidUris' )]
	public function testRejectsMalformedOrUnsupportedSetupWithoutEchoingPayload( string $uri ): void {
		try {
			( new Totp( static fn() => 59 ) )->code( $uri );
			$this->fail( 'Expected invalid provisioning URI to fail.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertStringNotContainsString( self::SHA1_KEY, $error->getMessage() );
			$this->assertStringNotContainsString( 'secret=', $error->getMessage() );
		}
	}
}
