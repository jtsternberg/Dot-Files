compdef _pest_changed pest-changed
compdef _pest pest
compdef _pest sailpest

# Helper function to get unstaged test files
_get_unstaged_test_files() {
	git diff --name-only --diff-filter=ACMRTUXB | grep '^tests/.*\.php$'
}

# Helper function to get staged test files
_get_staged_test_files() {
	git diff --staged --name-only --diff-filter=ACMRTUXB | grep '^tests/.*\.php$'
}

# Helper function to get changed test files on current branch vs main
_get_branch_test_files() {
	local main_branch=$(git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null | sed 's@^refs/remotes/origin/@@' || echo "master")
	git diff --name-only --diff-filter=ACMRTUXB "$main_branch"...HEAD | grep '^tests/.*\.php$'
}

# Helper function to execute a command and print it
_run_and_print_cmd() {
	local CMD="$1"
	echo "\n- command run:"
	echo "    $CMD\n"
	eval $CMD
}

# 1) Command: runs Pest on args, or on changed test files if no args
pest-changed() {
	local staged_only=false
	local unstaged_only=false
	local branch_only=false
	local use_sail=false
	local -a pest_args=()
	local has_flags=false

	# Parse flags
	while (( $# )); do
		case "$1" in
			--staged)
				staged_only=true
				has_flags=true
				shift
				;;
			--unstaged)
				unstaged_only=true
				has_flags=true
				shift
				;;
			--branch)
				branch_only=true
				has_flags=true
				shift
				;;
			--sail)
				use_sail=true
				has_flags=true
				# pest_args everything but --sail
				shift
				;;
			*)
				pest_args+=("$1")
				has_flags=true
				shift
				;;
		esac
	done

	if $has_flags; then
		echo "\nFlags:"
		if $staged_only; then
			echo "    - Staged Only: true"
		fi
		if $unstaged_only; then
			echo "    - Unstaged Only: true"
		fi
		if $branch_only; then
			echo "    - Branch Only: true"
		fi
		if $use_sail; then
			echo "    - Use Sail: true"
		fi
	fi

	# If specific args provided, just run pest with those args
	if (( ${#pest_args} )); then
		if $use_sail; then
			_run_and_print_cmd "./vendor/bin/sail pest ${(q)pest_args[@]}"
		else
			_run_and_print_cmd "php vendor/bin/pest ${(q)pest_args[@]}"
		fi
		return
	fi

	local -a files=()

	if $staged_only; then
		files=(${(f)"$(_get_staged_test_files)"})
	elif $unstaged_only; then
		files=(${(f)"$(_get_unstaged_test_files)"})
	elif $branch_only; then
		files=(${(f)"$(_get_branch_test_files)"})
	else
		# No flags -> check consecutively: unstaged, staged, branch
		files=(${(f)"$(_get_unstaged_test_files)"})
		if (( ! ${#files} )); then
			files=(${(f)"$(_get_staged_test_files)"})
		fi
		if (( ! ${#files} )); then
			files=(${(f)"$(_get_branch_test_files)"})
		fi
	fi

	if (( ${#files} )); then
		if $use_sail; then
			_run_and_print_cmd "./vendor/bin/sail pest ${(q)files[@]}"
		else
			_run_and_print_cmd "php vendor/bin/pest ${(q)files[@]}"
		fi
	else
		echo "No changed test files found."
		return 1
	fi
}

# 2) Completion: tab cycles through changed test files
_pest_changed() {
	local context state state_descr line
	typeset -A opt_args

	# Handle flag completion
	_arguments -C \
		'--staged[Run tests only on staged files]' \
		'--unstaged[Run tests only on unstaged files]' \
		'--branch[Run tests only on files changed in current branch]' \
		'--sail[Run tests using Laravel Sail]' \
		'*:test files:->files'

	case $state in
		files)
			local -a candidates

			# Check if any flags were specified
			if [[ -n $opt_args[--staged] ]]; then
				candidates=(${(f)"$(_get_staged_test_files 2>/dev/null)"})
			elif [[ -n $opt_args[--unstaged] ]]; then
				candidates=(${(f)"$(_get_unstaged_test_files 2>/dev/null)"})
			elif [[ -n $opt_args[--branch] ]]; then
				candidates=(${(f)"$(_get_branch_test_files 2>/dev/null)"})
			else
				# No flags -> get all changed files (same order as function: unstaged, staged, branch)
				local -a unstaged staged branch_files
				unstaged=(${(f)"$(_get_unstaged_test_files 2>/dev/null)"})
				staged=(${(f)"$(_get_staged_test_files 2>/dev/null)"})
				branch_files=(${(f)"$(_get_branch_test_files 2>/dev/null)"})
				candidates=($unstaged $staged $branch_files)
				# Remove duplicates while preserving order
				candidates=(${(u)candidates})
			fi

			if (( ${#candidates} )); then
				# Offer only the changed test files
				compadd -Q -a candidates
			else
				# Fallback: normal file completion under tests/
				_files -W tests -g '*.php'
			fi
			;;
	esac
}

pest() {
	local -a pest_args
	if (( $# )); then
		pest_args=("$@")
	elif [[ -d ./tests ]]; then
		pest_args=(tests/)
	fi

	_run_and_print_cmd "php vendor/bin/pest ${(q)pest_args[@]}"
}

sailpest() {
	local -a pest_args
	if (( $# )); then
		pest_args=("$@")
	elif [[ -d ./tests ]]; then
		pest_args=(tests/)
	fi

	_run_and_print_cmd "./vendor/bin/sail pest ${(q)pest_args[@]}"
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
	_run_and_print_cmd "php vendor/bin/pest --parallel ${(q)@}"
}

pestannounce() {
	local use_sail=false
	local -a pest_args=()

	# Parse arguments to check for --sail flag
	for arg in "$@"; do
		if [[ "$arg" == "--sail" ]]; then
			use_sail=true
		else
			pest_args+=("$arg")
		fi
	done

	if $use_sail; then
		_run_and_print_cmd "./vendor/bin/sail pest --parallel ${(q)pest_args[@]} | tee /tmp/pest_output.txt"
	else
		_run_and_print_cmd "php vendor/bin/pest --parallel ${(q)pest_args[@]} | tee /tmp/pest_output.txt"
	fi

	CLEAN_OUTPUT=$(sed 's/\x1B\[[0-9;]*[JKmsu]//g' /tmp/pest_output.txt);
	DURATION=$(tail -n 4 /tmp/pest_output.txt | head -n 1 | grep -o '[0-9.]\+s');

	if echo "$CLEAN_OUTPUT" | grep -qE 'Tests:\s+[1-9][0-9]* failed'; then \
		say "womp womp womp, sad trombone. tests failed. ${DURATION} seconds"; \
	else \
		say "tests success. ${DURATION} seconds"; \
	fi
}

