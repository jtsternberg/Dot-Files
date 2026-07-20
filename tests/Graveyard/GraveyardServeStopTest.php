<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-xr9 — `graveyard serve --stop` / `page --stop`. Exercises the
 * decision logic in Graveyard::stopServer() without a real server or sockets:
 * a test double overrides the I/O seams (serverListening / pidIsOurServer /
 * findServerPid / signalPid) so each branch — no-op, stale-pid guard, foreign
 * listener, clean stop — is reachable deterministically. Also covers the pure
 * state-file parser readServeState().
 */
final class GraveyardServeStopTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	protected function makeRoot(): string
	{
		$root = sys_get_temp_dir() . '/gy-stop-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		return $root;
	}

	/** Write a .serve.json descriptor into the current store root. */
	protected function writeState(string $root, array $state): void
	{
		file_put_contents($root . '/.serve.json', json_encode($state));
	}

	/** Run a callable with stdout swallowed (msg/successMsg/err would otherwise noise the run). */
	protected function quiet(callable $fn)
	{
		ob_start();
		$r = $fn();
		ob_get_clean();
		return $r;
	}

	// --- readServeState() (pure parse) ---------------------------------------

	public function testReadServeStateMissingIsNull(): void
	{
		$this->makeRoot();
		$this->assertNull($this->gy->readServeState());
	}

	public function testReadServeStateUnparsableIsNull(): void
	{
		$root = $this->makeRoot();
		file_put_contents($root . '/.serve.json', '{ this is not json');
		$this->assertNull($this->gy->readServeState());
	}

	public function testReadServeStateParsed(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 4242, 'host' => 'graveyard.localhost']);
		$state = $this->gy->readServeState();
		$this->assertIsArray($state);
		$this->assertSame(8787, $state['port']);
		$this->assertSame(4242, $state['pid']);
	}

	// --- stopServer() branches -----------------------------------------------

	public function testNoStateNothingListeningIsNoOpSuccess(): void
	{
		$this->makeRoot();
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->listening = false;
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
	}

	public function testNoStateButForeignListenerIsLeftAlone(): void
	{
		$this->makeRoot();
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->listening = true; // something holds the port, but we have no record of it
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([], $gy->signalled, 'must never signal a server it did not record');
	}

	public function testStalePidWithNothingListeningClearsStateAndSucceeds(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 999999]);
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->ours      = false; // recorded pid is stale / not our server
		$gy->listening = false; // and nothing is on the port
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([], $gy->signalled);
		$this->assertFileDoesNotExist($root . '/.serve.json', 'stale state should be cleaned up');
	}

	public function testStalePidWithForeignListenerIsLeftAlone(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 999999]);
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->ours      = false; // recorded pid recycled — belongs to someone else
		$gy->listening = true;  // and a different process holds the port
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([], $gy->signalled, 'a recycled PID / foreign listener must never be killed');
		$this->assertFileExists($root . '/.serve.json', 'foreign listener: leave state untouched');
	}

	public function testValidPidIsSigtermedAndStateCleared(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 4242]);
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->ours      = true;  // recorded pid really is our server
		$gy->listening = true;  // ...and it goes quiet after the signal
		$gy->quietAfterSignal = true;
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([4242], $gy->signalled);
		$this->assertFileDoesNotExist($root . '/.serve.json');
	}

	public function testPortStillListeningAfterSignalIsFailure(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 4242]);
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->ours      = true;
		$gy->listening = true;
		$gy->quietAfterSignal = false; // won't die
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(1, $code, 'genuine failure: port never freed');
		$this->assertFileExists($root . '/.serve.json', 'do not clear state when the stop failed');
	}

	public function testZeroPidResolvesListenerByLookupThenStops(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 0]); // reused a listener we didn't spawn
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->listening = true;
		$gy->foundPid  = 5555; // findServerPid identifies it as ours
		$gy->quietAfterSignal = true;
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([5555], $gy->signalled);
	}

	public function testZeroPidUnidentifiedListenerIsLeftAlone(): void
	{
		$root = $this->makeRoot();
		$this->writeState($root, ['port' => 8787, 'pid' => 0]);
		$gy = new StopDouble($this->cli, $this->cmux);
		$gy->listening = true;
		$gy->foundPid  = null; // can't confirm it's ours
		$code = $this->quiet(fn() => $gy->stopServer());
		$this->assertSame(0, $code);
		$this->assertSame([], $gy->signalled);
	}
}

/**
 * Test double: overrides every I/O seam stopServer() touches so branches are
 * driven by plain flags. `listening` flips to false once a signal is delivered
 * when `quietAfterSignal` is set, modelling a server that shuts down on SIGTERM.
 */
class StopDouble extends Graveyard
{
	public bool $listening = false;
	public bool $ours = false;
	public bool $quietAfterSignal = true;
	public ?int $foundPid = null;
	public array $signalled = [];

	protected function serverListening(int $port): bool { return $this->listening; }
	protected function pidIsOurServer(int $pid, int $port): bool { return $this->ours; }
	protected function findServerPid(int $port): ?int { return $this->foundPid; }
	protected function signalPid(int $pid, int $signal): void
	{
		$this->signalled[] = $pid;
		if ($this->quietAfterSignal) { $this->listening = false; }
	}
}
