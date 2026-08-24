<?php

namespace JT\LocalModels;

/**
 * MacWhisper's model store.
 *
 * MacWhisper hard-codes its models path with no env override, which is exactly
 * why the whole-dir symlink is the mechanism: the app follows a symlinked models
 * ROOT fine, but it will NOT list a model whose individual bundle is a symlink.
 * That single fact drives two rules here:
 *
 *   1. A store is a full replica of the models tree, all real directories.
 *   2. `reconcile` must COPY, never symlink (unlike Ollama's).
 *
 * It also means the small model and the support set exist as duplicate real
 * copies in both stores. That duplication is the price of the app's behaviour,
 * not an oversight.
 *
 * MacWhisper writes into the models root at runtime (downloads land there,
 * per-bundle .cache/ dirs), so operating on the local store strands new
 * downloads there until they are reconciled back to the external superset.
 *
 * It holds nothing open on the drive between transcriptions — validated live: it
 * was never the process blocking an eject. So it inherits the no-op
 * releaseHolds(), which is a measured fact rather than an unimplemented hook.
 */
final class MacWhisperEngine extends AbstractStoreEngine {

	public function name(): string {
		return 'macwhisper';
	}

	public function label(): string {
		return 'MacWhisper';
	}

	public function symlinkPath(): string {
		return $this->home . '/Library/Application Support/MacWhisper/models';
	}

	public function storePath( string $location ): string {
		return self::EXTERNAL === $location
			? $this->volumesRoot . '/' . $this->volumeName() . '/macwhisper/models'
			: $this->home . '/.macwhisper-local-models';
	}

	/**
	 * @return string[]
	 */
	protected function postApply( string $location ): array {
		if ( $this->appIsRunning( 'MacWhisper' ) ) {
			return [
				'MacWhisper is running — it caches its model list at launch, so relaunch it to see'
					. ' the ' . $location . ' store.',
			];
		}

		return [];
	}

	/**
	 * @return string[]
	 */
	public function advisories( string $location ): array {
		if ( self::LOCAL !== $location ) {
			return [];
		}

		return [
			'AI-LAB not mounted — new MacWhisper model downloads will land in the local store'
				. ' and need `aimodels whisper reconcile` after remount.',
		];
	}

	/**
	 * Copy anything the local store has and the external superset lacks.
	 *
	 * Strictly additive and strictly local -> external: the external store is the
	 * authoritative superset, so an entry it already holds is never overwritten
	 * and nothing is ever deleted. Copies, never symlinks — a symlinked bundle is
	 * invisible to the app, which is the whole reason this engine exists.
	 *
	 * @param array<string, mixed> $options
	 */
	public function reconcile( array $options = [] ): ApplyResult {
		$dryRun = ! empty( $options['dry-run'] );
		$local  = $this->storePath( self::LOCAL );
		$target = $this->storePath( self::EXTERNAL );

		if ( ! is_dir( $local ) ) {
			return new ApplyResult( ApplyResult::FAILED, 'local store missing: ' . $local );
		}

		if ( ! is_dir( $target ) ) {
			return new ApplyResult(
				ApplyResult::FAILED,
				'external store not available (mount AI-LAB and retry): ' . $target
			);
		}

		$plan = $this->missingFromExternal( $local, $target );

		if ( empty( $plan ) ) {
			return new ApplyResult(
				ApplyResult::NOOP,
				'external store already holds everything in the local store.'
			);
		}

		if ( $dryRun ) {
			return new ApplyResult(
				ApplyResult::WOULD_APPLY,
				count( $plan ) . ' entr' . ( 1 === count( $plan ) ? 'y' : 'ies' ) . ' to copy local -> external',
				self::EXTERNAL,
				$target,
				[],
				array_map( static fn( array $item ): string => 'would copy: ' . $item['relative'], $plan )
			);
		}

		$details = [];
		$failed  = 0;
		foreach ( $plan as $item ) {
			if ( $this->copyEntry( $item['from'], $item['to'] ) ) {
				$details[] = 'copied: ' . $item['relative'];
				continue;
			}

			$details[] = 'FAILED: ' . $item['relative'];
			$failed++;
		}

		return new ApplyResult(
			$failed > 0 ? ApplyResult::FAILED : ApplyResult::APPLIED,
			$failed > 0
				? $failed . ' of ' . count( $plan ) . ' entries failed to copy'
				: count( $plan ) . ' entr' . ( 1 === count( $plan ) ? 'y' : 'ies' ) . ' copied local -> external',
			self::EXTERNAL,
			$target,
			[],
			$details
		);
	}

	/**
	 * Recursive additive diff: the shallowest entries present in $local and
	 * absent from $external. Descends only where both sides have a directory, so
	 * a whole new bundle is copied once rather than file by file.
	 *
	 * @return array<int, array{from: string, to: string, relative: string}>
	 */
	private function missingFromExternal( string $local, string $external, string $prefix = '' ): array {
		$plan    = [];
		$entries = @scandir( $local ) ?: [];

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.DS_Store' === $entry ) {
				continue;
			}

			$from     = $local . '/' . $entry;
			$to       = $external . '/' . $entry;
			$relative = ( '' === $prefix ? '' : $prefix . '/' ) . $entry;

			if ( ! file_exists( $to ) ) {
				$plan[] = [ 'from' => $from, 'to' => $to, 'relative' => $relative ];
				continue;
			}

			// Present on both sides: descend into directories, never touch files.
			if ( is_dir( $from ) && is_dir( $to ) ) {
				$plan = array_merge( $plan, $this->missingFromExternal( $from, $to, $relative ) );
			}
		}

		return $plan;
	}

	/**
	 * Copy one entry, then verify by file-count parity before declaring success.
	 * A partial copy is removed rather than left to masquerade as a real model.
	 */
	private function copyEntry( string $from, string $to ): bool {
		$parent = dirname( $to );
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return false;
		}

		exec( $this->copyCommand( $from, $to ) . ' 2>&1', $out, $code );
		if ( 0 !== $code ) {
			$this->removeTree( $to );

			return false;
		}

		if ( $this->countFiles( $from ) !== $this->countFiles( $to ) ) {
			$this->removeTree( $to );

			return false;
		}

		return true;
	}

	/**
	 * ditto preserves macOS metadata and resource forks; Linux has neither ditto
	 * nor a need for them, and this repo runs on both.
	 */
	public function copyCommand( string $from, string $to, ?string $osFamily = null ): string {
		$family = $osFamily ?: PHP_OS_FAMILY;

		return 'Darwin' === $family
			? 'ditto ' . escapeshellarg( $from ) . ' ' . escapeshellarg( $to )
			: 'cp -a ' . escapeshellarg( $from ) . ' ' . escapeshellarg( $to );
	}

	private function countFiles( string $path ): int {
		if ( is_file( $path ) ) {
			return 1;
		}

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$count = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$count++;
			}
		}

		return $count;
	}

	private function removeTree( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $path );
	}

	/**
	 * Which models each store holds, and which are reachable right now.
	 *
	 * A model is a directory containing at least one *.mlmodelc — NOT one
	 * containing config.json. The parakeet bundle has no config.json at all,
	 * while the registry catalogs under argmaxinc/ and the tokenizer stubs under
	 * <framework>/models/openai/ have nothing but json. Nesting depth differs
	 * between frameworks, so the rule cannot key off depth either.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function residency(): array {
		$active = $this->currentLocation();
		$rows   = [];

		foreach ( [ self::LOCAL, self::EXTERNAL ] as $location ) {
			$store = $this->storePath( $location );
			if ( ! is_dir( $store ) ) {
				continue;
			}

			foreach ( $this->modelsIn( $store ) as $key => $model ) {
				$rows[ $key ] ??= [
					'engine'    => $this->name(),
					'name'      => $model['name'],
					'framework' => $model['framework'],
					'kind'      => $model['kind'],
					'path'      => $model['relative'],
					'sizeMb'    => $model['sizeMb'],
					'local'     => false,
					'external'  => false,
					'available' => false,
				];

				$rows[ $key ][ $location ] = true;
				// Prefer the external store's size: it is the authoritative superset.
				if ( self::EXTERNAL === $location ) {
					$rows[ $key ]['sizeMb'] = $model['sizeMb'];
				}
				if ( $location === $active ) {
					$rows[ $key ]['available'] = true;
				}
			}
		}

		return array_values( $rows );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function modelsIn( string $store ): array {
		$found = [];

		// whisper-cpp models are plain top-level .bin files.
		foreach ( glob( $store . '/*.bin' ) ?: [] as $bin ) {
			$name           = preg_replace( '/\.bin$/', '', basename( $bin ) );
			$found[ $name ] = [
				'name'      => $name,
				'framework' => 'whisper-cpp',
				'kind'      => 'asr',
				'relative'  => basename( $bin ),
				'sizeMb'    => (int) round( ( filesize( $bin ) ?: 0 ) / 1048576 ),
			];
		}

		// CoreML bundles, at whatever depth their framework nests them.
		foreach ( $this->bundleDirs( $store ) as $dir ) {
			$relative = ltrim( substr( $dir, strlen( $store ) ), '/' );
			$name     = basename( $dir );
			$sizeMb   = (int) round( $this->treeSize( $dir ) / 1048576 );

			// Two directories in a store can share a bundle's name — the real one
			// and its download-cache stub. Keep the larger; a 0-byte stub must
			// never stand in for 1.2 GB of weights.
			if ( isset( $found[ $name ] ) && $found[ $name ]['sizeMb'] >= $sizeMb ) {
				continue;
			}

			$found[ $name ] = [
				'name'      => $name,
				'framework' => strtok( $relative, '/' ) ?: 'unknown',
				'kind'      => 'asr',
				'relative'  => $relative,
				'sizeMb'    => $sizeMb,
			];
		}

		// Diarization is support, not a listed model, but it decides whether
		// --speakers works offline, so status must show whether it is present.
		if ( is_dir( $store . '/speakerkit' ) ) {
			$found['speakerkit'] = [
				'name'      => 'speakerkit',
				'framework' => 'speakerkit',
				'kind'      => 'support',
				'relative'  => 'speakerkit',
				'sizeMb'    => (int) round( $this->treeSize( $store . '/speakerkit' ) / 1048576 ),
			];
		}

		return $found;
	}

	/**
	 * Directories holding at least one *.mlmodelc child.
	 *
	 * Two subtrees are excluded because both mirror the bundle shape without
	 * being models:
	 *
	 *   .cache/huggingface/download/<model>/  download scaffolding, often empty
	 *   speakerkit/...                        diarization internals (W8A16 etc),
	 *                                         reported once as a support row
	 *
	 * The cache exclusion is the important one — a local store with a large-v3
	 * download stub and none of its weights would otherwise be reported as
	 * holding large-v3.
	 *
	 * @return string[]
	 */
	private function bundleDirs( string $store ): array {
		$dirs  = [];
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $store, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $item ) {
			if ( ! $item->isDir() || '.mlmodelc' !== substr( $item->getFilename(), -9 ) ) {
				continue;
			}

			$bundle   = dirname( $item->getPathname() );
			$relative = ltrim( substr( $bundle, strlen( $store ) ), '/' );

			if ( str_contains( $relative, '/.cache/' ) || str_starts_with( $relative, '.cache/' ) ) {
				continue;
			}

			if ( 'speakerkit' === strtok( $relative, '/' ) ) {
				continue;
			}

			$dirs[ $bundle ] = true;
		}

		return array_keys( $dirs );
	}

	private function treeSize( string $path ): int {
		if ( ! is_dir( $path ) ) {
			return is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		$bytes = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$bytes += $item->getSize();
			}
		}

		return $bytes;
	}
}
