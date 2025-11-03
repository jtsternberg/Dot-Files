#!/usr/bin/env python3.7
"""
iTerm2 Script: Directory Map Command

This script integrates with iTerm2's Python API to interact with the 'dirmap' command.
It allows you to:
- List all mapped directories (when key is 'list')
- Navigate to a mapped directory and optionally run a command

Usage:
   dirmapcommand.py [key] [command]

Arguments:
   key      Optional. Directory map key to look up. If 'list', displays all mappings.
            If omitted or empty, the command will still be executed in current directory.
   command  Optional. Command to execute after navigating to the mapped directory.

Examples:
   dirmapcommand.py list
      Lists all directory mappings

   dirmapcommand.py work
      Navigates to the directory mapped to 'work' and clears the screen

   dirmapcommand.py work 'npm start'
      Navigates to the directory mapped to 'work', clears the screen, and runs 'npm start'
"""

import sys
import iterm2

# Parse command-line arguments
# First argument (index 1) is the directory map key
try:
	sys.argv[1]
except IndexError:
	key = ''
else:
	key = sys.argv[1]

# Second argument (index 2) is the optional command to run after navigation
try:
	sys.argv[2]
except IndexError:
	command = ''
else:
	command = sys.argv[2]


async def main(connection):
	"""
	Main async function that interacts with iTerm2.

	Args:
		connection: iTerm2 connection object
	"""
	app = await iterm2.async_get_app(connection)
	window = app.current_terminal_window

	if window is not None:
		if 'list' == key:
			# List mode: show all directory mappings
			await window.current_tab.current_session.async_send_text(f"dirmap list\n")
		else:
			# Navigate mode: change to mapped directory, clear screen, and optionally run command
			# Uses command substitution to get the directory path from dirmap
			await window.current_tab.current_session.async_send_text(f"cd \"`dirmap {key}`\" && clear\n")

			# If a command was provided, execute it
			if command:
				await window.current_tab.current_session.async_send_text(f"{command}\n")
	else:
		# You can view this message in the script console.
		print('No current window')

iterm2.run_until_complete(main)
