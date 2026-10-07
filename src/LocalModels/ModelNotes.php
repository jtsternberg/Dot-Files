<?php

namespace JT\LocalModels;

/**
 * Notes about local models — when to use each, measured speed, test results —
 * plus a graveyard of models that were tried and removed, so nobody re-benchmarks
 * a model that was already killed.
 *
 * Keys are engine-qualified: `ollama:<tag>` for Ollama and the `mw models list` ID
 * for MacWhisper. The file began as Ollama-only (`ollama-why`), with bare tags and
 * `sd` for the AI-LAB drive; it is migrated in memory on every read and rewritten
 * in the new shape on the first write, after a one-time backup.
 *
 * Where a model lives is NOT this store's to know: the engines' residency is. The
 * `location` kept here is only the last-known answer, for a drive that is not
 * mounted right now.
 */
final class ModelNotes {

	public const VERSION       = 2;
	public const BACKUP_SUFFIX = '.pre-aimodels.bak';

	public const LOCAL    = AbstractStoreEngine::LOCAL;
	public const EXTERNAL = AbstractStoreEngine::EXTERNAL;
	public const BOTH     = 'both';

	/** `mw models list` ID prefixes, plus the engine prefix support bundles use. */
	private const MACWHISPER_PREFIXES = [ 'whisperkit', 'whisper-cpp', 'parakeet-pro', 'qwen3-asr', 'macwhisper' ];

	/** @var array{models: array<string, array<string, mixed>>, graveyard: array<string, array<string, mixed>>}|null */
	private ?array $data = null;

	private bool $legacy = false;

	public function __construct( private readonly string $file ) {
	}

	/** Kept at the name `ollama-why` always used, so the dotfiles symlink and history carry over. */
	public static function defaultPath( ?string $home = null ): string {
		return rtrim( $home ?: (string) getenv( 'HOME' ), '/' ) . '/.ollama-why.json';
	}

	public function path(): string {
		return $this->file;
	}

	/**
	 * Turn user input into a note key. Bare names are Ollama tags, because every
	 * key `ollama-why` ever wrote was one.
	 *
	 * @param string[] $knownIds inventory IDs, taken as-is whatever their prefix
	 */
	public static function resolveKey( string $input, array $knownIds = [] ): string {
		if ( in_array( $input, $knownIds, true ) ) {
			return $input;
		}

		$prefix = strstr( $input, ':', true );
		if ( 'ollama' === $prefix || in_array( $prefix, self::MACWHISPER_PREFIXES, true ) ) {
			return $input;
		}

		return 'ollama:' . $input;
	}

	public static function engineOf( string $key ): string {
		return str_starts_with( $key, 'ollama:' ) ? 'ollama' : 'macwhisper';
	}

	/** The key without its `ollama:` prefix — what Ollama itself calls the model. */
	public static function engineName( string $key ): string {
		return str_starts_with( $key, 'ollama:' ) ? substr( $key, 7 ) : $key;
	}

	/** local | external | both, accepting the old `sd` for external. */
	public static function normalizeLocation( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( 'sd' === $value ) {
			return self::EXTERNAL;
		}

		return in_array( $value, [ self::LOCAL, self::EXTERNAL, self::BOTH ], true ) ? $value : null;
	}

	/**
	 * @return array{models: array<string, array<string, mixed>>, graveyard: array<string, array<string, mixed>>}
	 */
	public function data(): array {
		return $this->data ??= $this->load();
	}

	/** @return array<string, mixed>|null */
	public function note( string $key ): ?array {
		return $this->data()['models'][ $key ] ?? null;
	}

	/** @return array<string, mixed>|null */
	public function buried( string $key ): ?array {
		return $this->data()['graveyard'][ $key ] ?? null;
	}

	/**
	 * Merge fields into a note, creating it if needed.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed> the saved note
	 */
	public function set( string $key, array $fields ): array {
		$data = $this->data();
		$note = array_merge( $data['models'][ $key ] ?? [], $fields );

		$this->data['models'][ $key ] = $note;
		$this->save();

		return $note;
	}

	/**
	 * `tested` is a dated history, one ` | `-joined entry per run, so new results
	 * append. An entry already recorded verbatim is not added twice.
	 */
	public static function appendTested( ?string $existing, string $new ): string {
		if ( null === $existing || '' === trim( $existing ) ) {
			return $new;
		}
		if ( in_array( trim( $new ), array_map( 'trim', explode( ' | ', $existing ) ), true ) ) {
			return $existing;
		}

		return $existing . ' | ' . $new;
	}

	/**
	 * Drop a note; with $archive, move it (or a bare tombstone) to the graveyard.
	 *
	 * @return bool whether a note existed
	 */
	public function remove( string $key, bool $archive = false, ?string $tested = null, ?string $date = null, bool $replaceTested = false ): bool {
		$data = $this->data();
		$had  = isset( $data['models'][ $key ] );

		if ( ! $had && ! $archive ) {
			return false;
		}

		if ( $archive ) {
			$entry               = $data['models'][ $key ] ?? [];
			$entry['removed_at'] = $date ?? date( 'Y-m-d' );
			if ( null !== $tested ) {
				$entry['tested'] = $replaceTested ? $tested : self::appendTested( $entry['tested'] ?? null, $tested );
			}
			$this->data['graveyard'][ $key ] = $entry;
		}

		unset( $this->data['models'][ $key ] );
		$this->save();

		return $had;
	}

	public function forget( string $key ): bool {
		if ( ! isset( $this->data()['graveyard'][ $key ] ) ) {
			return false;
		}

		unset( $this->data['graveyard'][ $key ] );
		$this->save();

		return true;
	}

	/**
	 * A local model that reconcile just linked into AI-LAB now lives on both.
	 */
	public function promoteLocalToBoth( string $key ): bool {
		if ( self::LOCAL !== ( $this->note( $key )['location'] ?? null ) ) {
			return false;
		}

		$this->set( $key, [ 'location' => self::BOTH ] );

		return true;
	}

	/**
	 * Notes joined to the engines' inventory: the one accessor every `why` view
	 * (text, --json, history) reads.
	 *
	 * $status is Watcher::status() — the same call `aimodels status` renders — so
	 * residency is never re-derived here. Each row is an inventory row (or, for a
	 * noted model the inventory cannot see, a synthesized one) plus:
	 *
	 *   location  local | external | both | null — from the inventory; while
	 *             AI-LAB is ejected the external half falls back to the note
	 *   state     available  in the store the engine points at
	 *             stranded   in a store, but not the active one
	 *             offline    noted as on AI-LAB, which is not mounted
	 *             orphaned   in neither store
	 *   note      the stored note, or null
	 *
	 * @param array<string, mixed> $status
	 * @return array{mounted: bool, models: array<int, array<string, mixed>>, graveyard: array<int, array<string, mixed>>}
	 */
	public function inventory( array $status, ?string $engine = null ): array {
		$mounted = (bool) $status['mounted'];
		$notes   = $this->data()['models'];
		$rows    = [];

		foreach ( $status['engines'] as $name => $state ) {
			foreach ( $state['models'] as $model ) {
				$rows[ $model['id'] ] = $model;
			}
		}

		foreach ( array_keys( $notes ) as $key ) {
			$rows[ $key ] ??= [
				'id'        => $key,
				'engine'    => self::engineOf( $key ),
				'name'      => self::engineName( $key ),
				'framework' => null,
				'kind'      => null,
				'path'      => null,
				'sizeMb'    => null,
				'local'     => false,
				'external'  => false,
				'available' => false,
			];
		}

		$out = [];
		foreach ( $rows as $key => $row ) {
			if ( null !== $engine && $row['engine'] !== $engine ) {
				continue;
			}

			$note   = $notes[ $key ] ?? null;
			$stored = $note['location'] ?? null;

			$external = $row['external']
				|| ( ! $mounted && in_array( $stored, [ self::EXTERNAL, self::BOTH ], true ) );
			$location = match ( true ) {
				$row['local'] && $external => self::BOTH,
				$row['local']              => self::LOCAL,
				$external                  => self::EXTERNAL,
				default                    => null,
			};

			$row['location'] = $location;
			$row['state']    = match ( true ) {
				$row['available']                     => 'available',
				$row['local'] || $row['external']     => 'stranded',
				! $mounted && null !== $location      => 'offline',
				default                               => 'orphaned',
			};
			$row['note']     = $note;
			$out[]           = $row;
		}

		return [
			'mounted'   => $mounted,
			'models'    => $out,
			'graveyard' => $this->graveyard( $engine ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function graveyard( ?string $engine = null ): array {
		$out = [];
		foreach ( $this->data()['graveyard'] as $key => $entry ) {
			if ( null !== $engine && self::engineOf( $key ) !== $engine ) {
				continue;
			}

			$out[] = [ 'id' => $key, 'engine' => self::engineOf( $key ), 'name' => self::engineName( $key ) ] + $entry;
		}

		return $out;
	}

	/**
	 * @return array{models: array<string, array<string, mixed>>, graveyard: array<string, array<string, mixed>>}
	 */
	private function load(): array {
		$empty = [ 'models' => [], 'graveyard' => [] ];
		if ( ! is_file( $this->file ) ) {
			return $empty;
		}

		$raw = json_decode( (string) file_get_contents( $this->file ), true );
		if ( ! is_array( $raw ) ) {
			return $empty;
		}

		if ( self::VERSION === ( $raw['version'] ?? null ) ) {
			return [
				'models'    => is_array( $raw['models'] ?? null ) ? $raw['models'] : [],
				'graveyard' => is_array( $raw['graveyard'] ?? null ) ? $raw['graveyard'] : [],
			];
		}

		$this->legacy = true;

		// The oldest shape had every model at the top level.
		if ( ! isset( $raw['models'] ) && ! isset( $raw['graveyard'] ) ) {
			$raw = [ 'models' => $raw, 'graveyard' => [] ];
		}

		return [
			'models'    => $this->migrateSection( is_array( $raw['models'] ?? null ) ? $raw['models'] : [] ),
			'graveyard' => $this->migrateSection( is_array( $raw['graveyard'] ?? null ) ? $raw['graveyard'] : [] ),
		];
	}

	/**
	 * Every legacy key is an Ollama tag, so it is prefixed unconditionally rather
	 * than guessed at by resolveKey().
	 *
	 * @param array<string, mixed> $section
	 * @return array<string, array<string, mixed>>
	 */
	private function migrateSection( array $section ): array {
		$out = [];
		foreach ( $section as $key => $entry ) {
			$entry = is_array( $entry ) ? $entry : [ 'when' => (string) $entry ];
			if ( isset( $entry['location'] ) && is_string( $entry['location'] ) ) {
				$entry['location'] = self::normalizeLocation( $entry['location'] ) ?? $entry['location'];
			}

			$key         = str_starts_with( (string) $key, 'ollama:' ) ? (string) $key : 'ollama:' . $key;
			$out[ $key ] = array_merge( $out[ $key ] ?? [], $entry );
		}

		return $out;
	}

	private function save(): void {
		if ( $this->legacy && is_file( $this->file ) && ! file_exists( $this->file . self::BACKUP_SUFFIX ) ) {
			copy( $this->file, $this->file . self::BACKUP_SUFFIX );
		}

		$data = $this->data();
		ksort( $data['models'] );
		ksort( $data['graveyard'] );

		// file_put_contents follows a symlink, so the dotfiles link survives.
		file_put_contents(
			$this->file,
			json_encode(
				[
					'version'   => self::VERSION,
					'models'    => (object) $data['models'],
					'graveyard' => (object) $data['graveyard'],
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n"
		);

		$this->legacy = false;
	}
}
