# vim:ft=zsh ts=2 sw=2 sts=2
# jt theme - Powerline-style prompt. Needs a Powerline-patched font.

### Segment drawing
# A few utility functions to make it easy and re-usable to draw segmented prompts

CURRENT_BG='NONE'
SEGMENT_SEPARATOR=''

# Begin a segment
# Takes two arguments, background and foreground. Both can be omitted,
# rendering default background/foreground.
prompt_segment() {
  local bg fg
  [[ -n $1 ]] && bg="%K{$1}" || bg="%k"
  [[ -n $2 ]] && fg="%F{$2}" || fg="%f"
  if [[ $CURRENT_BG != 'NONE' && $1 != $CURRENT_BG ]]; then
    echo -n " %{$bg%F{$CURRENT_BG}%}$SEGMENT_SEPARATOR%{$fg%} "
  else
    echo -n "%{$bg%}%{$fg%} "
  fi
  CURRENT_BG=$1
  [[ -n $3 ]] && echo -n $3
}

# End the prompt, closing any open segments
prompt_end() {
  if [[ -n $CURRENT_BG && $CURRENT_BG != 'NONE' ]]; then
    echo -n " %{%k%F{$CURRENT_BG}%}$SEGMENT_SEPARATOR"
  else
    echo -n "%{%k%}"
  fi
  echo -n "%{%f%}"
  CURRENT_BG=''
}

### Prompt components
# Each component will draw itself, and hide itself if no information needs to be shown

# Context: user@hostname (who am I and where am I)
prompt_context() {
  if [[ "$USER" != "$DEFAULT_USER" || -n "$SSH_CLIENT" ]]; then
    prompt_segment black default "%(!.%{%F{yellow}%}.)$USER@%m"
  fi
}

# Git: branch/detached head, dirty status (standalone: no OMZ git plugin required)
prompt_jt_git_dirty() {
  [[ -n $(git status --porcelain 2>/dev/null) ]] && echo "1"
}
prompt_git() {
  local ref dirty repo_path

  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    repo_path=$(git rev-parse --git-dir 2>/dev/null)
    dirty=$(parse_git_dirty 2>/dev/null || prompt_jt_git_dirty)
    ref=$(git symbolic-ref HEAD 2> /dev/null) || ref="➦ $(git show-ref --head -s --abbrev |head -n1 2> /dev/null)"
    if [[ -n $dirty ]]; then
      prompt_segment yellow black
    else
      prompt_segment green black
    fi

    if [[ -f "${repo_path}/description" ]]; then
      git_desc=$(head -n 1 "${repo_path}/description")

      if [[ $git_desc == *"nnamed repository"* ]]; then
        desc=""
      else
        desc="$(echo $git_desc | sed -e 's/^[[:space:]]*//' | sed 's/ *$//g') "
      fi
    else
      desc=""
    fi

    vcs_info
    echo -n "${desc}${ref/refs\/heads\// }${vcs_info_msg_0_%% }"
  fi
}

# Dir: current working directory (shortens deep paths: ~/a/b/...N.../parent/current)
JT_PROMPT_PATH_LEADING=${JT_PROMPT_PATH_LEADING:-3}   # segments to keep at start (incl. ~)
JT_PROMPT_PATH_TRAILING=${JT_PROMPT_PATH_TRAILING:-2} # segments to keep at end
JT_PROMPT_PATH_MAX_LEN=${JT_PROMPT_PATH_MAX_LEN:-50}  # only shorten when longer than this
prompt_jt_short_path() {
  local pwd_display="${PWD/#$HOME/~}"
  local -a segments
  segments=("${(s:/:)pwd_display}")
  local n=${#segments[@]}
  local need=$(( JT_PROMPT_PATH_LEADING + JT_PROMPT_PATH_TRAILING + 1 ))
  if (( n < need )) || (( ${#pwd_display} <= JT_PROMPT_PATH_MAX_LEN )); then
    echo -n "$pwd_display"
    return
  fi
  local removed=$(( n - JT_PROMPT_PATH_LEADING - JT_PROMPT_PATH_TRAILING ))
  local leading="${(j:/:)segments[1,$JT_PROMPT_PATH_LEADING]}"
  local trailing="${(j:/:)segments[-$JT_PROMPT_PATH_TRAILING,-1]}"
  echo -n "${leading}/‹‹${removed}››/${trailing}"
}
prompt_dir() {
  prompt_segment blue black "$(prompt_jt_short_path)"
}

# Dirmap: show the shortest matching dirmap key for the current directory
# Cache parsed dirmap so we only shell out to PHP when the file changes.
typeset -gA _jt_dirmap=()
typeset -g  _jt_dirmap_mtime=""

_jt_dirmap_reload() {
  local mapfile="$HOME/.dirmap.json"
  [[ -f "$mapfile" ]] || return
  local mtime
  mtime=$(command stat -f '%m' "$mapfile" 2>/dev/null || command stat -c '%Y' "$mapfile" 2>/dev/null)
  [[ "$mtime" == "$_jt_dirmap_mtime" ]] && return
  _jt_dirmap_mtime="$mtime"
  _jt_dirmap=()
  local key val
  while IFS='=' read -r key val; do
    [[ -z "$key" ]] && continue
    val="${val/#\~/$HOME}"
    _jt_dirmap[$key]="$val"
  done < <(php -r '
    $d = json_decode(file_get_contents("'"$mapfile"'"), true);
    foreach ($d as $k => $v) { if ($k !== "") echo "$k=$v\n"; }
  ')
}

prompt_dirmap() {
  _jt_dirmap_reload
  (( ${#_jt_dirmap} )) || return
  local best="" key val
  for key val in "${(@kv)_jt_dirmap}"; do
    if [[ "$PWD" == "$val" ]]; then
      if [[ -z "$best" || ${#key} -lt ${#best} ]]; then
        best="$key"
      fi
    fi
  done
  [[ -n "$best" ]] && prompt_segment magenta white "$best"
}

# Status:
# - was there an error
# - am I root
# - are there background jobs?
prompt_status() {
  local symbols
  symbols=()
  [[ $RETVAL -ne 0 ]] && symbols+="%{%F{red}%}✘"
  [[ $UID -eq 0 ]] && symbols+="%{%F{yellow}%}⚡"
  [[ $(jobs -l | wc -l) -gt 0 ]] && symbols+="%{%F{cyan}%}⚙"

  [[ -n "$symbols" ]] && prompt_segment black default "$symbols"
}

## Main prompt
build_prompt() {
  RETVAL=$?
  prompt_status
  prompt_context
  prompt_dirmap
  prompt_dir
  prompt_git
  prompt_end
}

# One-time vcs_info setup (not per-prompt)
setopt promptsubst
autoload -Uz vcs_info
zstyle ':vcs_info:*' enable git
zstyle ':vcs_info:*' get-revision true
zstyle ':vcs_info:*' check-for-changes true
zstyle ':vcs_info:*' stagedstr '✚'
zstyle ':vcs_info:git:*' unstagedstr '●'
zstyle ':vcs_info:*' formats ' %u%c'
zstyle ':vcs_info:*' actionformats ' %u%c'

PROMPT='%{%f%b%k%}$(build_prompt) '
