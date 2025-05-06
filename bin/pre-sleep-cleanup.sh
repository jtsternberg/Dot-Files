#!/bin/bash
# === Pre-sleep cleanup: disables known sleep offenders ===

timestamp=$(date +"%Y-%m-%d_%H%M")
logfile=~/sleep-diagnostic-$timestamp.log

echo "🛑 Running pre-sleep cleanup at $timestamp" > "$logfile"

echo "🔌 Disabling Bluetooth..."
sudo defaults write /Library/Preferences/com.apple.Bluetooth ControllerPowerState -int 0
sudo pkill bluetoothd

echo "📱 Unloading Handoff/sharingd..."
launchctl unload -w /System/Library/LaunchAgents/com.apple.sharingd.plist 2>/dev/null

echo "👥 Disabling AddressBook Source Sync..."
launchctl unload -w /System/Library/LaunchAgents/com.apple.AddressBook.SourceSync.plist 2>/dev/null

echo "💽 Disabling Time Machine (if enabled)..."
sudo tmutil disable

echo "🧪 Disabling TCPKeepAlive, standby, and autopoweroff..."
sudo pmset -b tcpkeepalive 0
sudo pmset -b standby 0
sudo pmset -b autopoweroff 0

echo -e "\n🛠️ Logging current pmset assertions..." >> "$logfile"
pmset -g assertions >> "$logfile" 2>&1

echo -e "\n📄 Logging last hour of wake events..." >> "$logfile"
log show --style syslog --predicate 'eventMessage contains "Wake reason"' --last 1h >> "$logfile" 2>&1

echo -e "\n✅ Cleanup done. Lid may now be closed. Log saved to $logfile"