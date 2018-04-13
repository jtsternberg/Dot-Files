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

# Autocompletes sites in my vagrant vvv directory.
vvvsite() {
	if [ -d ~/vagrant/www/$1/wp-content ]; then
		cd ~/vagrant/www/$1/wp-content;
	else
		cd ~/vagrant/www/$1/htdocs/wp-content;
	fi
}
_vvvsite() {
	_files -W ~/vagrant/www/ -/;
}
compdef _vvvsite vvvsite
