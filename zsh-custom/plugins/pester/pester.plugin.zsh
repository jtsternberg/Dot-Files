compdef _pest_changed pest-changed
compdef _pest pest
compdef _pest sailpest

# 1) Command: runs Pest on args, or on changed test files if no args
pest-changed() {
	if (( $# )); then
		php vendor/bin/pest "$@"
	else
		# no args -> run on changed tests vs current index/HEAD
		local -a files
		files=(${(f)"$({ git diff --name-only --diff-filter=ACMRTUXB; git diff --staged --name-only --diff-filter=ACMRTUXB; } | sort -u | grep '^tests/.*\.php$')"})
		php vendor/bin/pest "${files[@]}"
	fi
}

# 2) Completion: tab cycles through changed test files
_pest_changed() {
	local -a candidates
	candidates=(${(f)"$({ git diff --name-only --diff-filter=ACMRTUXB; git diff --staged --name-only --diff-filter=ACMRTUXB; } | sort -u | grep '^tests/.*\.php$' 2>/dev/null)"})

	if (( ${#candidates} )); then
		# Offer only the changed test files
		compadd -Q -a candidates
	else
		# Fallback: normal file completion under tests/
		_files -W tests -g '*.php'
	fi
}

pest() {
	local -a pest_args
	if (( $# )); then
		pest_args=("$@")
	elif [[ -d ./tests ]]; then
		pest_args=(tests/)
	fi

	php vendor/bin/pest "${pest_args[@]}"
}

sailpest() {
	local -a pest_args
	if (( $# )); then
		pest_args=("$@")
	elif [[ -d ./tests ]]; then
		pest_args=(tests/)
	fi

	./vendor/bin/sail pest "${pest_args[@]}"
}

# 2) Completion: smartly anchor to tests/ if present; otherwise current dir
_pest() {
	# echo "" >&2
	# echo "--------------------------------" >&2
	# echo "DEBUG: _pest called with PREFIX='$PREFIX', words='${words[@]}'" >&2
	# echo "DEBUG: Current directory: $(pwd)" >&2
	# echo "DEBUG: tests directory exists: $([[ -d tests ]] && echo yes || echo no)" >&2
	# echo "--------------------------------" >&2

	if [[ -d ./tests && -z "$PREFIX" ]]; then
		# At the start of completion, and `tests/` exists.
		# Offer completions from within `tests/`, and prefix them with 'tests/'.
		_files -P 'tests/' -W ./tests -/ -g '*.php'
	else
		# Fallback to standard file completion. This handles cases where:
		# - We are in the middle of a completion (e.g., "tests/U").
		# - No "tests/" directory exists in the current path.
		_files -/ -g '*.php'
	fi
}

pestparallel() {
	php vendor/bin/pest --parallel "$@"
}

pestannounce() {
	pestparallel "$@" | tee /tmp/pest_output.txt;

	CLEAN_OUTPUT=$(sed 's/\x1B\[[0-9;]*[JKmsu]//g' /tmp/pest_output.txt);
	DURATION=$(tail -n 4 /tmp/pest_output.txt | head -n 1 | grep -o '[0-9.]\+s');

	if echo "$CLEAN_OUTPUT" | grep -qE 'Tests:\s+[1-9][0-9]* failed'; then \
		say "womp womp womp, sad trombone. tests failed. ${DURATION} seconds"; \
	else \
		say "tests success. ${DURATION} seconds"; \
	fi
}

