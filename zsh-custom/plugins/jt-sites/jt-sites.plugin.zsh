site() {
	cd ~/vagrant/www/$1/htdocs/wp-content;
}
_site() {
	_files -W ~/Sites/www/ -/;
}
compdef _site site
