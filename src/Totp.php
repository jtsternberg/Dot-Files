<?php
namespace JT;

use Closure;
use InvalidArgumentException;

/** RFC 6238 codes from standard otpauth provisioning URIs. */
final class Totp {

	public function __construct( private readonly ?Closure $clock = null ) {}

	public function code( string $uri ): string {
		$uri = trim( $uri );
		$parts = parse_url( $uri );
		if ( false === $parts || preg_match( '/\s/', $uri )
			|| 'otpauth' !== strtolower( $parts['scheme'] ?? '' )
			|| 'totp' !== strtolower( $parts['host'] ?? '' )
			|| '' === trim( $parts['path'] ?? '', '/' )
			|| array_intersect( [ 'user', 'pass', 'port', 'fragment' ], array_keys( $parts ) )
		) {
			throw new InvalidArgumentException( 'Expected one otpauth://totp authenticator setup QR code.' );
		}

		$params = [];
		foreach ( explode( '&', $parts['query'] ?? '' ) as $pair ) {
			[ $name, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$name = urldecode( $name );
			if ( '' === $name || str_contains( $name, '[' ) || array_key_exists( $name, $params ) ) {
				throw new InvalidArgumentException( 'Invalid or repeated authenticator parameters.' );
			}
			$params[ $name ] = urldecode( $value );
		}

		$key = $this->base32( $params['secret'] ?? '' );
		$algorithm = strtolower( $params['algorithm'] ?? 'SHA1' );
		$digits = $params['digits'] ?? '6';
		$period = filter_var( $params['period'] ?? '30', FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		if ( ! in_array( $algorithm, [ 'sha1', 'sha256', 'sha512' ], true )
			|| ! in_array( $digits, [ '6', '8' ], true ) || false === $period
			|| isset( $params['encoder'] ) || isset( $params['counter'] )
		) {
			throw new InvalidArgumentException( 'Unsupported TOTP parameters: use SHA1/SHA256/SHA512, 6 or 8 digits, and a positive integer period.' );
		}

		$now = null === $this->clock ? time() : ( $this->clock )();
		if ( ! is_int( $now ) || $now < 0 ) {
			throw new InvalidArgumentException( 'TOTP requires a nonnegative Unix timestamp.' );
		}
		$counter = intdiv( $now, $period );
		// Two network-order words encode the 64-bit counter, including dates beyond 2038.
		$hash = hash_hmac( $algorithm, pack( 'N2', $counter >> 32, $counter & 0xffffffff ), $key, true );
		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0f;
		$value = unpack( 'N', substr( $hash, $offset, 4 ) )[1] & 0x7fffffff;

		return str_pad( (string) ( $value % ( 10 ** (int) $digits ) ), (int) $digits, '0', STR_PAD_LEFT );
	}

	private function base32( string $secret ): string {
		$secret = strtoupper( $secret );
		$unpadded = rtrim( $secret, '=' );
		$length = strlen( $unpadded );
		$padding = strlen( $secret ) - $length;
		if ( ! preg_match( '/^[A-Z2-7]+={0,6}$/D', $secret )
			|| ! in_array( $length % 8, [ 0, 2, 4, 5, 7 ], true )
			|| ( $padding > 0 && $padding !== ( 8 - $length % 8 ) % 8 )
		) {
			throw new InvalidArgumentException( 'Authenticator secret must be valid Base32.' );
		}

		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$buffer = 0;
		$bits = 0;
		$bytes = '';
		foreach ( str_split( $unpadded ) as $character ) {
			$buffer = ( $buffer << 5 ) | strpos( $alphabet, $character );
			$bits += 5;
			if ( $bits >= 8 ) {
				$bits -= 8;
				$bytes .= chr( ( $buffer >> $bits ) & 0xff );
				$buffer &= ( 1 << $bits ) - 1;
			}
		}
		if ( 0 !== $buffer ) {
			throw new InvalidArgumentException( 'Authenticator secret has invalid Base32 trailing bits.' );
		}

		return $bytes;
	}
}
