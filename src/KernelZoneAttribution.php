<?php

namespace JT;

use JT\LocalModels\OllamaRunners;

/**
 * Tells a kernel GPU zone holding resident LLM weights apart from a leak.
 *
 * The watchdog alerts on any single kernel zone at or above 8G, because that is
 * its only view of wired-kernel memory: `%free` and `memory_pressure` are blind
 * to it, and the 2026-07-13 `data.kalloc.1024` leak reached 19G and hard-panicked
 * this Mac while `%free` still read ~90%.
 *
 * A local LLM trips that threshold legitimately. On 2026-09-12 two resident
 * Ollama models — 2.1GB and 6.6GB, both 100% GPU — put
 * `com.apple.iokit.IOGPUFamily.API` at 8.8G, and the watchdog sent a
 * panic-flavoured alert about memory the machine was deliberately using. Worse
 * than the noise: a 💥 alert that cries wolf is one JT stops reading, and it is
 * the only alert that arrives minutes before a panic.
 *
 * So attribution is narrow in two directions, both of which matter more than
 * silencing the noise:
 *
 * 1. Only GPU zones are attributable. Resident weights explain nothing about
 *    `data.kalloc.1024` or a VM zone, so those alert exactly as before.
 * 2. Only up to the resident total plus a small headroom. A GPU zone GBs beyond
 *    what is loaded is a GPU leak, and a leak that happens to coincide with a
 *    loaded model must not hide behind it.
 */
final class KernelZoneAttribution {

	/**
	 * Zone-name prefixes whose size resident GPU weights can account for.
	 *
	 * `IOGPUFamily.API` is where llama.cpp's Metal buffers land; the others move
	 * with it (driver state, IOSurface backing store) and are listed so a model
	 * large enough to make one of them the single largest zone reads the same way.
	 */
	const GPU_ZONES = [
		'com.apple.iokit.IOGPUFamily',
		'com.apple.iokit.IOSurface',
		'com.apple.iokit.IOMobileGraphicsFamily',
		'com.apple.AGX',
	];

	/**
	 * GB a GPU zone may exceed the resident total by and still count as explained.
	 *
	 * Metal command buffers, driver allocations and IOSurface backing store come
	 * and go around the weights, and the zone's own reading drifted ~100MB across
	 * a 20-second sample. Two GB covers that without excusing a leak: the panic
	 * zone grew by 19G.
	 */
	const HEADROOM_GB = 2.0;

	/** Whether resident GPU weights can account for this zone being large. */
	public static function explains(
		string $zone,
		float $zoneGb,
		float $gpuResidentGb,
		float $headroomGb = self::HEADROOM_GB
	): bool {
		if ( $gpuResidentGb <= 0.0 || ! self::isGpuZone( $zone ) ) {
			return false;
		}

		return $zoneGb <= $gpuResidentGb + $headroomGb;
	}

	public static function isGpuZone( string $zone ): bool {
		foreach ( self::GPU_ZONES as $prefix ) {
			if ( 0 === strpos( $zone, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One clause naming what the models account for, for an alert that still fires.
	 *
	 * The zone-map backstop is not suppressed by attribution — near-exhaustion is
	 * near-exhaustion however the map filled — but the remedy changes completely,
	 * from hunting an EndpointSecurity flood to unloading a model, so the alert
	 * has to say which one it is. Empty string when nothing is resident, so the
	 * caller can concatenate it unconditionally.
	 */
	public static function describe( float $gpuResidentGb ): string {
		if ( $gpuResidentGb <= 0.0 ) {
			return '';
		}

		return sprintf(
			'%.1fG of it is Ollama models resident on the GPU (`ollama stop <model>` reclaims it)',
			$gpuResidentGb
		);
	}

	/**
	 * GB of weights Ollama has wired onto the GPU right now.
	 *
	 * Memoized per process so this stays a convenience and not a hidden `ollama ps`
	 * per call — OllamaRunners memoizes per instance, and a static that built a
	 * fresh one each time would quietly undo that for every caller of this shortcut.
	 */
	public static function gpuResidentGb(): float {
		static $gb = null;

		return $gb ??= ( new OllamaRunners() )->gpuResidentGb();
	}
}
