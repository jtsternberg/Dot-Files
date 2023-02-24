# Set path as only allowing unique values
typeset -U path

# Set architecture flags
export ARCHFLAGS="-arch x86_64"
# Set the path
# export PATH=$PATH:/usr/local/bin
# PATH=$PATH
# Ensure user-installed binaries take precedence
PATH=$PATH:/usr/local/bin
PATH=$PATH:/usr/bin
PATH=$PATH:/bin
PATH=$PATH:/usr/sbin
PATH=$PATH:/sbin
PATH=$PATH:/Developer/usr/bin
PATH=$PATH:/usr/local/MacGPG2/bin
PATH=$PATH:/usr/local/lib
PATH=$PATH:/usr/local/git/bin
PATH=$PATH:/usr/local/mysql/bin
PATH=$PATH:~/.rvm/bin
PATH=$PATH:~/android/sdk/platform-tools
PATH=$PATH:~/android/sdk/tools
PATH=$PATH:~/phpcs/scripts
PATH=$PATH:~/wpcs/vendor/bin
PATH=$PATH:~/.dotfiles/bin
PATH=$PATH:~/.dotfiles/bin/vv
PATH=$PATH:~/.composer/vendor/bin
PATH=$PATH:/home/vagrant/bin
PATH=$PATH:~/.node/bin
PATH=$PATH:./node_modules/.bin
PATH=$PATH:/usr/local/sbin
PATH=$PATH:~/.dotfiles/git
PATH=$PATH:~/Library/ApplicationSupport/iTerm2/iterm2env/versions/3.10.4/bin
PATH=$PATH:/Applications/Sublime\ Text.app/Contents/SharedSupport/bin


# Set PATH, MANPATH, etc., for Homebrew.
eval "$(/opt/homebrew/bin/brew shellenv)"
PATH=$PATH=$(brew --prefix coreutils)/libexec/gnubin
PATH=$PATH:$(brew --prefix)/share/zsh/site-functions
PATH=$PATH:$(brew --prefix)/libexec/gnubin:/usr/local/bin:
export PATH=$PATH

export EDITOR='subl -w'
export SVN_EDITOR='/Applications/Sublime\ Text.app/Contents/SharedSupport/bin/subl -w'

export WP_TESTS_DIR=/tmp/wordpress/tests

export NVM_DIR="$HOME/.nvm"
# [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" # This loads nvm
# Run this command to configure your shell:
# eval $(docker-machine env)
