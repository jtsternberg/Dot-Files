# Autocompletes sites in Sites directory.
site() {
	if [ -d ~/Sites/$1/wp-content ]; then
		cd ~/Sites/$1/wp-content;
	else
		cd ~/Sites/$1/app/public/wp-content;
	fi
}
_site() {
	_files -W ~/Sites/ -/;
}
compdef _site site

# Autocompletes sites in Local Sites directory.
lsite() {
	if [ -d ~/Local\ Sites/$1/wp-content ]; then
		cd ~/Local\ Sites/$1/wp-content;
	elif [ -d ~/Local\ Sites/$1/app/public/wp-content ]; then
		cd ~/Local\ Sites/$1/app/public/wp-content;
	else
		cd ~/Local\ Sites/$1/app/public;
	fi
}
_lsite() {
	_files -W ~/Local\ Sites/ -/;
}
compdef _lsite lsite
