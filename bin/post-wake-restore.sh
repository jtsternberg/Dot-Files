#!/bin/bash
# === Post-wake restore: re-enables services if needed ===

echo "🔄 Restoring system services after wake..."

echo "🔌 Re-enabling Bluetooth..."
sudo defaults write /Library/Preferences/com.apple.Bluetooth ControllerPowerState -int 1
sudo pkill bluetoothd

echo "📱 Reloading Handoff/sharingd..."
launchctl load -w /System/Library/LaunchAgents/com.apple.sharingd.plist 2>/dev/null

echo "👥 Re-enabling AddressBook Source Sync..."
launchctl load -w /System/Library/LaunchAgents/com.apple.AddressBook.SourceSync.plist 2>/dev/null

echo "💽 Re-enabling Time Machine..."
sudo tmutil enable

echo "🔧 Restoring TCPKeepAlive, standby, and autopoweroff..."
sudo pmset -b tcpkeepalive 1
sudo pmset -b standby 1
sudo pmset -b autopoweroff 1

echo "✅ All services restored."