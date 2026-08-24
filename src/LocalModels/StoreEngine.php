<?php

namespace JT\LocalModels;

/**
 * One model-store engine (Ollama, MacWhisper) as the watcher sees it.
 *
 * The whole point of this interface is that the watcher never knows which engine
 * it is driving: it asks each one to `apply()` the location implied by whether
 * AI-LAB is mounted. Engine-specific behaviour — Ollama's OLLAMA_MODELS launchd
 * env, MacWhisper's copy-based reconcile — hides behind postApply() and
 * reconcile(), never in the watcher.
 */
interface StoreEngine {

	/** Machine name, e.g. 'ollama'. */
	public function name(): string;

	/** Human label, e.g. 'Ollama'. */
	public function label(): string;

	/** The app-facing path that is a symlink to one of the stores. */
	public function symlinkPath(): string;

	/** @param string $location 'local'|'external' */
	public function storePath( string $location ): string;

	/** Structural check; never mutates. */
	public function preflight(): Preflight;

	/** Which store the symlink currently resolves to, or null. */
	public function currentLocation(): ?string;

	public function apply( string $location, bool $dryRun = false ): ApplyResult;

	/**
	 * Engine-specific: Ollama symlinks local into the external tree; MacWhisper
	 * must COPY, because a symlinked bundle is invisible to the app.
	 *
	 * @param array<string, mixed> $options
	 */
	public function reconcile( array $options = [] ): ApplyResult;

	/**
	 * Standing advice for operating at this location — e.g. that new downloads
	 * while on the local store will need reconciling after remount.
	 *
	 * @return string[]
	 */
	public function advisories( string $location ): array;

	/**
	 * Release whatever this engine is holding open on the external drive, so it
	 * can be ejected. Engine-specific by necessity: Ollama unloads its loaded
	 * models (`ollama stop`), which frees the llama-server file descriptors while
	 * leaving Ollama.app running. Returns what it actually released.
	 *
	 * @return string[]
	 */
	public function releaseHolds(): array;

	/**
	 * Rows for `aimodels status`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function residency(): array;
}
