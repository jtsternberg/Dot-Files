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
