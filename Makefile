help:
	@echo ""
	@echo "Run 'make install' (you may need sudo) to install Slacker to /usr/local/bin/"
	@echo "After that, run 'slacker' to start"
	@echo "If you don't want to install globally, just run 'php slacker.php' to launch."
	@echo ""
	@echo "DEBUGGING:"
	@echo ""
	@echo "If you get an error about calling undefined function"
	@echo "'ncurses_init()', you'll need to install libncursesw5-dev and the ncurses"
	@echo "PHP extension."
	@echo ""
	@echo "On Ubuntu, you can run 'sudo make ubuntu-dependencies' to attempt to"
	@echo "automatically install everything."
	@echo ""
	@echo "Otherwise, install php5-dev, php-pear, libncursesw5-dev with your"
	@echo "favorite package manager..."
	@echo ""
	@echo "And then run 'sudo pecl install ncurses' and add the"
	@echo "'extension=ncurses.so' to your php.ini file"
	@echo ""

install:
	cp slacker.php /usr/local/bin/slacker
	chmod a+x /usr/local/bin/slacker

ubuntu-dependencies:
	apt-get update
	apt-get install -y php5-cli php5-dev php-pear libncursesw5-dev
	yes '' | pecl install ncurses
	echo "extension=ncurses.so" >> /etc/php5/cli/php.ini
