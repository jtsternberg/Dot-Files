# Path to your oh-my-zsh configuration.
ZSH=$HOME/.oh-my-zsh
ZSH_CUSTOM=$HOME/.dotfiles/zsh-custom

# Set name of the theme to load.
# Look in ~/.oh-my-zsh/themes/
# Optionally, if you set this to "random", it'll load a random theme each
# time that oh-my-zsh is loaded.
ZSH_THEME="jt"

test -f "$HOME/.dotfiles/private/additonal_aliases.sh" && source "$HOME/.dotfiles/private/additonal_aliases.sh"
alias vup="cd ~/vagrant && vagrant up"
alias vssh="cd ~/vagrant && vagrant ssh"
alias subl="/Applications/Sublime\ Text.app/Contents/SharedSupport/bin/subl"
alias zshconfig="subl ~/.dotfiles/.zshrc"
alias ohmyzsh="subl ~/.dotfiles/.oh-my-zsh"
alias cmb="cd ~/Sites/dsgnwrks.pro/wp-content/mu-plugins/cmb2/"
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
alias mysqlstart="sudo /usr/local/mysql/support-files/mysql.server start"
alias mysqlstop="sudo /usr/local/mysql/support-files/mysql.server stop"
alias groot='cd `git rev-parse --show-cdup`'
alias grep='grep -in'
alias bell='echo "\a"'
alias alldone='say "all done"'
alias threebell='bell; sleep 0.25; bell; sleep 0.25; bell'
alias rmbrokensymlinks='find -L . -type l -delete'
alias lsn='ls -latr'
# alias svn='/usr/local/bin/svn'

# alias macdown=`open -a MacDown
# Open in MacDown
macdown() { open -a MacDown $1 }
# Create symlinks for entire directory
dwsymlink() { cd ~/Sites/dsgnwrks.pro/ && wp dwsymlink go --frompath="$1" --topath="$2" $3 }
# tail a debug log in any w
debuglog() { tail -f $1/wp-content/debug.log }
base64-svg() { echo -n `cat "$*"` | base64 | pbcopy }

dandeploy() {
	PREFIX=$*
	if [ -z "$PREFIX" ];
		then
			echo "Using dandelion.yml to deploy"
			dandelion deploy
		else
			echo "Using $PREFIX-dandelion.yml to deploy"
			dandelion --config=$PREFIX-dandelion.yml deploy
	fi
}


## Git functions

# view modified files in a directory
ls-mod-dir() { git ls-files -m -- "$*" }
# reset modified files in a directory
reset-mod-dir() { git checkout -- `git ls-files -m -- "$*"` }
# Remove untracked files (use caution)
remove-untracked() { rm -rf `git ls-files --other --exclude-standard` }
# Get all files changed b/w commits
gdifflog() { git log --name-only --pretty=oneline --full-index $*..HEAD | grep -vE '^[0-9a-f]{40} ' | sort | uniq }
deleteremote() { git push $1 :$2 }
searchcommits() { git log -G "$*" --oneline }

gifify() {
  if [[ -n "$1" ]]; then
  	 GIF="${1%.*}.gif"
    if [[ $2 == '--good' ]]; then
      ffmpeg -i $1 -r 10 -vcodec png out-static-%05d.png
      time convert -verbose +dither -layers Optimize -resize 600x600\> out-static*.png  GIF:- | gifsicle --colors 128 --delay=5 --loop --optimize=3 --multifile - > $GIF
      rm out-static*.png
    else
      ffmpeg -i $1 -s 600x400 -pix_fmt rgb24 -r 10 -f gif - | gifsicle --optimize=3 --delay=3 > $GIF
    fi
  else
    echo "proper usage: gifify <input_movie.mov>. You DO need to include extension."
  fi
}

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

# Uncomment the following line to enable command auto-correction.
# ENABLE_CORRECTION="true"

# Uncomment following line if you want red dots to be displayed while waiting for completion
COMPLETION_WAITING_DOTS="true"

# Uncomment following line if you want to disable marking untracked files under
# VCS as dirty. This makes repository status check for large repositories much,
# much faster.
# DISABLE_UNTRACKED_FILES_DIRTY="true"

# Uncomment the following line if you want to change the command execution time
# stamp shown in the history command output.
# The optional three formats: "mm/dd/yyyy"|"dd.mm.yyyy"|"yyyy-mm-dd"
# HIST_STAMPS="mm/dd/yyyy"


# Path is in .zshenv


# Which plugins would you like to load? (plugins can be found in ~/.oh-my-zsh/plugins/*)
# Custom plugins may be added to ~/.oh-my-zsh/custom/plugins/
# Example format: plugins=(rails git textmate ruby lighthouse)
plugins=(git git-extras npm zsh-syntax-highlighting brew colorize vagrant osx)
# Git plugin docs: https://github.com/robbyrussell/oh-my-zsh/wiki/Plugin:git

source $ZSH/oh-my-zsh.sh

# Set default user.
# Will remove from prompt if matches current user
# DEFAULT_USER="JT"

ZSH_HIGHLIGHT_HIGHLIGHTERS=(main brackets pattern cursor)
ZSH_HIGHLIGHT_PATTERNS+=('rm -rf *' 'fg=white,bold,bg=red')
function gi() { curl https://www.gitignore.io/api/$@  >> .gitignore;}
