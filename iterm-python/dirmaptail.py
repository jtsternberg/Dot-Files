#!/usr/bin/env python3.7

import sys
import iterm2

try:
	sys.argv[1]
except IndexError:
	key = ''
else:
	key = sys.argv[1]


# print('Number of arguments: ', len(sys.argv))
# print( 'Argument List:', str(sys.argv))
# print( 'key:', key)
# print( 'directory:', directory)
# except IndexError:
# try:
# 	print( 'sys.argv[1]:', str(sys.argv[1]))
# except IndexError:

# getopt.getopt(sys.argv, options, [long_options])

async def main(connection):
	app = await iterm2.async_get_app(connection)
	window = app.current_terminal_window
	if window is not None:
		if 'list' == key:
			await window.current_tab.current_session.async_send_text(f"dirmap list\n")
		else:
			await window.current_tab.current_session.async_send_text(f"cd \"`dirmap {key}`\" && clear\n")
			await window.current_tab.current_session.async_send_text(f"tailtrim\n")
		# window.current_tab.current_session
		# newtab = await window.async_create_tab()
		# if newtab is not None:
		# 	for session in newtab.sessions:
		# 		await session.async_send_text(f"cd {directory} && clear\n")
	else:
		# You can view this message in the script console.
		print('No current window')

iterm2.run_until_complete(main)
