<?php
namespace JT\Tests\GitPub;

use JT\Tests\TestCase;
use JT\GitPub;

/**
 * gitpub — publish the current repo to a bare repo on one of JT's own tailnet
 * hosts (self-hosted git remote over SSH), then wire up origin and push.
 *
 * The pure units (name derivation, URL building, config/user resolution) are
 * tested directly. The whole publish flow is driven through an injected runner
 * that records the command sequence and returns scripted results — so the suite
 * never opens an SSH connection or mutates a real repo, mirroring the
 * stub-binary seam used for Godo::resolvePath (tests/Godo/GodoTest.php).
 */
final class GitPubTest extends TestCase
{
	/** @var string[] Commands the fake runner saw, in order. */
	private array $commands = [];

	private string $oldUser = '';

	protected function setUp(): void
	{
		parent::setUp();
		// Deterministic local user so the "omit user@ when it's the default"
		// rule is predictable regardless of the machine running the suite.
		$this->oldUser = (string) getenv('USER');
		putenv('USER=tester');
		// Silence prompt/summary output into throwaway memory streams.
		$this->cli->setStreams(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'));
	}

	protected function tearDown(): void
	{
		putenv('USER=' . $this->oldUser);
	}

	/**
	 * Build a recording runner. $rules maps a substring needle => a partial
	 * response array (exitCode/output/error); the first matching needle wins,
	 * and anything unmatched succeeds with empty output.
	 *
	 * @param array<string,array> $rules
	 */
	private function runner(array $rules): callable
	{
		$this->commands = [];

		return function (string $cmd) use ($rules): array {
			$this->commands[] = $cmd;
			foreach ($rules as $needle => $resp) {
				if (false !== strpos($cmd, $needle)) {
					return $resp + ['exitCode' => 0, 'output' => '', 'error' => ''];
				}
			}

			return ['exitCode' => 0, 'output' => '', 'error' => ''];
		};
	}

	private function make(array $args, array $rules): GitPub
	{
		$this->cli->setArgs(array_merge(['gitpub'], $args));

		return new GitPub($this->cli, $this->runner($rules));
	}

	/** True if any recorded command contains the needle. */
	private function ran(string $needle): bool
	{
		foreach ($this->commands as $cmd) {
			if (false !== strpos($cmd, $needle)) {
				return true;
			}
		}

		return false;
	}

	/** Index of the first recorded command containing the needle (or -1). */
	private function orderOf(string $needle): int
	{
		foreach ($this->commands as $i => $cmd) {
			if (false !== strpos($cmd, $needle)) {
				return $i;
			}
		}

		return -1;
	}

	// ---- Pure: name derivation -------------------------------------------

	public function testKebabNameLowercasesAndHyphenates(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame('my-cool-repo', $gp->kebabName('My Cool Repo'));
	}

	public function testKebabNameCollapsesPunctuationRuns(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame('some-repo-v2', $gp->kebabName('Some_Repo.v2'));
	}

	public function testKebabNameTrimsLeadingTrailingSeparators(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame('weird-name', $gp->kebabName(' --Weird  Name-- '));
	}

	// ---- Pure: remote URL -------------------------------------------------

	public function testBuildRemoteUrlWithUser(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame(
			'jtsternberg@jt-host:git-remotes/my-repo.git',
			$gp->buildRemoteUrl('jt-host', 'jtsternberg', 'my-repo')
		);
	}

	public function testBuildRemoteUrlOmitsEmptyUser(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame(
			'jt-host:git-remotes/my-repo.git',
			$gp->buildRemoteUrl('jt-host', null, 'my-repo')
		);
	}

	public function testBuildRemoteUrlStripsEmbeddedUserFromHost(): void
	{
		$gp = new GitPub($this->cli, $this->runner([]));
		$this->assertSame(
			'bob@jt-host:git-remotes/my-repo.git',
			$gp->buildRemoteUrl('someone@jt-host', 'bob', 'my-repo')
		);
	}

	// ---- Config / user resolution ----------------------------------------

	public function testResolveHostPrefersFlagOverConfig(): void
	{
		$gp = $this->make(['--host=flaghost'], ['gitpub.host' => ['output' => 'confighost']]);
		$this->assertSame('flaghost', $gp->resolveHost());
	}

	public function testResolveHostFallsBackToConfig(): void
	{
		$gp = $this->make([], ['gitpub.host' => ['output' => 'confighost']]);
		$this->assertSame('confighost', $gp->resolveHost());
	}

	public function testResolveHostNullWhenUnset(): void
	{
		$gp = $this->make([], ['gitpub.host' => ['exitCode' => 1]]);
		$this->assertNull($gp->resolveHost());
	}

	public function testResolveUserPrefersFlag(): void
	{
		$gp = $this->make(['--user=flaguser'], ['gitpub.user' => ['output' => 'configuser']]);
		$this->assertSame('flaguser', $gp->resolveUser('some-host'));
	}

	public function testResolveUserFromEmbeddedHost(): void
	{
		$gp = $this->make([], ['gitpub.user' => ['output' => 'configuser']]);
		$this->assertSame('embed', $gp->resolveUser('embed@some-host'));
	}

	public function testResolveUserFromConfig(): void
	{
		$gp = $this->make([], ['gitpub.user' => ['output' => 'configuser']]);
		$this->assertSame('configuser', $gp->resolveUser('some-host'));
	}

	public function testResolveUserFallsBackToLocalUser(): void
	{
		$gp = $this->make([], ['gitpub.user' => ['exitCode' => 1]]);
		$this->assertSame('tester', $gp->resolveUser('some-host'));
	}

	// ---- Name resolution --------------------------------------------------

	public function testResolveNameDerivesKebabFromRepoRoot(): void
	{
		$gp = $this->make([], []);
		$this->assertSame('my-cool-repo', $gp->resolveName('/home/x/My Cool Repo'));
	}

	public function testResolveNamePositionalWinsAsIs(): void
	{
		// Explicit name is used verbatim — not re-kebabed.
		$gp = $this->make(['Keep_As.Is'], []);
		$this->assertSame('Keep_As.Is', $gp->resolveName('/home/x/My Cool Repo'));
	}

	// ---- Full publish flow ------------------------------------------------

	private function happyRules(array $overrides = []): array
	{
		return $overrides + [
			'rev-parse --show-toplevel' => ['output' => '/home/x/My Cool Repo'],
			'gitpub.host'  => ['output' => 'jt-host'],
			'gitpub.user'  => ['output' => 'jtsternberg'],
			'remote get-url origin'     => ['exitCode' => 1],   // no existing origin
			'rev-parse --verify'        => ['exitCode' => 0],   // has commits
			'test -d'                   => ['exitCode' => 1],   // remote path absent
		];
	}

	public function testHappyPathCreatesWiresAndPushesInOrder(): void
	{
		$gp   = $this->make(['-y'], $this->happyRules());
		$code = $gp->run();

		$this->assertSame(0, $code);
		$this->assertTrue($this->ran('git init --bare'), 'creates the bare repo');
		$this->assertTrue($this->ran('remote add origin'), 'wires origin');
		$this->assertTrue($this->ran('push -u origin --all'), 'pushes branches');
		$this->assertTrue($this->ran('push origin --tags'), 'pushes tags');

		// Order: create bare -> wire origin -> push branches -> push tags.
		$this->assertLessThan($this->orderOf('remote add origin'), $this->orderOf('git init --bare'));
		$this->assertLessThan($this->orderOf('push -u origin --all'), $this->orderOf('remote add origin'));
		$this->assertLessThan($this->orderOf('push origin --tags'), $this->orderOf('push -u origin --all'));
	}

	public function testHappyPathBuildsRemoteUrlWithConfiguredUser(): void
	{
		$gp = $this->make(['-y'], $this->happyRules());
		$gp->run();

		// User 'jtsternberg' != local 'tester', so it is included; name is kebabed.
		$this->assertTrue(
			$this->ran('jtsternberg@jt-host:git-remotes/my-cool-repo.git'),
			'origin URL uses configured user + kebab name'
		);
	}

	public function testUserOmittedFromUrlWhenItMatchesLocalDefault(): void
	{
		// gitpub.user resolves to the local user -> omit user@ from URL and ssh target.
		$gp = $this->make(['-y'], $this->happyRules(['gitpub.user' => ['output' => 'tester']]));
		$gp->run();

		$this->assertTrue($this->ran('jt-host:git-remotes/my-cool-repo.git'));
		$this->assertFalse($this->ran('tester@jt-host'), 'default user is not embedded');
	}

	public function testExistingOriginAbortsWithoutMutating(): void
	{
		$gp = $this->make(['-y'], $this->happyRules([
			'remote get-url origin' => ['exitCode' => 0, 'output' => 'git@github.com:foo/bar.git'],
		]));
		$code = $gp->run();

		$this->assertSame(1, $code);
		$this->assertFalse($this->ran('git init --bare'), 'never touches the host');
		$this->assertFalse($this->ran('remote add origin'), 'never repoints origin');
		$this->assertFalse($this->ran('push'), 'never pushes');
	}

	public function testMissingHostErrorsWithoutSsh(): void
	{
		$gp = $this->make(['-y'], $this->happyRules([
			'gitpub.host' => ['exitCode' => 1],
		]));
		$code = $gp->run();

		$this->assertSame(1, $code);
		$this->assertFalse($this->ran('ssh'), 'no network before a host is known');
		$this->assertFalse($this->ran('remote add origin'));
	}

	public function testNoCommitsWiresOriginButSkipsPush(): void
	{
		$gp = $this->make(['-y'], $this->happyRules([
			'rev-parse --verify' => ['exitCode' => 1],   // empty repo, no HEAD
		]));
		$code = $gp->run();

		$this->assertSame(0, $code, 'wiring origin on an empty repo is still success');
		$this->assertTrue($this->ran('remote add origin'), 'origin still wired');
		$this->assertFalse($this->ran('push'), 'nothing to push yet');
	}

	public function testNotAGitRepoErrors(): void
	{
		$gp = $this->make(['-y'], $this->happyRules([
			'rev-parse --show-toplevel' => ['exitCode' => 128, 'output' => ''],
		]));
		$code = $gp->run();

		$this->assertSame(1, $code);
		$this->assertFalse($this->ran('ssh'));
	}

	public function testExistingRemotePathIsReusedIdempotently(): void
	{
		// Remote bare repo already exists; with -y we reuse it and still init
		// (idempotent) then wire + push.
		$gp = $this->make(['-y'], $this->happyRules([
			'test -d' => ['exitCode' => 0],
		]));
		$code = $gp->run();

		$this->assertSame(0, $code);
		$this->assertTrue($this->ran('git init --bare'), 'init is safe/idempotent on reuse');
		$this->assertTrue($this->ran('remote add origin'));
	}
}
