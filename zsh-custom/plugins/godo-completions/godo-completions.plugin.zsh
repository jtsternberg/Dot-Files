_godo_lazy() {
	local generated

	generated="$(command godo completion zsh 2>/dev/null)" || {
		_message 'unable to generate godo completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load godo completion'
		return 1
	}

	if (( ! $+functions[_godo] )); then
		_message 'godo completion did not define _godo'
		return 1
	fi

	_godo "$@"
}

compdef _godo_lazy godo
