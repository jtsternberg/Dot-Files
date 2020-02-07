_dirmap() {
	mapkeys=$(dirmap list -k)
	_arguments -C \
	    "1: :(--help $mapkeys)" \
}
compdef _dirmap dirmap