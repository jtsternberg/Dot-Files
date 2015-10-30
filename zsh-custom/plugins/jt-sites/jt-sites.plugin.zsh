site() {
	cd ~/vagrant/www/$1/htdocs/wp-content;
}
_site() {
	_files -W ~/vagrant/www/ -/;
}
compdef _site site
