#!/bin/sh

git clone --recursive https://github.com/jtsternberg/Dot-Files.git ~/.dotfiles

if [[ "$OSTYPE" =~ ^linux ]]; then
	sudo apt-get -y install zsh tree tig wget
	chsh -s $(which zsh)

	cd ~/.dotfiles && chmod +x symdotfiles && symdotfiles
fi
