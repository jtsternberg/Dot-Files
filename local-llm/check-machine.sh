#!/usr/bin/env bash
# =============================================================================
# check-machine.sh — verify the current machine matches the one the local-llm
# docs were written for.
#
# The findings in local-llm/CLAUDE.md (benchmarks, MLX behavior, model picks)
# are specific to a single machine. This computes a stable fingerprint of the
# host (OS + CPU + core count + RAM tier) and compares it to the expected hash.
#
# Usage:
#   bash local-llm/check-machine.sh          # human-readable status
#   bash local-llm/check-machine.sh --hash   # print current fingerprint only
#   bash local-llm/check-machine.sh --quiet  # no output; exit code only
#
# Exit codes: 0 = match, 1 = mismatch (different machine), 2 = detection error.
# To adopt a new machine as the reference, run with --hash and replace
# EXPECTED_HASH below (and update the specs in CLAUDE.md).
# =============================================================================

# Fingerprint of the machine these docs describe: Apple M2 Max, 12 cores, 64 GB.
EXPECTED_HASH="28b3a5cec701629e"
EXPECTED_DESC="Apple M2 Max · 12 cores · 64 GB (macOS)"

os="$(uname -s)"
cpu=""; cores=""; ram_gb=""

case "$os" in
  Darwin)
    cpu="$(sysctl -n machdep.cpu.brand_string 2>/dev/null)"
    cores="$(sysctl -n hw.ncpu 2>/dev/null)"
    mem_bytes="$(sysctl -n hw.memsize 2>/dev/null)"
    [ -n "$mem_bytes" ] && ram_gb=$(( mem_bytes / 1024 / 1024 / 1024 ))
    ;;
  Linux)
    cpu="$(awk -F': ' '/model name/{print $2; exit}' /proc/cpuinfo 2>/dev/null)"
    cores="$(nproc 2>/dev/null)"
    mem_kb="$(awk '/MemTotal/{print $2; exit}' /proc/meminfo 2>/dev/null)"
    [ -n "$mem_kb" ] && ram_gb=$(( mem_kb / 1024 / 1024 ))
    ;;
  *)
    [ "$1" = "--quiet" ] || echo "check-machine: unsupported OS '$os'" >&2
    exit 2
    ;;
esac

if [ -z "$cpu" ] || [ -z "$cores" ] || [ -z "$ram_gb" ]; then
  [ "$1" = "--quiet" ] || echo "check-machine: could not detect specs (os=$os cpu='$cpu' cores='$cores' ram='$ram_gb')" >&2
  exit 2
fi

# RAM tier (rounded down to nearest 8 GB) so trivial reporting differences don't
# trip a false mismatch on the same physical machine.
ram_tier=$(( ram_gb / 8 * 8 ))
fingerprint="${os}|${cpu}|${cores}|${ram_tier}GB"

if command -v shasum >/dev/null 2>&1; then
  hash="$(printf '%s' "$fingerprint" | shasum -a 256 | cut -c1-16)"
else
  hash="$(printf '%s' "$fingerprint" | sha256sum | cut -c1-16)"
fi

if [ "$1" = "--hash" ]; then
  echo "$hash  ($fingerprint)"
  exit 0
fi

if [ "$hash" = "$EXPECTED_HASH" ]; then
  [ "$1" = "--quiet" ] || echo "✅ machine matches local-llm docs: $EXPECTED_DESC"
  exit 0
else
  [ "$1" = "--quiet" ] || {
    echo "⚠️  MACHINE MISMATCH — local-llm/CLAUDE.md findings may NOT apply here." >&2
    echo "   Docs describe: $EXPECTED_DESC" >&2
    echo "   This machine:  $cpu · ${cores} cores · ${ram_gb} GB ($os)" >&2
    echo "   Benchmarks, MLX behavior, and model picks are machine-specific — re-verify before trusting them." >&2
  }
  exit 1
fi
