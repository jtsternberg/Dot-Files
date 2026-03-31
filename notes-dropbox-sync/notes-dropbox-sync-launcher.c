/*
 * Native launcher for notes-dropbox-sync.
 *
 * macOS Full Disk Access is granted per-binary. Shell scripts are interpreted
 * by bash, so FDA on the script path doesn't propagate to child processes
 * like rsync. This tiny Mach-O binary exists solely so we can grant FDA to
 * it, and it execs the real script — FDA follows the process tree.
 *
 * Build (one-time, both architectures):
 *   cc -O2 -o launcher launcher.c
 *
 * Then grant FDA to the compiled `launcher` binary in:
 *   System Settings > Privacy & Security > Full Disk Access
 */

#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>

int main(void) {
	const char *home = getenv("HOME");
	if (!home) {
		fprintf(stderr, "notes-dropbox-sync launcher: $HOME not set\n");
		return 1;
	}

	char script[1024];
	snprintf(script, sizeof(script), "%s/.dotfiles/bin/notes-dropbox-sync", home);

	char *argv[] = { script, NULL };
	char path_env[512];
	snprintf(path_env, sizeof(path_env),
		"PATH=/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin");

	char *envp[] = { path_env, NULL };

	/* Preserve HOME so the script can find Dropbox, Notes, etc. */
	char home_env[512];
	snprintf(home_env, sizeof(home_env), "HOME=%s", home);
	char *full_envp[] = { path_env, home_env, NULL };

	execve("/bin/bash", (char *[]){ "bash", script, NULL }, full_envp);

	perror("execve failed");
	return 1;
}
