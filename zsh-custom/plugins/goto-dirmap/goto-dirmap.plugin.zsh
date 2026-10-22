# ------------------------------------------------------------------------------
# Description
# -----------
#
#  Completion script for dirmap (https://github.com/jtsternberg/Dot-Files/blob/master/bin/dirmap).
#
# To add directories shortcuts:
# `dirmap add <key> <path>``
#
# To navigate directories:
# `goto <key>`
#
# To autocomplete:
# `goto [TAB]`
#
# ------------------------------------------------------------------------------
# Authors
# -------
#
#  * jtsternberg (https://github.com/jtsternberg)
#
# ------------------------------------------------------------------------------

goto() {
	mapkeys=($(dirmap commands))

	getoutput () {
		output=`dirmap "$1"`
		if [ $? -eq 0 ]
		then
			cd "$output"
		else
			echo "$output"
		fi
	}
	if contains "$1" $mapkeys; then
		dirmap "$@"
	else
		getoutput "$1"
	fi
}

_goto() {
	mapkeys=$(dirmap list -k)
	_arguments -C \
		"1: :(--help $mapkeys)" \
}

compdef _goto goto

# `talkagent <key> [--agent <bin>] [agent args...]` — goto the key, then start
# the agent (default claude) there with every other arg passed through.
# A function (not a godo cmd) so the cd persists after the agent exits.
talkagent() {
	local dir agent=claude
	local -a args
	dir="$(dirmap get "$1")" || { echo "$dir"; return 1; }
	shift
	while (( $# )); do
		case "$1" in
			--agent) agent="$2"; shift 2 ;;
			--agent=*) agent="${1#--agent=}"; shift ;;
			*) args+=("$1"); shift ;;
		esac
	done
	cd "$dir" && "$agent" "${args[@]}"
}

_talkagent() {
	_arguments \
		"1: :($(dirmap list -k))" \
		'--agent=[agent CLI to start]:agent:(claude codex agy pi aider)' \
		'*::agent args:_default'
}

compdef _talkagent talkagent
