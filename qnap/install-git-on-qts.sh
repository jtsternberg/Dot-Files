#!/bin/bash
# =============================================================================
# Install git on QNAP QTS by compiling inside a disposable container.
#
# Uses an official CentOS 7 image (no custom/untrusted images), runs without
# --privileged, and cleans up after itself. The compiled binaries land in
# /share/Public/toolchain on the host via a bind mount.
#
# Inspired by https://sdhuang32.github.io/install-git-on-qts/ but hardened:
#   - Official base image only (centos:7)
#   - No --privileged flag
#   - Configurable git version
#   - Proper error handling
#
# Usage:
#   ./install-git-on-qts.sh [GIT_VERSION]
#
# Example:
#   ./install-git-on-qts.sh          # builds default version
#   ./install-git-on-qts.sh 2.53.0   # builds specific version
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_SCRIPT="build-git.sh"
CONTAINER_NAME="git-builder-$$"
TOOLCHAIN_DIR="/share/Public/toolchain"
DOCKER_CMD="system-docker"
GIT_VERSION="${1:-2.45.4}"

# Fall back to docker if system-docker isn't available
if ! command -v "$DOCKER_CMD" &>/dev/null; then
	DOCKER_CMD="docker"
fi

echo "==> Building git v${GIT_VERSION} for QTS"
echo "    Container: ${CONTAINER_NAME}"
echo "    Install to: ${TOOLCHAIN_DIR}"
echo ""

# Ensure toolchain directory exists
mkdir -p "${TOOLCHAIN_DIR}"

# Cleanup on exit (success or failure)
cleanup() {
	echo "==> Cleaning up build container..."
	"$DOCKER_CMD" rm -f "${CONTAINER_NAME}" 2>/dev/null || true
}
trap cleanup EXIT

# Start a disposable CentOS 7 container with the toolchain dir mounted.
# No --privileged needed — we're just compiling.
echo "==> Starting build container..."
"$DOCKER_CMD" run \
	--name "${CONTAINER_NAME}" \
	-v "${TOOLCHAIN_DIR}:/opt/toolchain" \
	-e "GIT_VERSION=${GIT_VERSION}" \
	-d centos:7 \
	sleep 3600

# Copy build script in and run it
echo "==> Running build script inside container..."
"$DOCKER_CMD" cp "${SCRIPT_DIR}/${BUILD_SCRIPT}" "${CONTAINER_NAME}:/root/${BUILD_SCRIPT}"
"$DOCKER_CMD" exec -t "${CONTAINER_NAME}" bash "/root/${BUILD_SCRIPT}"

# Git was compiled with --prefix=/opt/toolchain (the container's mount point),
# so its hardcoded exec-path is /opt/toolchain/libexec/git-core/. On the host
# the files actually live at /share/Public/toolchain/libexec/git-core/.
# Without this symlink, SSH-based git operations (clone, push, pull) fail with
# "cannot run pack-objects: No such file or directory".
EXEC_PATH="/opt/toolchain/libexec/git-core"
if [ ! -e "${EXEC_PATH}" ]; then
	echo "==> Creating exec-path symlink: ${EXEC_PATH} -> ${TOOLCHAIN_DIR}/libexec/git-core"
	mkdir -p "$(dirname "${EXEC_PATH}")"
	ln -s "${TOOLCHAIN_DIR}/libexec/git-core" "${EXEC_PATH}"
fi

# Verify the binary works on the host
if "${TOOLCHAIN_DIR}/bin/git" --version &>/dev/null; then
	echo ""
	echo "==> Success! $("${TOOLCHAIN_DIR}/bin/git" --version)"
	echo "    Installed to: ${TOOLCHAIN_DIR}/bin/git"
	echo ""
	echo "    Add this to your .bashrc on the NAS:"
	echo "    export PATH=\"${TOOLCHAIN_DIR}/bin:\$PATH\""
else
	echo ""
	echo "==> ERROR: git binary was built but doesn't run on QTS."
	echo "    This may be a glibc compatibility issue."
	exit 1
fi
