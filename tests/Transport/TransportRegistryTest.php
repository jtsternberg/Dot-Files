<?php
namespace JT\Tests\Transport;

use JT\Tests\TestCase;
use JT\Transport\TransportRegistry;
use RuntimeException;

/**
 * Transport selection: who is reachable, who owns a row, and who a resurrect
 * lands in.
 *
 * The fakes here IMPLEMENT SessionTransport (see FakeTransport) rather than
 * subclassing a real one, so none of them can silently stop doubling anything
 * when a body moves.
 */
final class TransportRegistryTest extends TestCase
{
	private function fake(string $name, array $sids = [], bool $available = true, bool $targetable = true): FakeTransport
	{
		$rows = array_map(
			fn($sid) => FakeTransport::row($name, $sid, ['targetable' => $targetable, 'reason' => $targetable ? null : 'unbound']),
			$sids
		);
		return new FakeTransport($name, $rows, $available);
	}

	private function registry(array $transports): TransportRegistry
	{
		return new TransportRegistry($this->cli, $transports);
	}

	// ── availability + ordering ───────────────────────────────────────────────

	public function testAvailableListsOnlyReachableTransports(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'], false), $this->fake('herdr', ['b'])]);

		$this->assertSame(['herdr'], array_map(fn($t) => $t->name(), $reg->available()));
	}

	public function testAvailablePutsCmuxFirstRegardlessOfRegistrationOrder(): void
	{
		// Registration order must not decide the incumbent: `resurrect` with no flag
		// takes available()[0], and that has to be cmux whenever cmux is up.
		$reg = $this->registry([$this->fake('herdr', ['b']), $this->fake('cmux', ['a'])]);

		$this->assertSame(['cmux', 'herdr'], array_map(fn($t) => $t->name(), $reg->available()));
	}

	public function testUnavailableRegistryHasNoAvailableTransports(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'], false), $this->fake('herdr', ['b'], false)]);

		$this->assertSame([], $reg->available());
		$this->assertNull($reg->primary());
	}

	// ── the union ─────────────────────────────────────────────────────────────

	public function testRegistryUnionsLiveSessionsAcrossTransports(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a']), $this->fake('herdr', ['b'])]);

		$ids = array_column($reg->liveSessions(), 'session_id');
		sort($ids);
		$this->assertSame(['a', 'b'], $ids);
	}

	public function testRegistrySkipsUnavailableTransportsInTheUnion(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'], false), $this->fake('herdr', ['b'])]);

		$this->assertSame(['b'], array_column($reg->liveSessions(), 'session_id'));
	}

	public function testRegistryDedupesASessionReportedByBoth(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a']), $this->fake('herdr', ['a'])]);

		$this->assertCount(1, $reg->liveSessions());
	}

	/**
	 * The collision is not hypothetical: cmux ALREADY reports every herdr-hosted
	 * claude session, because Claude Code writes ~/.claude/sessions/<pid>.json
	 * whatever multiplexer it runs under — but cmux cannot bind it to one of its own
	 * surfaces, so the row comes back untargetable ("CMUX_SURFACE_ID not found among
	 * cmux surfaces"). Taking cmux's row on a collision would therefore present every
	 * herdr session as unburyable and drop the row that can actually be driven.
	 *
	 * So the row that WINS is the one whose transport demonstrably hosts the session:
	 * an untargetable row is that transport's own admission that it cannot reach it.
	 */
	public function testHostingTransportWinsWhenTheOtherCannotTargetTheSession(): void
	{
		$reg = $this->registry([
			$this->fake('cmux',  ['shared'], true, false),
			$this->fake('herdr', ['shared'], true, true),
		]);

		$rows = $reg->liveSessions();
		$this->assertCount(1, $rows);
		$this->assertSame('herdr', $rows[0]['transport'], 'the transport that can target the session hosts it');
		$this->assertTrue($rows[0]['targetable']);
	}

	public function testCmuxWinsWhenBothTransportsCanTargetTheSession(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['shared']), $this->fake('herdr', ['shared'])]);

		$this->assertSame('cmux', $reg->liveSessions()[0]['transport'], 'incumbent breaks a genuine tie');
	}

	public function testCmuxOnlyUnionPreservesRowOrderExactly(): void
	{
		// Tripwire: with herdr absent, nothing about the union may reorder or drop a
		// row — `candidates` output for a cmux-only user must be what it was.
		$cmux = new FakeTransport('cmux', [
			FakeTransport::row('cmux', 'a'),
			FakeTransport::row('cmux', 'b', ['targetable' => false, 'reason' => 'unbound']),
			FakeTransport::row('cmux', 'c'),
		]);
		$reg = $this->registry([$cmux, $this->fake('herdr', ['x'], false)]);

		$this->assertSame(['a', 'b', 'c'], array_column($reg->liveSessions(), 'session_id'));
	}

	// ── routing a row home ────────────────────────────────────────────────────

	public function testForRowRoutesARowBackToItsOwnTransport(): void
	{
		$cmux = $this->fake('cmux', ['a']);
		$reg  = $this->registry([$cmux, $this->fake('herdr', ['b'])]);

		$this->assertSame($cmux, $reg->forRow(['transport' => 'cmux', 'session_id' => 'a']));
	}

	public function testForRowRoutesToAnUnavailableTransportItStillKnows(): void
	{
		// A row is only ever held because some transport produced it. If that transport
		// has since gone away, the caller must fail on the drive with the transport's
		// own error, not be quietly handed a different multiplexer's surfaces.
		$herdr = $this->fake('herdr', ['b'], false);
		$reg   = $this->registry([$this->fake('cmux', ['a']), $herdr]);

		$this->assertSame($herdr, $reg->forRow(['transport' => 'herdr', 'session_id' => 'b']));
	}

	public function testForRowThrowsForAnUnregisteredTransportName(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'])]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Unknown session transport 'tmux'");
		$reg->forRow(['transport' => 'tmux', 'session_id' => 'a']);
	}

	public function testForRowThrowsForARowThatNamesNoTransport(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'])]);

		$this->expectException(RuntimeException::class);
		$reg->forRow(['session_id' => 'a']);
	}

	public function testByNameFindsARegisteredTransportAndNullsAnythingElse(): void
	{
		$herdr = $this->fake('herdr', ['b']);
		$reg   = $this->registry([$this->fake('cmux', ['a']), $herdr]);

		$this->assertSame($herdr, $reg->byName('herdr'));
		$this->assertNull($reg->byName('tmux'));
	}

	// ── the resurrect target ──────────────────────────────────────────────────

	public function testPrimaryHonoursAnExplicitTransportRequest(): void
	{
		$herdr = $this->fake('herdr', ['b']);
		$reg   = $this->registry([$this->fake('cmux', ['a']), $herdr]);

		$this->assertSame($herdr, $reg->primary('herdr'));
	}

	public function testPrimaryDefaultsToCmuxWhenBothAreUp(): void
	{
		$cmux = $this->fake('cmux', ['a']);
		$reg  = $this->registry([$this->fake('herdr', ['b']), $cmux]);

		$this->assertSame($cmux, $reg->primary(), 'incumbent, and the only one with full surface fidelity');
	}

	public function testPrimaryFallsBackToTheOnlyAvailableTransport(): void
	{
		$herdr = $this->fake('herdr', ['b']);
		$reg   = $this->registry([$this->fake('cmux', ['a'], false), $herdr]);

		$this->assertSame($herdr, $reg->primary());
	}

	// ── who hosts the caller ─────────────────────────────────────────────────

	/**
	 * The registry finds the caller wherever it is, which is the fix for dotfiles-8wh:
	 * primary() is cmux-first, so asking the primary alone left every herdr-hosted agent
	 * unclaimed and its self-guards collapsed together.
	 */
	public function testSelfSurfaceRefAsksEveryTransportNotJustThePrimary(): void
	{
		$cmux  = new FakeTransport('cmux', [], true);
		$herdr = new FakeTransport('herdr', [], true, false, [], null, 'wF:p3');
		$reg   = $this->registry([$cmux, $herdr]);

		$this->assertSame('cmux', $reg->primary()->name(), 'the incumbent is still the primary');
		$this->assertSame('wF:p3', $reg->selfSurfaceRef());
		$this->assertSame('herdr', $reg->selfTransport()->name());
	}

	/** Nobody claims the caller — a human in a plain terminal. */
	public function testSelfSurfaceRefIsNullWhenNoTransportClaimsTheCaller(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a']), $this->fake('herdr', ['b'])]);

		$this->assertNull($reg->selfSurfaceRef());
		$this->assertNull($reg->selfTransport());
	}

	/**
	 * An UNREACHABLE transport still gets to claim the caller. Its answer is an env
	 * read, and an agent whose multiplexer server has just died is exactly the caller
	 * that most needs the guard — gating this on available() would drop protection at
	 * the worst moment.
	 */
	public function testAnUnreachableTransportStillClaimsItsOwnCaller(): void
	{
		$down = new FakeTransport('herdr', [], false, false, [], null, 'wF:p3');
		$reg  = $this->registry([$this->fake('cmux', ['a']), $down]);

		$this->assertSame(['cmux'], array_map(fn($t) => $t->name(), $reg->available()));
		$this->assertSame('wF:p3', $reg->selfSurfaceRef());
	}

	public function testPrimaryRefusesAnUnknownRequestedTransport(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a'])]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Unknown transport 'tmux'");
		$reg->primary('tmux');
	}

	public function testPrimaryRefusesARequestedTransportThatIsNotReachable(): void
	{
		$reg = $this->registry([$this->fake('cmux', ['a']), $this->fake('herdr', ['b'], false)]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("herdr is not reachable");
		$reg->primary('herdr');
	}
}
