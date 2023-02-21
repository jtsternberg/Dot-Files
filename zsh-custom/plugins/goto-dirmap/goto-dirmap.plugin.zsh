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
		output=`dirmap $1`
		if [ $? -eq 0 ]
		then
			cd "$output"
		else
			echo "$output"
		fi
	}
	contains $1 $mapkeys && dirmap $1 || getoutput $1
}

_goto() {
	mapkeys=$(dirmap list -k)
	_arguments -C \
		"1: :(--help $mapkeys)" \
}

compdef _goto goto