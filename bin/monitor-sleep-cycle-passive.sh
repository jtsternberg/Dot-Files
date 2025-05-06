#!/bin/bash
timestamp=$(date +"%Y-%m-%d_%H%M")
logfile=~/sleep-cycle-monitor-$timestamp.log

echo "🔍 Passive Sleep Cycle Monitor started at $timestamp" > "$logfile"
echo "Waiting for wake events. This won't prevent sleep..." >> "$logfile"

# Function to log current assertions when a wake is detected
log_assertions() {
  echo -e "\n🕑 [$(date +"%Y-%m-%d %H:%M:%S")] Wake Detected:" >> "$logfile"
  echo "🛑 Assertions:" >> "$logfile"
  pmset -g assertions >> "$logfile" 2>&1

  echo -e "\n🧾 pmset Sleep/Wake Summary:" >> "$logfile"
  pmset -g log | grep -E "Sleep|Wake" | tail -n 20 >> "$logfile" 2>&1
}

# Use log stream to passively listen for wake events
log stream --predicate 'eventMessage contains "Wake reason"' --style syslog --info \
| while read -r line; do
    echo -e "\n⚠️  [$(date +"%Y-%m-%d %H:%M:%S")] Wake reason event:" >> "$logfile"
    echo "$line" >> "$logfile"
    log_assertions
done