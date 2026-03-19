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

### Upgrading

Just run the script again with a newer version number. The new build overwrites
the previous one in `/share/Public/toolchain/`.
