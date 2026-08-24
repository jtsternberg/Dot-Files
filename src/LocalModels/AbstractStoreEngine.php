<?php

namespace JT\LocalModels;

/**
 * Shared flip machinery for every store engine.
 *
 * Subclasses supply names and paths; the flip, the self-gate, and the
 * refuse-on-real-data rule live here once so both engines cannot drift apart.
 */
abstract class AbstractStoreEngine implements StoreEngine {

	public const LOCAL    = 'local';
	public const EXTERNAL = 'external';

	protected string $home;
	protected Drive $drive;

	public function __construct(
		?string $home = null,
		protected readonly string $volumesRoot = '/Volumes'
	) {
		$this->home  = rtrim( $home ?: (string) getenv( 'HOME' ), '/' );
		$this->drive = new Drive( $this->volumesRoot );
	}

	/** The removable volume both engines live on. */
	public function volumeName(): string {
		return 'AI-LAB';
	}

	public function drive(): Drive {
		return $this->drive;
	}

	/**
	 * Location implied by the drive's presence — what the watcher applies.
	 */
	public function locationForDrive( bool $settle = false ): string {
		$mounted = $settle
			? $this->drive->settle( $this->volumeName() )
			: $this->drive->isMounted( $this->volumeName() );

		return $mounted ? self::EXTERNAL : self::LOCAL;
	}

	public function preflight(): Preflight {
		$link = $this->symlinkPath();

		if ( ! is_link( $link ) && file_exists( $link ) ) {
			return Preflight::blocked(
				$link . ' is a real directory, not a symlink. Migrate it into the two stores first;'
					. ' this tooling will not move or delete it for you.'
			);
		}

		if ( ! is_dir( $this->storePath( self::LOCAL ) ) ) {
			return Preflight::blocked( 'local store missing: ' . $this->storePath( self::LOCAL ) );
		}

		return Preflight::ready();
	}

	public function currentLocation(): ?string {
		$target = Flip::currentTarget( $this->symlinkPath() );
		if ( null === $target ) {
			return null;
		}

		foreach ( [ self::LOCAL, self::EXTERNAL ] as $location ) {
			if ( rtrim( $target, '/' ) === rtrim( $this->storePath( $location ), '/' ) ) {
				return $location;
			}
		}

		return null;
	}

	public function apply( string $location, bool $dryRun = false ): ApplyResult {
		if ( ! in_array( $location, [ self::LOCAL, self::EXTERNAL ], true ) ) {
			return new ApplyResult( ApplyResult::FAILED, "Unknown location: {$location}" );
		}

		$pre = $this->preflight();
		if ( ! $pre->manageable ) {
			return new ApplyResult( ApplyResult::FAILED, $pre->reason, $location );
		}

		$target = $this->storePath( $location );
		if ( ! is_dir( $target ) ) {
			return new ApplyResult(
				ApplyResult::FAILED,
				( self::EXTERNAL === $location ? 'external store not available: ' : 'store not found: ' ) . $target,
				$location,
				$target
			);
		}

		if ( Flip::currentTarget( $this->symlinkPath() ) === $target ) {
			return ( new ApplyResult(
				ApplyResult::NOOP,
				$this->label() . ' already on ' . $location,
				$location,
				$target
			) )->withWarnings( $this->advisories( $location ) );
		}

		if ( $dryRun ) {
			return ( new ApplyResult(
				ApplyResult::WOULD_APPLY,
				'would point ' . $this->symlinkPath() . ' -> ' . $target,
				$location,
				$target
			) )->withWarnings( $this->advisories( $location ) );
		}

		if ( ! Flip::swap( $this->symlinkPath(), $target ) ) {
			return new ApplyResult(
				ApplyResult::FAILED,
				'failed to point ' . $this->symlinkPath() . ' -> ' . $target,
				$location,
				$target
			);
		}

		return ( new ApplyResult(
			ApplyResult::APPLIED,
			$this->label() . ' models -> ' . $location . ': ' . $target,
			$location,
			$target
		) )->withWarnings( array_merge( $this->postApply( $location ), $this->advisories( $location ) ) );
	}

	/**
	 * Engine-specific work after a successful flip. Returns warnings to surface.
	 *
	 * @return string[]
	 */
	protected function postApply( string $location ): array {
		return [];
	}

	public function advisories( string $location ): array {
		return [];
	}

	public function reconcile( array $options = [] ): ApplyResult {
		return new ApplyResult( ApplyResult::SKIPPED, $this->label() . ' has no reconcile step.' );
	}

	public function residency(): array {
		return [];
	}

	/**
	 * Is a GUI app of this name running? Used only to warn — never to kill.
	 */
	protected function appIsRunning( string $processName ): bool {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return false;
		}

		exec( 'pgrep -x ' . escapeshellarg( $processName ) . ' 2>/dev/null', $out, $code );

		return 0 === $code && ! empty( $out );
	}
}
