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
PATH=$PATH:/Users/JT/.composer/vendor/bin/
PATH=$PATH:/home/vagrant/bin
PATH=$PATH:/Users/JT/.node/bin

export PATH=$PATH

export EDITOR=“subl”

export WP_TESTS_DIR=/tmp/wordpress/tests
export DOCKER_HOST=tcp://192.168.59.103:2376
export DOCKER_CERT_PATH=/Users/JT/.boot2docker/certs/boot2docker-vm
export DOCKER_TLS_VERIFY=1
