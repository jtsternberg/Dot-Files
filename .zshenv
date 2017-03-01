# Set path as only allowing unique values
typeset -U path

# Set architecture flags
export ARCHFLAGS="-arch x86_64"
# Ensure user-installed binaries take precedence
export PATH=/usr/local/bin:$PATH

# Set the path
# export PATH=$PATH:/usr/local/bin
# PATH=$PATH
PATH=$(brew --prefix coreutils)/libexec/gnubin
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
PATH=$PATH:~/.dotfiles/bin
PATH=$PATH:~/.dotfiles/bin/vv
PATH=$PATH:~/.composer/vendor/bin
PATH=$PATH:/home/vagrant/bin
PATH=$PATH:~/.node/bin
PATH=$PATH:/usr/local/sbin

export PATH=$PATH
export PATH="$(brew --prefix coreutils)/libexec/gnubin:/usr/local/bin:$PATH"


export EDITOR=“subl”

export WP_TESTS_DIR=/tmp/wordpress/tests

export DOCKER_TLS_VERIFY="1"
export DOCKER_HOST="tcp://192.168.99.100:2376"
export DOCKER_CERT_PATH="$HOME/.docker/machine/machines/default"
export DOCKER_MACHINE_NAME="default"
# Run this command to configure your shell:
# eval $(docker-machine env)
