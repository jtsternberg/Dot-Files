# Completions for CLI commands maintained in this dotfiles repository.

_linux_catchup_lazy() {
	local generated

	generated="$(command linux-catchup completion zsh 2>/dev/null)" || {
		_message 'unable to generate linux-catchup completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load linux-catchup completion'
		return 1
	}

	if (( ! $+functions[_linux_catchup] )); then
		_message 'linux-catchup completion did not define _linux_catchup'
		return 1
	fi

	_linux_catchup "$@"
}

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

_cmux_bak_lazy() {
	local generated

	generated="$(command cmux-bak completion zsh 2>/dev/null)" || {
		_message 'unable to generate cmux-bak completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load cmux-bak completion'
		return 1
	}

	if (( ! $+functions[_cmux_bak] )); then
		_message 'cmux-bak completion did not define _cmux_bak'
		return 1
	fi

	_cmux_bak "$@"
}

_md_atx_lazy() {
	local generated

	generated="$(command md-atx completion zsh 2>/dev/null)" || {
		_message 'unable to generate md-atx completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load md-atx completion'
		return 1
	}

	if (( ! $+functions[_md_atx] )); then
		_message 'md-atx completion did not define _md_atx'
		return 1
	fi

	_md_atx "$@"
}

_xname_lazy() {
	local generated

	generated="$(command xname completion zsh 2>/dev/null)" || {
		_message 'unable to generate xname completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load xname completion'
		return 1
	}

	if (( ! $+functions[_xname] )); then
		_message 'xname completion did not define _xname'
		return 1
	fi

	_xname "$@"
}

_aimodels_lazy() {
	local generated

	generated="$(command aimodels completion zsh 2>/dev/null)" || {
		_message 'unable to generate aimodels completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load aimodels completion'
		return 1
	}

	if (( ! $+functions[_aimodels] )); then
		_message 'aimodels completion did not define _aimodels'
		return 1
	fi

	_aimodels "$@"
}

# herdr (https://herdr.dev) is a third-party tool, not maintained in this repo,
# but its completion follows the same generate-on-first-use contract as our own
# commands, so its lazy loader lives here rather than in a new plugin. It is a
# no-op in shells where herdr isn't installed: the generator call fails quietly
# and _message explains on the rare occasion someone tabs `herdr ` without it.
_herdr_lazy() {
	local generated

	generated="$(command herdr completion zsh 2>/dev/null)" || {
		_message 'unable to generate herdr completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load herdr completion'
		return 1
	}

	if (( ! $+functions[_herdr] )); then
		_message 'herdr completion did not define _herdr'
		return 1
	fi

	_herdr "$@"
}

compdef _graveyard graveyard
compdef _cmux_bak_lazy cmux-bak
compdef _linux_catchup_lazy linux-catchup
compdef _md_atx_lazy md-atx
compdef _xname_lazy xname
compdef _aimodels_lazy aimodels
compdef _herdr_lazy herdr
