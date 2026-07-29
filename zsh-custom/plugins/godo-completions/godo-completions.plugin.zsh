# BEGIN GENERATED COMPLETION: godo
#compdef godo
# Generated from PHP command attributes. Do not edit by hand.

_godo() {
	local -a commands
	local -a completion_values_1
	local -a completion_values_2

	commands=(
		'get:Print the stored commands for a key.'
		'addcmd:Append a command to a key without adding duplicates.'
		'setcmd:Replace a key'\''s command list with one command.'
		'rmcmd:Remove one command from a key, or all commands when omitted.'
		'remove:Remove a key entirely from the command map.'
		'list:Output the stored command map.'
		'edit:Open the command-map file in the configured editor.'
		'help:Display command help'
		'completion:Generate shell completion'
	)
	completion_values_1=("${(@f)$(dirmap list -k 2>/dev/null)}")
	completion_values_2=("${(@f)$(godo list --keys 2>/dev/null)}")

	if (( CURRENT == 2 )); then
		_describe -t commands 'godo command' commands
		compadd -- ${completion_values_1}
		return
	fi

	case $words[2] in
		get)
			_arguments \
				"2:key:($completion_values_1)"
			;;
		addcmd)
			_arguments \
				"2:key:($completion_values_1)" \
				'3:command:'
			;;
		setcmd)
			_arguments \
				"2:key:($completion_values_1)" \
				'3:command:'
			;;
		rmcmd)
			_arguments \
				"2:key:($completion_values_2)" \
				'3:command:'
			;;
		remove)
			_arguments \
				"2:key:($completion_values_2)"
			;;
		list)
			_arguments \
				'--keys[Output only the stored keys.]' \
				'-k[alias for --keys]' \
				'--json[Output raw JSON without colors.]' \
				'-j[alias for --json]'
			;;
		edit)
			_message 'no more arguments'
			;;
		help)
			_arguments '2:command:(get addcmd setcmd rmcmd remove list edit help completion)'
			;;
		completion)
			_arguments '2:shell:(zsh)'
			;;
		*)
			_arguments \
				"1:key:($completion_values_1)" \
				'--default=[One-off command to use when the key has no stored commands.]:command:'
			;;
	esac
}

compdef _godo godo
# END GENERATED COMPLETION: godo
