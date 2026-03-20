# QNAP QTS Utilities

Scripts for making a QNAP NAS more developer-friendly.

## Install Git

QTS doesn't ship with git and there's no QPKG for it. These scripts compile git
from source inside a disposable Docker container (via Container Station) and
install the binaries to `/share/Public/toolchain/` on the NAS.

Based on [sdhuang32's approach](https://sdhuang32.github.io/install-git-on-qts/),
but hardened:

- Uses official `centos:7` image (no custom/untrusted images)
- No `--privileged` flag
- Configurable git version
- Proper cleanup on failure
- Process-ID-scoped container names (safe to run concurrently)

### Prerequisites

- Container Station installed via AppCenter (provides `system-docker`)

### Usage

Copy both scripts to the NAS and run:

```bash
# Default version (2.52.0)
sudo ./install-git-on-qts.sh

# Specific version
sudo ./install-git-on-qts.sh 2.53.0
```

Then add the toolchain to your PATH in `~/.bashrc`:

```bash
export PATH="/share/Public/toolchain/bin:$PATH"
```

### What it does

1. Starts a disposable `centos:7` container with `/share/Public/toolchain`
   bind-mounted
2. Installs build deps (`gcc`, `make`, `openssl-devel`, etc.) inside the
   container
3. Downloads and compiles git from source (from kernel.org)
4. Installs to the bind mount so binaries land on the host
5. Removes the container

No containers run after installation — git runs natively on QTS.

### SSH git operations (clone/push/pull)

Git is compiled inside the container with `--prefix=/opt/toolchain` (the
container's mount point), so its hardcoded `exec-path` is
`/opt/toolchain/libexec/git-core/`. On the host, those files actually live at
`/share/Public/toolchain/libexec/git-core/`.

Without a symlink bridging these paths, remote git operations over SSH fail:

```
error: cannot run pack-objects: No such file or directory
fatal: git upload-pack: unable to fork git-pack-objects
```

The install script creates this symlink automatically:

```
/opt/toolchain/libexec/git-core -> /share/Public/toolchain/libexec/git-core
```

If you hit this error after a manual install or QTS update that wiped the
symlink, recreate it:

```bash
sudo mkdir -p /opt/toolchain/libexec
sudo ln -s /share/Public/toolchain/libexec/git-core /opt/toolchain/libexec/git-core
```

You'll also want `git-upload-pack` and `git-receive-pack` on the default PATH
so SSH can find them:

```bash
sudo ln -s /share/Public/toolchain/bin/git-upload-pack /usr/bin/git-upload-pack
sudo ln -s /share/Public/toolchain/bin/git-receive-pack /usr/bin/git-receive-pack
```

### Upgrading

Just run the script again with a newer version number. The new build overwrites
the previous one in `/share/Public/toolchain/`.
