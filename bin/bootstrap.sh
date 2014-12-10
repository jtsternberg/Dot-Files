#!/bin/bash

# curl https://raw.githubusercontent.com/jtsternberg/Dot-Files/master/misc/bootstrap.sh | sh

git clone --recursive https://github.com/jtsternberg/Dot-Files.git ~/.dotfiles

if [[ "$OSTYPE" =~ ^linux ]]; then
	sudo apt-get -y install zsh tree tig wget

	cd ~/.dotfiles && chmod +x symdotfiles && sh symdotfiles
	curl -L http://install.ohmyz.sh | sh

	chsh -s $(which zsh)
fi
