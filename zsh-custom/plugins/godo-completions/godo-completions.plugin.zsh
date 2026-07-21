# ------------------------------------------------------------------------------
# Description
# -----------
#
#  Completion for godo (https://github.com/jtsternberg/Dot-Files/blob/master/bin/godo).
#
#  godo is a plain bin (not a function like goto — it has no interactive `cd`
#  to persist), so completion is attached to it the same way goto's is: via
#  compdef. Whether the target is a function or a binary is irrelevant to zsh
#  completion. First argument completes to godo's subcommands plus every dirmap
#  key (godo shares dirmap's keys); after a subcommand, we complete keys.
#
# To run a key's stored commands:
# `godo <key>`
#
# To autocomplete:
# `godo [TAB]`
#
# ------------------------------------------------------------------------------
# Authors
# -------
#
#  * jtsternberg (https://github.com/jtsternberg)
#
# ------------------------------------------------------------------------------

_godo() {
	local subcmds mapkeys
	subcmds=($(godo commands))
	mapkeys=($(dirmap list -k))

	if (( CURRENT == 2 )); then
		# First arg: a subcommand or a dirmap key.
		_arguments -C "1: :(--help $subcmds $mapkeys)"
	else
		# After a subcommand, the next arg is (almost always) a key.
		_arguments -C "*: :($mapkeys)"
	fi
}

compdef _godo godo
