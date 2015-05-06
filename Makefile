help:
	@echo ""
	@echo "Run 'make install' (you may need sudo) to install Slacker to /usr/local/bin/"
	@echo "After that, run 'slacker' to start"
	@echo "If you don't want to install globally, just run 'php slacker.php' to launch."
	@echo ""

install:
	cp slacker.php /usr/local/bin/slacker
	chmod a+x /usr/local/bin/slacker
