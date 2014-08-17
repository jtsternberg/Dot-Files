# Path to your oh-my-zsh configuration.
ZSH=$HOME/.oh-my-zsh

# Set name of the theme to load.
# Look in ~/.oh-my-zsh/themes/
# Optionally, if you set this to "random", it'll load a random theme each
# time that oh-my-zsh is loaded.
ZSH_THEME="jt"

# Example aliases
alias subl="/Applications/Sublime\ Text.app/Contents/SharedSupport/bin/subl"
alias zshconfig="subl ~/.dotfiles/.zshrc"
alias ohmyzsh="subl ~/.dotfiles/.oh-my-zsh"
alias cmb="cd ~/Sites/dsgnwrks.pro/wp-content/themes/elevation-worship/lib/cmb/"
alias dw="cd ~/Sites/dsgnwrks.pro/"
alias dwplugins="cd ~/Sites/dsgnwrks.pro/wp-content/plugins"
alias dwthemes="cd ~/Sites/dsgnwrks.pro/wp-content/themes"
alias dwsites="cd ~/Sites"
alias dwlibraries="cd ~/Sites/dsgnwrks.pro/wp-content/plugins/library-holder"
alias dwclients="cd ~/Documents/Work/WebDevStudios/Clients"
alias pdfjoin="/System/Library/Automator/Combine\ PDF\ Pages.action/Contents/Resources/join.py"
alias dwdebug="tail -f ~/Sites/dsgnwrks.pro/wp-content/debug.log"
#alias mmv() { vimdiff -R <(svn cat "$1") "$1";  }
autoload -U zmv
alias mmv='noglob zmv -W'
alias mmvn='noglob zmv -W -n'
alias bookmarklet='perl ~/.dotfiles/bookmarklet.pl > b'
alias copysshkey='pbcopy < ~/.ssh/id_rsa.pub'
alias myip='curl ifconfig.me -v | pbcopy'
alias ql='qlmanage -p'
alias gifs='~/Sites/wpengine/gifs'
alias mergeall='git mergetool -t opendiff'
alias pubkey='more ~/.ssh/id_rsa.pub | pbcopy | printf '\''=> Public key copied to pasteboard.\n'\'
alias hidedotfiles='defaults write com.apple.finder AppleShowAllFiles -bool false &amp;&amp; killall Finder'
alias showdotfiles='defaults write com.apple.finder AppleShowAllFiles -bool true &amp;&amp; killall Finder'
# Random commit message
alias yolo="git commit -am '`curl -s http://whatthecommit.com/index.txt`'"

source "$HOME/.dotfiles/private/additonal_aliases.sh"

## Git: view modified files in a directory
ls-mod-dir() { git ls-files -m -- "$*" }
## Git: reset modified files in a directory
reset-mod-dir() { git checkout -- `git ls-files -m -- "$*"` }
dwsymlink() { cd ~/Sites/dsgnwrks.pro/ && wp dwsymlink go --frompath="$1" --topath="$2" $3 }
## Git: Gets all submodule
## Git: Remove untracked files (use caution)
remove-untracked() { rm -rf `git ls-files --other --exclude-standard` }

base64-svg() { echo -n `cat "$*"` | base64 | pbcopy }
# Set to this to use case-sensitive completion
# CASE_SENSITIVE="true"

# Comment this out to disable bi-weekly auto-update checks
# DISABLE_AUTO_UPDATE="true"

# Uncomment to change how often before auto-updates occur? (in days)
# export UPDATE_ZSH_DAYS=13

# Uncomment following line if you want to disable colors in ls
# DISABLE_LS_COLORS="true"

# Uncomment following line if you want to disable autosetting terminal title.
# DISABLE_AUTO_TITLE="true"

# Uncomment following line if you want to disable command autocorrection
# DISABLE_CORRECTION="true"

# Uncomment following line if you want red dots to be displayed while waiting for completion
COMPLETION_WAITING_DOTS="true"

# Uncomment following line if you want to disable marking untracked files under
# VCS as dirty. This makes repository status check for large repositories much,
# much faster.
# DISABLE_UNTRACKED_FILES_DIRTY="true"


# Path is in .zshenv


# Which plugins would you like to load? (plugins can be found in ~/.oh-my-zsh/plugins/*)
# Custom plugins may be added to ~/.oh-my-zsh/custom/plugins/
# Example format: plugins=(rails git textmate ruby lighthouse)
plugins=(git zsh-syntax-highlighting brew colorize lol)

source $ZSH/oh-my-zsh.sh

# Set default user.
# Will remove from prompt if matches current user
# DEFAULT_USER="JT"

ZSH_HIGHLIGHT_HIGHLIGHTERS=(main brackets pattern cursor)
ZSH_HIGHLIGHT_PATTERNS+=('rm -rf *' 'fg=white,bold,bg=red')
function gi() { curl http://www.gitignore.io/api/$@  >> .gitignore;}
