#!/bin/bash
# =============================================================================
# Sleep Diagnostics Logger
# By Justin Sternberg
#
# Version 1.0.0
#
# Generates a diagnostic log file containing information about processes and events
# that may be preventing your Mac from sleeping properly. The log includes:
# - Current power management assertions (processes blocking sleep)
# - Wake reasons from the last hour
# - Recent sleep/wake events from pmset
#
# From ChatGPT conversation, `MacBook Battery Drain Diagnosis`
#
# The log file is saved with a timestamp in your home directory:
# ~/sleep-diagnostic-YYYY-MM-DD_HHMM.log
#
# Usage:
# log-sleep-offenders.sh
#
# Example output file:
# ~/sleep-diagnostic-2024-03-21_1423.log
# =============================================================================

# Create a timestamped log file
timestamp=$(date +"%Y-%m-%d_%H%M")
logfile=~/sleep-diagnostic-$timestamp.log

echo "🕵️‍♂️ Sleep Diagnostics - $timestamp" > "$logfile"
echo "===============================" >> "$logfile"

echo -e "\n🛑 pmset assertions (processes blocking sleep):\n" >> "$logfile"
pmset -g assertions >> "$logfile" 2>&1

echo -e "\n⚠️  Wake reasons (last 1 hour):\n" >> "$logfile"
log show --style syslog --predicate 'eventMessage contains "Wake reason"' --last 1h >> "$logfile" 2>&1

echo -e "\n🧾 Recent pmset sleep/wake events:\n" >> "$logfile"
pmset -g log | grep -E "Sleep|Wake" | tail -n 100 >> "$logfile" 2>&1

echo -e "\n✅ Log written to: $logfile\n"