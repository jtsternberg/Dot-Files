#!/usr/bin/env python3.7

import sys
import iterm2

try:
    key = sys.argv[1]
except IndexError:
    key = ''

try:
    command = sys.argv[2]
except IndexError:
    command = ''

async def main(connection):
    app = await iterm2.async_get_app(connection)
    window = app.current_terminal_window
    if window is not None:
        # Create a new tab
        new_tab = await window.async_create_tab()
        if new_tab is not None:
            session = new_tab.current_session
            # Change directory and execute command in the new tab
            if 'list' == key:
                await session.async_send_text(f"dirmap list\n")
            else:
                await session.async_send_text(f"cd \"`dirmap {key}`\" && clear\n")
                await session.async_send_text(f"{command}\n")
    else:
        print("No current window")

iterm2.run_until_complete(main)