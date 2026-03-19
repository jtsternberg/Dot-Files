#!/bin/bash
# =============================================================================
# Build git from source inside a container.
# This script runs INSIDE the build container, not on QTS directly.
# =============================================================================

set -euo pipefail

GIT_VERSION="${GIT_VERSION:-2.45.4}"
PREFIX="/opt/toolchain"

echo "==> Pointing yum at vault (CentOS 7 is EOL)..."
sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/CentOS-Base.repo
sed -i 's|^#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-Base.repo

echo "==> Installing build dependencies..."
yum install -y gcc wget make \
	curl-devel expat-devel gettext-devel \
	openssl-devel perl-devel zlib-devel

echo "==> Downloading git v${GIT_VERSION}..."
cd /tmp
wget -q "https://mirrors.edge.kernel.org/pub/software/scm/git/git-${GIT_VERSION}.tar.gz"
tar zxf "git-${GIT_VERSION}.tar.gz"

echo "==> Compiling (this takes a few minutes)..."
cd "git-${GIT_VERSION}"
./configure --prefix="${PREFIX}"
make -j"$(nproc)" all
make install

echo "==> Installed git $(${PREFIX}/bin/git --version) to ${PREFIX}"
