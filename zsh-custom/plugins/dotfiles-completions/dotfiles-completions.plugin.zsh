# Completions for CLI commands maintained in this dotfiles repository.

_graveyard() {
	local context state state_descr line
	local -a commands

	commands=(
		'bury:Bury one or more live sessions'
		'candidates:List live agent sessions'
		'peek:Preview a live session'
		'ls:List buried sessions'
		'search:Search buried sessions'
		'page:Open or manage the graveyard overview server'
		'serve:Start or manage the overview server without opening it'
		'show:Open a buried transcript in the editor'
		'rename:Rename a buried session or workspace'
		'delete:Permanently delete a buried session or workspace'
		'resurrect:Resume a buried session or workspace'
		'repair:Repair stored session configuration'
	)

	_arguments -C \
		'(-h --help)'{-h,--help}'[display help]' \
		'1:command:->command' \
		'*::argument:->arguments'

	case $state in
		command)
			_describe -t commands 'graveyard command' commands
			;;
		arguments)
			local subcommand=$words[2]
			words=($words[2,-1])
			(( CURRENT-- ))

			case $subcommand in
				bury)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--idle[bury sessions idle longer than a duration]:duration (e.g. 2d, 48h, 90m):' \
						'(-ws --workspace)'{-ws,--workspace}'[bury every session in a workspace]:workspace name, ref, or group id:' \
						'--group[add one session to an existing buried group]:group id:' \
						'--force[bury even when a session looks busy or is untargetable]' \
						'-y[skip confirmations]' \
						'*:session id, surface ref, or title:'
					;;
				candidates)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--porcelain[emit tab-separated output]' \
						'--json[emit structured JSON]'
					;;
				peek)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--turns=[number of recent turns to show]:turn count:' \
						'1:live session id:'
					;;
				ls)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--json[emit structured JSON]'
					;;
				search)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--full-text[also search rendered transcripts]' \
						'--json[emit structured JSON]' \
						'1:search term:'
					;;
				page)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--no-open[start the server without opening a browser]' \
						'--port=[server port]:port:(8787)' \
						'--host=[loopback hostname]:hostname:(graveyard.localhost)' \
						'--stop[stop the running server]'
					;;
				serve)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--port=[server port]:port:(8787)' \
						'--host=[loopback hostname]:hostname:(graveyard.localhost)' \
						'--stop[stop the running server]'
					;;
				show)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'1:buried session id or name:'
					;;
				rename)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'(-ws --workspace)'{-ws,--workspace}'[rename a buried workspace]:workspace group:' \
						'1:buried session id or workspace group:' \
						'2:new name:'
					;;
				delete)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'(-ws --workspace)'{-ws,--workspace}'[delete a buried workspace]:workspace group:' \
						'-y[skip confirmation]' \
						'1:buried session id or workspace group:'
					;;
				resurrect)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'(-ws --workspace)'{-ws,--workspace}'[resurrect a buried workspace]:workspace group:' \
						'--from-transcript[restart from the archived transcript instead of resuming]' \
						'1:buried session id or name:'
					;;
				repair)
					_arguments \
						'(-h --help)'{-h,--help}'[display help]' \
						'--apply[write repairs instead of showing a dry run]'
					;;
			esac
			;;
	esac
}

_cmux_bak() {
	_arguments -s \
		'(-h --help)'{-h,--help}'[display help]' \
		'1:command:(audit)' \
		'--restore[restore missing workspaces and sessions from the backup]' \
		'--file=[use a specific backup file]:backup file:_files' \
		'--dry-run[show what would be done without making changes]' \
		'--verbose[show detailed progress]'
}

compdef _graveyard graveyard
compdef _cmux_bak cmux-bak
