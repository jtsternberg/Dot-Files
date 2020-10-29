# Path to your oh-my-zsh configuration.
# ZSH_DISABLE_COMPFIX=true
ZSH=$HOME/.oh-my-zsh
ZSH_CUSTOM=$HOME/.dotfiles/zsh-custom

# Set name of the theme to load.
# Look in ~/.oh-my-zsh/themes/
# Optionally, if you set this to "random", it'll load a random theme each
# time that oh-my-zsh is loaded.
ZSH_THEME="jt"
vdebuglog="$HOME/vagrant/log/debug.log"
dwlog="$HOME/Sites/dsgnwrks.pro/wp-content/debug.log"

test -f "$HOME/.dotfiles/private/additonal_aliases.sh" && source "$HOME/.dotfiles/private/additonal_aliases.sh"
alias vup="cd ~/vagrant && vagrant up && flash && say 'vagrant is up'"
alias valt="cd ~/vagrant && vagrant halt && flash && say 'vagrant is halted'"
alias vssh="cd ~/vagrant && vagrant ssh"
alias subl="/Applications/Sublime\ Text.app/Contents/SharedSupport/bin/subl"
alias zshconfig="subl ~/.dotfiles/.zshrc"
alias ohmyzsh="subl ~/.dotfiles/.oh-my-zsh"
alias cmb="cd ~/Sites/dsgnwrks.pro/wp-content/mu-plugins/cmb2/"
alias dw="cd ~/Sites/dsgnwrks.pro/"
alias dwplugins="cd ~/Sites/dsgnwrks.pro/wp-content/plugins"
alias om="cd ~/Sites/app.optinmonster.test/app/public/wp-content"
alias dwthemes="cd ~/Sites/dsgnwrks.pro/wp-content/themes"
alias dwsites="cd ~/Sites"
alias dwlibraries="cd ~/Sites/dsgnwrks.pro/wp-content/plugins/library-holder"
alias dwclients="cd ~/Documents/Work/WebDevStudios/Clients"
alias pdfjoin="/System/Library/Automator/Combine\ PDF\ Pages.action/Contents/Resources/join.py"
alias vdebug="tail -f $vdebuglog"
alias dwdebug="tail -f $dwlog"
alias cmbdebug="echo 'hello :)' > /tmp/wordpress/wp-content/debug.log && clear && tail -f /tmp/wordpress/wp-content/debug.log"
#alias mmv() { vimdiff -R <(svn cat "$1") "$1"; }
autoload -U zmv
alias mmv='noglob zmv -W'
alias mmvn='noglob zmv -W -n'
alias copysshkey='pbcopy < ~/.ssh/id_rsa.pub'
alias myip='curl ifconfig.me -v | pbcopy'
alias ql='qlmanage -p'
alias gifs='~/Sites/wpengine/gifs'
alias mergeall='git mergetool -t opendiff'
alias pubkey='more ~/.ssh/id_rsa.pub | pbcopy | printf '\''=> Public key copied to pasteboard.\n'\'
alias pubkeyzao='more ~/.ssh/id_rsa_zaobot.pub | pbcopy | printf '\''=> Public key copied to pasteboard.\n'\'
alias hidedotfiles='defaults write com.apple.finder AppleShowAllFiles -bool false &amp;&amp; killall Finder'
alias showdotfiles='defaults write com.apple.finder AppleShowAllFiles -bool true &amp;&amp; killall Finder'
# Random commit message
alias yolo='git commit -am "$(curl -s https://whatthecommit.com/index.txt)"'
alias mysqlstart="sudo /usr/local/mysql/support-files/mysql.server start"
alias mysqlstop="sudo /usr/local/mysql/support-files/mysql.server stop"
alias groot='cd `git rev-parse --show-cdup`'
alias grep='grep -in'
alias bell='echo "\a"'
alias alldone='flash && say "all done"'
alias threebell='bell; sleep 0.25; bell; sleep 0.25; bell'
alias rmbrokensymlinks='find -L . -type l -delete'
alias lsn='ls -latr'
alias structure='tree -I "node_modules" -L 3'
alias pls='sudo $(fc -ln -1)'
alias xdebuglog='sudo chmod 666 /tmp/xdebug-remote.log'
alias brewupdate="brew update && brew upgrade && brew cleanup && brew cask cleanup && npm update -g && gem update"
alias git=hub
alias dockerstart="bash '/Applications/Docker/Docker Quickstart Terminal.app/Contents/Resources/Scripts/start.sh'"
alias vlc="/Applications/VLC.app/Contents/MacOS/VLC"
alias listenstripe="stripe listen --forward-to https://app.optinmonster.test/index.php\?edd-listener\=stripe"

# t tasks https://github.com/sjl/t
alias t="python $HOME/.dotfiles/t/t.py --task-dir $HOME/Dropbox/t-tasks --list tasks"
alias tedit="open $HOME/Dropbox/t-tasks/tasks"

# Show your basic terminal text colors for terminal preferences change.
alias showcolors="printf \"\e[%dm%d dark\e[0m  \e[%d;1m%d bold\e[0m\n\" {30..37}{,,,}"
alias wpbackup='date="`date +%m%d%Y`" && wp db export backup-"$date".sql'
alias gethooks="$HOME/.dotfiles/bin/gethooks/gethooks"
alias newtab="open -a iTerm ."
alias nt="open -a iTerm ."

# For when the sidebar items disappear.
# https://apple.stackexchange.com/a/210469
alias restoreFinderSidebar="cd ~/Library/Preferences && sudo find com.apple.finder.plist* -exec rm {} \; && killall Finder"

# This alias because the HOWDOI_COLORIZE env variable isn't working.
# https://github.com/gleitz/howdoi/#notes
alias howdoi="howdoi -c"

# Find largest directories
alias top10="du -a | sort -n -r | head -n 10"

# Find largest files
alias top10files="find . -type f -exec du -Sh {} + | sort -rh | head -n 5"

# Speed up ZSH by not loading rvm/nvm by default.
# https://bennycwong.github.io/post/speeding-up-oh-my-zsh/
alias loadrvm='[[ -s "$HOME/.rvm/scripts/rvm" ]] && . "$HOME/.rvm/scripts/rvm"'
# NVM_DIR defined in .zshenv
# Potentially to try in the future:
# https://github.com/creationix/nvm/issues/539#issuecomment-245791291
# alias loadnvm='[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"'

alias itermScriptRun='~/Library/ApplicationSupport/iTerm2/iterm2env/versions/3.7.5/bin/python'
alias itermScripRun='itermScriptRun'
alias itermDirTab='itermScriptRun ~/.dotfiles/iterm-python/dirmaptab.py'
alias itermDirCommand='itermScriptRun ~/.dotfiles/iterm-python/dirmapcommand.py'

# Make sure composer uses the Brew version of PHP.
alias composer="/usr/local/Cellar/php@7.2/7.2.23/bin/php  /usr/local/bin/composer"

# Git help
alias phpcschanged='bin/phpcs --colors -s `git diff --name-only | grep .php`'
alias phpcbfchanged='bin/phpcbf --colors `git diff --name-only | grep .php`'

# alias svn='/usr/local/bin/svn'

## Git functions
source ~/.dotfiles/.git-functions

## Misc functions
source ~/.dotfiles/.misc-functions

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

# This will add a 10 second wait before you can confirm a wildcard deletion.
setopt RM_STAR_WAIT

# Path is in .zshenv


# Which plugins would you like to load? (plugins can be found in ~/.oh-my-zsh/plugins/*)
# Custom plugins may be added to ~/.oh-my-zsh/custom/plugins/
# Example format: plugins=(rails git textmate ruby lighthouse)
plugins=(
	goto-sites
	dirmap-completions
	gh-completions
	goto-dirmap
	git
	git-extras
	gitfast
	npm
	zsh-syntax-highlighting
	brew
	colorize
	composer
	# vagrant
	osx
	z
	zsh-autosuggestions
	colored-man-pages
)
# Git plugin docs: https://github.com/robbyrussell/oh-my-zsh/wiki/Plugin:git

### Fix slowness of pastes with zsh-syntax-highlighting.zsh
# https://gist.github.com/magicdude4eva/2d4748f8ef3e6bf7b1591964c201c1ab
pasteinit() {
  OLD_SELF_INSERT=${${(s.:.)widgets[self-insert]}[2,3]}
  zle -N self-insert url-quote-magic # I wonder if you'd need `.url-quote-magic`?
}
pastefinish() {
  zle -N self-insert $OLD_SELF_INSERT
}
zstyle :bracketed-paste-magic paste-init pasteinit
zstyle :bracketed-paste-magic paste-finish pastefinish
### Fix slowness of pastes
source ~/.dotfiles/zsh-custom/plugins/zsh-syntax-highlighting/zsh-syntax-highlighting.zsh

source $ZSH/oh-my-zsh.sh

# https://github.com/zsh-users/zsh-autosuggestions#disabling-suggestion-for-large-buffers
ZSH_AUTOSUGGEST_BUFFER_MAX_SIZE=20
ZSH_AUTOSUGGEST_HIGHLIGHT_STYLE='fg=black'

ZSH_HIGHLIGHT_HIGHLIGHTERS=(main brackets pattern cursor)
ZSH_HIGHLIGHT_PATTERNS+=('rm -rf *' 'fg=white,bold,bg=red')
function gi() { curl https://www.gitignore.io/api/$@  >> .gitignore;}

ZSH_DISABLE_COMPFIX=true
# VV completions, woot!
# if [[ ! -z $(which vv) ]]; then
# 	source $( echo $(which vv)-completions )
# fi

# Git Extras!
source /usr/local/opt/git-extras/share/git-extras/git-extras-completion.zsh

# added by travis gem
# [ -f /Users/JT/.travis/travis.sh ] && source /Users/JT/.travis/travis.sh

zstyle ':completion:*:*:git:*' script ~/.dotfiles/git/git-completion.bash
# fpath=(~/.dotfilesg/it $fpath)

# To refresh: rm -f ~/.zcompdump; compinit
autoload -Uz compinit
compinit