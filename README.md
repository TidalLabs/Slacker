Slacker
=======

Introduction
------------

Slacker is a simple weekend project CLI interface to Slack. This is not a
library and is not yet intended to be developed on top of. It's not even very
good. This is just a simple, silly command line Slack client. But if you live
in tmux or the command line like me, you might just like it.

![Slacker Screenshot](http://i.imgur.com/NS0P2u9.png)

Right now this is a goof project. But if the world loves it and wants to
contribute to it, then I will maintain the app and make it a little more
robust. Currently there's no testing, autoloading, no options, documentation,
logging, or any niceties that a real app should enjoy. I will build those
things if the project ends up warranting it, and that's all up to you.

Installation
------------

Quick version:

	$ git clone https://github.com/TidalLabs/Slacker.git
	$ cd Slacker
    $ sudo make install

That will copy the executable to your /usr/local/bin/ path. You can then run
the program with:

    $ slacker

Then follow the instructions to install your Slack token. (See "Configuration" below)

More detail: this is just a PHP script. Run `slacker.php` any way you like.
Here are some options:

    $ php slacker.php

	$ chmod a+x slacker.php
	$ ./slacker.php

	$ chmod a+x slacker.php
	$ sudo cp slacker.php /usr/local/bin/slacker
	$ slacker

Installation Dependencies
-------------------------

Slacker requires the PHP CLI executable compiled with ncurses support.

If you don't know how to do all of that, and are on Ubuntu, just run
`sudo make ubuntu-dependencies` to get the job done. That _might_ work on
Debian too, but I haven't tested it.

For other systems, you basically have to do the following:

1) Ensure php5-cli, php5-dev, php-pear, and libncursesw5-dev are installed. Usually a package manager can take care of this for you. On Ubuntu or debian `apt-get install` does the trick.

2) Install the PHP ncurses with `sudo pecl install ncurses`. You might need to resolve some other packages if the pecl command fails.

3) Once pecl successfully installs ncurses, add "extension=ncurses.so" to the bottom of your php.ini file. This might be located somewhere like /etc/php5/cli/php.ini.

For OS X based systems, you have to do the following 

1) Install pear 
```
curl -O http://pear.php.net/go-pear.phar
sudo php -d detect_unicode=0 go-pear.phar`
```

2) Install the PHP ncurses with `sudo pecl install ncurses`. You might need to resolve some other packages if the pecl command fails.

3) Once pecl successfully installs ncurses, add "extension=ncurses.so" to the bottom of your php.ini file. This might be located somewhere like /etc/php.ini.

If you've figured this out on a non-debian system, it'd be great if you could
submit a pull request adding your system to the Makefile.

Configuration
-------------

The app will yell at you if you don't install a file with your slack token in
it.

Visit https://api.slack.com/web, scroll down, and generate a token for
yourself.

Then paste that token into `~/.slack_token` and all will be well.

Usage
-----

Use the up and down arrow keys to highlight a new room. Hit enter and you'll
enter that room.

Start typing and your letters will appear in the box. Hit enter and your
message will be sent to the room you're in.

Your box will now be empty. Hit enter again. That manually refreshes the room
you're in (basically just re-selecting the current room from the menu). You may
or may not see new messages depending on how many friends you have.

Wait a little while. You may see new messages. That's because we auto-refresh
the room for you.

Hit Escape to exit.

There are literally no other features right now. There are no colors. There are
no options. Unread counts probably don't work. There are no unit tests. There's only
one file and no build system. We can fix these things over time if you like.

Contributing
------------

Use github, create github issues, use topic branches, make pull requests, be
polite, be patient.

Changelog
---------

Check out `git log` for the Changelog. I will semver and tag once we hit v1.0.0.

License
-------

Everything here is GPL v3.

About the Author
----------------

Burak Kanber is co-founder and CTO of Tidal Labs, a tech startup in digital
marketing. He loves the command line and wants to spend even more time in it,
so he took a day and built a simple CLI app using the Slack API.

Right now the plan for this repository is for me to continue contrbuting it
just to clean up some code and add the occasional feature. If I start using the
app every day, I'm sure certain things will bug me and I'll fix them. But this
isn't professional-grade software and isn't intended to be. There are no unit
tests and the code is poorly organized. If you want to help me
fix that, please feel free to contribute! I'm happy to maintain this
repository, but I don't want to hear criticism about not making this code
professional enough as I was never planning on sharing it publicly and only
made an attempt to clean if up after deciding to open source it ;).
