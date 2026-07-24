<?php
namespace JT;

use JT\CLI\Helpers;

/**
 * gitpub — "publish to your own host instead of GitHub".
 *
 * Publishes the current git working tree to a bare repo on one of JT's own
 * tailnet hosts (self-hosted git remote over SSH/Tailscale), wires up `origin`,
 * and pushes all branches + tags. One command, run inside a repo:
 *
 *   ssh <host> 'git init --bare -- git-remotes/<name>.git'
 *   git remote add origin <host>:git-remotes/<name>.git
 *   git push -u origin --all && git push origin --tags
 *
 * gitpub wraps exactly that with sane defaults, safety prompts, and no
 * per-machine memorization of hostnames or paths.
 *
 * Config is read via `git config` from the `[gitpub]` section (JT keeps it in
 * ~/.dotfiles/private/additional_config, included by .gitconfig and NOT synced,
 * so the internal hostname stays out of the public dotfiles repo):
 *
 *   [gitpub]
 *       host = my-tailnet-host  ; required (or pass --host)
 *       user = jtsternberg      ; optional (see resolveUser)
 *
 * All bare repos land in `git-remotes/` (relative to the remote user's home);
 * that base dir is fixed, not configurable.
 *
 * Testability: every shelling call (git + ssh) goes through an injected runner
 * so the suite can drive the whole flow without a network or a real repo,
 * mirroring the stub-binary seam used for Godo::resolvePath.
 */
class GitPub {

	/** Fixed base directory (relative to the remote home) for all bare repos. */
	const BASE_DIR = 'git-remotes';

	protected Helpers $cli;

	/**
	 * Shelling seam. Given a full command string, returns
	 * ['exitCode' => int, 'output' => string, 'error' => string].
	 *
	 * @var callable(string):array
	 */
	protected $runner;

	/**
	 * @param callable(string):array|null $runner Injected for tests; defaults to
	 *        the CLI helper's proc_open-based runner.
	 */
	public function __construct( Helpers $cli, ?callable $runner = null ) {
		$this->cli    = $cli;
		$this->runner = $runner ?: function ( string $cmd ) use ( $cli ) {
			return $cli->getCommandOutputAndExitCode( $cmd );
		};
	}

	// -- Pure units ---------------------------------------------------------

	/**
	 * Lowercased-kebab of an arbitrary string: lowercase, collapse every run of
	 * non-[a-z0-9] into a single '-', trim leading/trailing '-'.
	 */
	public function kebabName( string $raw ): string {
		$s = strtolower( $raw );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );

		return trim( $s, '-' );
	}

	/**
	 * scp-style, home-relative remote URL: <user>@<host>:git-remotes/<name>.git.
	 * A null/empty $user omits the `user@` prefix (ssh then uses its default
	 * user). Any `user@` already embedded in $host is stripped so the passed
	 * $user is authoritative.
	 */
	public function buildRemoteUrl( string $host, ?string $user, string $name ): string {
		$host   = $this->stripUser( $host );
		$prefix = ( null !== $user && '' !== $user ) ? $user . '@' : '';

		return $prefix . $host . ':' . self::BASE_DIR . '/' . $name . '.git';
	}

	// -- Resolution ---------------------------------------------------------

	/** Target host: --host flag, else gitpub.host config, else null. */
	public function resolveHost(): ?string {
		$flag = $this->flag( 'host' );
		if ( null !== $flag ) {
			return $flag;
		}

		$cfg = $this->gitConfig( 'gitpub.host' );

		return '' !== $cfg ? $cfg : null;
	}

	/**
	 * Effective user, in precedence order: --user flag -> user@ embedded in the
	 * host value -> gitpub.user config -> local $USER. Always returns a string;
	 * whether it ends up in the URL is decided by the caller (see effectiveUser).
	 */
	public function resolveUser( string $host ): string {
		$flag = $this->flag( 'user' );
		if ( null !== $flag ) {
			return $flag;
		}

		$embedded = $this->userFromHost( $host );
		if ( '' !== $embedded ) {
			return $embedded;
		}

		$cfg = $this->gitConfig( 'gitpub.user' );
		if ( '' !== $cfg ) {
			return $cfg;
		}

		return $this->localUser();
	}

	/**
	 * Remote repo name: the positional argument used verbatim (JT's explicit
	 * choice wins, not re-kebabed), else the kebab of the repo root dir name.
	 */
	public function resolveName( string $repoRoot ): string {
		$positional = $this->cli->getArg( 1 );
		if ( null !== $positional && '' !== trim( (string) $positional ) ) {
			return trim( (string) $positional );
		}

		return $this->kebabName( basename( rtrim( $repoRoot, '/' ) ) );
	}

	// -- Flow ---------------------------------------------------------------

	/**
	 * Run the publish flow. Returns a process exit code (0 = success, 1 = error
	 * or user-aborted). Never calls exit() so it stays unit-testable; the bin
	 * entry does `exit( $gitpub->run() )`.
	 */
	public function run(): int {
		// 1. Must be inside a working tree.
		$root = $this->capture( 'git rev-parse --show-toplevel' );
		if ( '' === $root ) {
			$this->cli->err( "\nNot inside a git working tree. cd into a repo and try again." );

			return 1;
		}

		// 2. Host must resolve.
		$host = $this->resolveHost();
		if ( null === $host ) {
			$this->cli->err( "\nNo target host. Set one with --host=<host>, or add it to git config:" );
			$this->cli->msg( sprintf(
				"  %s[gitpub]\n      host = <your-tailnet-host>%s\n  (JT keeps this in ~/.dotfiles/private/additional_config)",
				$this->cli->color( 'cyan' ),
				$this->cli->color( 'none' )
			) );

			return 1;
		}

		// 3. Never silently repoint an existing origin.
		$existing = $this->existingOrigin();
		if ( null !== $existing ) {
			$this->cli->err( sprintf( "\nThis repo already has an 'origin' remote:\n  %s", $existing ) );
			$this->cli->msg( "gitpub won't repoint it. Either pass a different [name], or remove it first:" );
			$this->cli->msg( sprintf( "  %sgit remote remove origin%s", $this->cli->color( 'cyan' ), $this->cli->color( 'none' ) ) );

			return 1;
		}

		$cleanHost = $this->stripUser( $host );
		$user      = $this->resolveUser( $host );
		$urlUser   = $this->effectiveUser( $user );
		$name      = $this->resolveName( $root );
		$url       = $this->buildRemoteUrl( $cleanHost, $urlUser, $name );
		$sshTarget = ( null !== $urlUser ) ? $urlUser . '@' . $cleanHost : $cleanHost;
		$hasCommits = $this->hasCommits();

		// 4. Summary + confirm (skipped with -y).
		$this->cli->msg( sprintf( "\n%sPublishing this repo to your own host:%s", $this->cli->color( 'green' ), $this->cli->color( 'none' ) ) );
		$this->cli->msg( sprintf( "  repo    %s", $root ) );
		$this->cli->msg( sprintf( "  remote  %s", $url ) );
		if ( ! $hasCommits ) {
			$this->cli->msg( "  note    repo has no commits yet — origin will be wired but nothing pushed", 'yellow' );
		}

		if ( ! $this->cli->confirm( "\nCreate the bare repo, wire 'origin', and push?" ) ) {
			$this->cli->msg( "Aborted." );

			return 1;
		}

		// 5. Create the bare repo on the host (idempotent). Guard against
		//    accidentally attaching to an unrelated existing repo.
		$remotePath = self::BASE_DIR . '/' . $name . '.git';
		if ( $this->sshExec( $sshTarget, 'test -d ' . escapeshellarg( $remotePath ) )['exitCode'] === 0 ) {
			if ( ! $this->cli->confirm( sprintf( "\n%s already exists on %s. Reuse it?", $remotePath, $cleanHost ) ) ) {
				$this->cli->msg( "Aborted." );

				return 1;
			}
		}

		$init = $this->sshExec( $sshTarget, 'git init --bare -- ' . escapeshellarg( $remotePath ) );
		if ( 0 !== $init['exitCode'] ) {
			$this->cli->err( sprintf( "\nFailed to create the bare repo on %s:\n  %s", $cleanHost, $init['error'] ?: $init['output'] ) );

			return 1;
		}

		// 6. Wire origin.
		$add = $this->run_( 'git remote add origin ' . escapeshellarg( $url ) );
		if ( 0 !== $add['exitCode'] ) {
			$this->cli->err( sprintf( "\nFailed to add origin:\n  %s", $add['error'] ?: $add['output'] ) );

			return 1;
		}

		// 7. Push (skipped for an empty repo — origin is still validly wired).
		if ( $hasCommits ) {
			$push = $this->run_( 'git push -u origin --all' );
			if ( 0 !== $push['exitCode'] ) {
				$this->cli->err( sprintf( "\norigin is set, but pushing branches failed:\n  %s", $push['error'] ?: $push['output'] ) );

				return 1;
			}

			$tags = $this->run_( 'git push origin --tags' );
			if ( 0 !== $tags['exitCode'] ) {
				$this->cli->msg( sprintf( "\nBranches pushed, but pushing tags failed:\n  %s", $tags['error'] ?: $tags['output'] ), 'yellow' );
			}
		}

		// 8. Report.
		$this->cli->successMsg( sprintf( "\nPublished. origin -> %s", $url ) );
		$this->cli->msg( sprintf( "Clone elsewhere with:\n  %sgit clone %s%s", $this->cli->color( 'cyan' ), $url, $this->cli->color( 'none' ) ) );

		return 0;
	}

	// -- Git/SSH probes (through the runner) --------------------------------

	/** A `git config --get <key>` value, or '' when unset. */
	public function gitConfig( string $key ): string {
		return $this->capture( 'git config --get ' . escapeshellarg( $key ) );
	}

	/** The current `origin` remote URL, or null when there is none. */
	public function existingOrigin(): ?string {
		$res = $this->run_( 'git remote get-url origin' );
		if ( 0 !== $res['exitCode'] ) {
			return null;
		}

		$url = trim( $res['output'] );

		return '' !== $url ? $url : null;
	}

	/** Whether the repo has at least one commit (a resolvable HEAD). */
	public function hasCommits(): bool {
		return 0 === $this->run_( 'git rev-parse --verify --quiet HEAD' )['exitCode'];
	}

	// -- Internals ----------------------------------------------------------

	/** Run an arbitrary remote command over ssh (double-quoted correctly). */
	protected function sshExec( string $target, string $remoteCmd ): array {
		return $this->run_( 'ssh ' . escapeshellarg( $target ) . ' ' . escapeshellarg( $remoteCmd ) );
	}

	/** Invoke the injected runner. */
	protected function run_( string $cmd ): array {
		$res = ( $this->runner )( $cmd );

		return $res + [ 'exitCode' => 0, 'output' => '', 'error' => '' ];
	}

	/** Trimmed stdout of a command, or '' if it failed. */
	protected function capture( string $cmd ): string {
		$res = $this->run_( $cmd );

		return 0 === $res['exitCode'] ? trim( $res['output'] ) : '';
	}

	/**
	 * The user to actually render into URLs/ssh targets: null (omit) when it is
	 * empty or equals the local default user, otherwise the user itself.
	 */
	protected function effectiveUser( string $user ): ?string {
		if ( '' === $user || $user === $this->localUser() ) {
			return null;
		}

		return $user;
	}

	/** A `--flag` value, or null when the flag is absent or empty. */
	protected function flag( string $name ): ?string {
		if ( ! $this->cli->hasFlag( $name ) ) {
			return null;
		}

		$val = trim( (string) $this->cli->getFlag( $name ) );

		return '' !== $val ? $val : null;
	}

	/** The `user` from a `user@host` value, or '' when there is none. */
	protected function userFromHost( string $host ): string {
		return ( false !== strpos( $host, '@' ) ) ? substr( $host, 0, strpos( $host, '@' ) ) : '';
	}

	/** Strip a leading `user@` from a host value. */
	protected function stripUser( string $host ): string {
		$at = strpos( $host, '@' );

		return false !== $at ? substr( $host, $at + 1 ) : $host;
	}

	/** The local login user (for the omit-when-default rule). */
	protected function localUser(): string {
		return (string) ( getenv( 'USER' ) ?: getenv( 'LOGNAME' ) ?: '' );
	}
}
