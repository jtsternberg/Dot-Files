# Set path as only allowing unique values
typeset -U path

# Set the path
export PATH=$PATH:/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/git/bin:/Users/jt/.rvm/bin
export PATH=${PATH}:/Users/JT/android/sdk/platform-tools:/Users/JT/android/sdk/tools:/Developer/usr/bin:/Users/JT/phpcs/scripts
export PATH=${PATH}:/usr/local/mysql/bin
export PATH=${PATH}:~/.dotfiles/bin


# path=(/Users/gary/.composer/vendor/bin /Users/gary/.rvm/gems/ruby-2.1.1/bin /Users/gary/.rvm/gems/ruby-2.1.1@global/bin /Users/gary/.rvm/rubies/ruby-2.1.1/bin /Users/gary/.rvm/bin /usr/local/bin /usr/bin /bin /usr/sbin /sbin $path)

export EDITOR=“subl”

export WP_TESTS_DIR=/tmp/wordpress/tests
