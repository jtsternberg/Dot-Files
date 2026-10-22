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

# `talkclaude <key> [claude args...]` — goto the key, then start claude there.
# A function (not a godo cmd) so the cd persists after claude exits.
talkclaude() {
	local dir
	dir="$(dirmap get "$1")" || { echo "$dir"; return 1; }
	cd "$dir" && claude "${@:2}"
}

_talkclaude() {
	_arguments "1: :($(dirmap list -k))" '*::claude args:_default'
}

compdef _talkclaude talkclaude
