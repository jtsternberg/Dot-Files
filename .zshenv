# Set path as only allowing unique values
typeset -U path

# Set the path
# export PATH=$PATH:/usr/local/bin
# PATH=$PATH
PATH=/usr/local/bin
PATH=$PATH:/usr/bin
# PATH=$PATH:/usr/local/bin
PATH=$PATH:/bin
PATH=$PATH:/usr/sbin
PATH=$PATH:/sbin
PATH=$PATH:/Developer/usr/bin
PATH=$PATH:/usr/local/MacGPG2/bin
PATH=$PATH:/usr/local/lib
PATH=$PATH:/usr/local/git/bin
PATH=$PATH:/usr/local/mysql/bin
PATH=$PATH:/Users/JT/.rvm/bin
PATH=$PATH:/Users/JT/android/sdk/platform-tools
PATH=$PATH:/Users/JT/android/sdk/tools
PATH=$PATH:/Users/JT/phpcs/scripts
PATH=$PATH:/Users/JT/.dotfiles/bin
PATH=$PATH:/Users/JT/.dotfiles/bin/vv

export PATH=$PATH

export EDITOR=“subl”

export WP_TESTS_DIR=/tmp/wordpress/tests
