#!/usr/bin/php
<?php

/**
 * Slacker App
 * ===========
 *
 * Built with love by Burak Kanber of Tidal Labs. Licensed GPLv3 (see
 * LICENSE.md) so that this always stays a for-fun project.
 *
 * Overview
 * --------
 *
 * I'm sorry that all the code is in one file. Most of you don't consider it a
 * good pattern. I don't either. But it was this or the alternatives of 1)
 * making this a phar with that whole build system or 2) make some kind of
 * Make-based build system. So I went with the light and easy option. This lets
 * you just copy this single file wherever you want, run it with a php
 * interpreter, and it just works.
 *
 * This file has a little bit of stray application logic (defining constants,
 * opening the token file, and bootstrapping the Slack and Slacker objects),
 * and several classes for the management of this app:
 *
 * - Slack - a simple wrapper around the slack API
 * - Pane - an abstraction of an ncurses window
 * - MenuPane - for the channel/group/im selection pane
 * - RoomPane - for displaying room contents
 * - Slacker - the application logic class (app loop, input handling, etc)
 *
 * App Lifecycle and Architecture
 * ------------------------------
 *
 * The app starts by checking for a token in ~/.slack_token. If there's no file
 * there, we alert the user, provide instructions, and exit.
 *
 * Otherwise, we initialize a Slack object, authenticated to the API, and give
 * it to a Slacker object. The Slacker object orchestrates the interactions
 * between all the components, and the user.
 *
 * The Pane class manages basic ncurses commands. It also maintains a write
 * buffer. We use a buffer so we can check things like "height of the screen to
 * be drawn" before drawing it. We use that for scrolling. It's actually pretty
 * cool. I should write a blog post about that. It's not novel or anything.
 * Just cool for us PHP folks that rarely get to do this. Routine for system
 * programmers.
 *
 * The application loop runs after calling `start()` and will go infinitely.
 * The user can exit pressing ESC, and SIGINT (C-c) seems to work fine too.
 *
 * At some point in the future, we'll also write to a ~/.slack_notification
 * file, so that other programs like tmux can listen and display slack
 * notifications ie in the status bar.
 *
 */

/**
 * First thing's first. Get a slack token.
 */

$tokenFile = $_SERVER['HOME']."/.slack_token";
$tokenFilePath = realpath($tokenFile);
if (!file_exists($tokenFilePath)) {

echo <<<EODOC


Slack CLI App
=============

You need to install a slack API token at {$tokenFile}.

Get the token here: https://api.slack.com/web

Then paste the token into the {$tokenFile} file and restart this program.

 

EODOC;

	exit;
}

$token = file_get_contents($tokenFilePath);
$token = trim($token);

// App-wide. Eventually we should inject this into the Slack object. TECH DEBT
define('SLACK_TOKEN', $token);
define('SLACK_API',  "https://slack.com/api/");
define("ESCAPE_KEY", 27);
define("ENTER_KEY", 13);

/**
 * Prints $str to STDOUT.
 *
 * Only use this in development. Here's a good pattern:
 *
 *     php slacker.php 2> debug.log
 *
 */
function debug($str) {
	$stderr = fopen('php://stderr', 'w+');
	fwrite($stderr, $str."\n");
	fclose($stderr);
}


/**
 * The next large section will be defining a bunch of classes. This is sloppy
 * and hard to read because I haven't figured out how to make PHARs yet so this
 * all needs to be in one file, for now.
 */

/**
 * Abstracts the Slack API.
 *
 * Helps maintain and organize data on channels, users, messages, groups, ims,
 * etc. Lots of convenience functions for accessing and manipulating this data.
 */

class Slack
{

	public $channels;
	public $users;
	public $messages;
	public $groups;
	public $ims;

	function callPost($methodName, $data)
	{
		$url = SLACK_API.$methodName;
		$data['token'] = SLACK_TOKEN;

		// use key 'http' even if you send the request to https://...
		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
			),
		);

		$context  = stream_context_create($options);
		$result = file_get_contents($url, false, $context);
		$json = json_decode($result, true);

		if ($json && $json['ok'] && $json['message']) {
			return $json['message'];
		} else {
			return null; // not ok
		}
	}

	function callGet($methodName, $args = null)
	{
		if ($args === null) {
			$args = array();
		}

		$args['token'] = SLACK_TOKEN;

		$raw = file_get_contents(
			SLACK_API.$methodName.'?'.http_build_query($args)
		);

		$json = json_decode($raw, true);

		return $json;
	}

	private function _fetchAndStore($methodName, $parameters, $localVariable, $resultVariable)
	{
		$result = $this->callGet($methodName, $parameters);

		$this->{$localVariable} = [];

		foreach ($result[$resultVariable] as $item) {
			$this->{$localVariable}[$item['id']] = $item;
		}

		return $this->{$localVariable};
	}

	public function getChannels()
	{
		return $this->_fetchAndStore(
			'channels.list',
			['exclude_archived' => 1],
			'channels',
			'channels'
		);
	}

	public function getUsers()
	{

		return $this->_fetchAndStore(
			'users.list',
			[],
			'users',
			'members'
		);

	}

	public function getGroups()
	{
		return $this->_fetchAndStore(
			'groups.list',
			['exclude_archived' => 1],
			'groups',
			'groups'
		);
	}

	public function getIms()
	{
		$this->_fetchAndStore(
			'im.list',
			[],
			'ims',
			'ims'
		);

		foreach ($this->ims as $key => $im) {
			if ($im['is_user_deleted']) {
				unset($this->ims[$key]);
				continue;
			}

			if (isset($this->users[$im['user']])) {
				$userObj = $this->users[$im['user']];
			} else {
				$userObj = ['name' => 'slackbot'];
			}

			$userName = $userObj['name'];
			$this->ims[$key]['name'] = $userName;
		}

		return $this->ims;
	}

	public function getChannelByName($name)
	{

		foreach ($this->channels as $channel) {
			if ($name === $channel['name']) {
				return $channel;
			}
		}

		return null;
	}

	public function getMessages($channel)
	{
		// Translate $channel 'type' to a method name.
		if ('Channels' === $channel['type']) {
			$methodName = 'channels.history';
		} else if ('Groups' === $channel['type']) {
			$methodName = 'groups.history';
		} else if ('IMs' === $channel['type']) {
			$methodName = 'im.history';
		} else {
			throw new \BadMethodCallException("Channel type not recognized. Should be one of 'Channels', 'Groups', or 'IMs'");
		}

		// Check if channel has existing messages
		$parameters = ['channel' => $channel['id']];

		if (isset($this->messages[$channel['id']])) {
			$messages = $this->messages[$channel['id']];
			$parameters['oldest'] = $messages[0]['ts'];
		} else {
			$messages = [];
		}

		$result = $this->callGet(
			$methodName,
			$parameters
		);

		$incomingMessages = $result['messages'];
		$incomingMessages = array_reverse($incomingMessages);

		foreach ($incomingMessages as $incomingMessage) {
			array_unshift($messages, $incomingMessage);
		}

		$this->messages[$channel['id']] = $messages;

		return $this->messages[$channel['id']];
	}
}


/**
 * Abstracts ncurses windows
 *
 * Has a few nice conveniences over ncurses. The only "novel" feature of this
 * class is the scrolling buffer. We're buffering addstr commands so we can
 * figure out how tall the screen would be before writing it. We can then
 * manipulate the buffer to make it scroll before writing it to the screen.
 *
 * Make an instance of this class if you want to make a "window" (what I call a
 * "pane") on the screen. This class is much nicer than using the ncurses*
 * functions directly.
 */
class Pane
{
	public $height;
	public $width;
	public $y;
	public $x;
	public $window;
	public $isBordered = false;
	public $isDirty = true;
	public $slack;
	public $currentChannel;
	public $scrollTop = 0;

	private $buffer;

	public function __construct($height, $width, $y, $x)
	{
		$this->y = $y;
		$this->x = $x;
		$this->window = ncurses_newwin($height, $width, $this->y, $this->x);
		ncurses_getmaxyx($this->window, $this->height, $this->width);
	}

	public function clear()
	{
		$this->isDirty = true;
		ncurses_werase($this->window);
		return $this;
	}

	public function bufferStats()
	{
		$min = 999999999;
		$max = 0;

		foreach ($this->buffer as $item) {
			if ($item['y'] < $min) {
				$min = $item['y'];
			}
			if ($item['y'] > $max) {
				$max = $item['y'];
			}
		}

		$height = ($max - $min) + 1;
		return ['height' => $height, 'minY' => $min, 'maxY' => $max];
	}

	public function bufferHeight()
	{
		$stats = $this->bufferStats();
		return $stats['height'];
	}

	public function playBuffer()
	{
		foreach ($this->buffer as $item) {
			$this->playBufferItem($item);
		}

		$this->buffer = [];

		return $this;
	}

	public function playBufferItem($item)
	{
		// Adjust offset
		$item['y'] -= $this->scrollTop;

		// Skip this item if it's offscreen
		if ($item['y'] < 1) {
			return $this;
		}

		if ($item['y'] >= $this->height-1) {
			return $this;
		}

		if (isset($item['options']['reverse']) && $item['options']['reverse']) {
			ncurses_wattron($this->window, NCURSES_A_REVERSE);
		}

		ncurses_mvwaddstr($this->window, $item['y'], $item['x'], $item['str']);

		if (isset($item['options']['reverse']) && $item['options']['reverse']) {
			ncurses_wattroff($this->window, NCURSES_A_REVERSE);
		}

		return $this;
	}

	public function addStr($y, $x, $str, $options = array())
	{
		$this->isDirty = true;
		$this->buffer[] = ['y' => $y, 'x' => $x, 'str' => $str, 'options' => $options];

		return $this;
	}

	public function draw($force = false)
	{
		if ($this->isDirty || $force) {

			if ($this->isBordered) {
				ncurses_wborder($this->window, 0, 0, 0, 0, 0, 0, 0, 0);
			}

			$this->bufferHeight();
			$this->playBuffer();

			ncurses_wrefresh($this->window);
		}
	}

}

/**
 * Manages the Channels/IMs/Groups menu
 *
 * Extends from Pane. This is more of a concrete class than an abstract one:
 * it's all about managing that menu. It displays it, and handles the logic for
 * selecting and switching rooms.
 */
class MenuPane extends Pane
{

	public $highlightedMenuItem = null;
	public $highlightedMenuItemData;

	public function renderSubmenu($title, $items, $startLineNumber)
	{
		// Check if we have a highlightedMenuItem set.
		if ($this->highlightedMenuItem === null) {
			$counter = $startLineNumber + 2;
			foreach ($this->slack->channels as $channel) {
				if ($channel['name'] === $this->currentChannel['name']) {
					$this->highlightedMenuItem = $counter;
				}
				$counter++;
			}
		}

		// Yes, we're just renaming the variable.
		// Why two names? Because $startLineNumber really tells you what the
		// variable is for.
		$index = $startLineNumber;

		// say hi
		$this->addStr($index, 2, $title);
		$index += 2;


		foreach ($items as $item) {

			$options = [];
			if (
				$this->currentChannel['id'] === $item['id']
				|| $this->highlightedMenuItem === $index
			) {
				$options['reverse'] = true;
			}

			$this->addStr($index, 2, $item['name'], $options);

			if ($this->highlightedMenuItem === $index) {
				$this->highlightedMenuItemData = [
					'type' => $title,
					'id' => $item['id'],
					'name' => $item['name']
				];
			}

			$index++;
		}

	}

	public function renderChannels($startLineNumber = 1)
	{
		$this->renderSubmenu("Channels", $this->slack->channels, $startLineNumber);
		return $this;
	}

	public function renderGroups($startLineNumber = 20)
	{
		$this->renderSubmenu("Groups", $this->slack->groups, $startLineNumber);
		return $this;
	}

	public function renderIms($startLineNumber = 30)
	{
		$this->renderSubmenu("IMs", $this->slack->ims, $startLineNumber);
		return $this;
	}

	public function renderMenu()
	{

		$this->renderChannels(1);

		$imStartLine = count($this->slack->channels) + 4;
		$this->renderIms($imStartLine);

		$groupStartLine = $imStartLine + count($this->slack->ims) + 4;
		$this->renderGroups($groupStartLine);


		return $this;

	}

	public function scrollUp()
	{
		$this->highlightedMenuItem--;
		$this->fixScrollTop();
		$this->clear()->renderMenu()->draw();
		return $this;
	}

	public function scrollDown()
	{
		$this->highlightedMenuItem++;
		$this->fixScrollTop();
		$this->clear()->renderMenu()->draw();
		return $this;
	}

	public function fixScrollTop()
	{

		if ($this->highlightedMenuItem > $this->height - 10) {
			$this->scrollTop = 10 - ($this->height - $this->highlightedMenuItem);
		}

		if ($this->highlightedMenuItem < $this->height - 10) {
			$this->scrollTop = 0;
		}
	}
}

/**
 * Manages the chat room view
 *
 * Concrete class that manages word-wrapping and rendering chat history.
 */
class RoomPane extends Pane
{

	public function renderRoom()
	{
		$availableLines = $this->height - 4;
		$availableWidth = $this->width - 10;
		$lineNumber = 3;
		$messages = $this->slack->messages[$this->currentChannel['id']];
		$messages = array_reverse($messages);
		$lines = [];

		foreach ($messages as $index => $message) {

			if (isset($message['user']) && isset($this->slack->users[$message['user']])) {
				$user = $this->slack->users[$message['user']];
			} else {
				$user = null;
			}

			$messageText = ($user ? $user['name'] : 'bot').': '.$message['text'];
			$messageText = wordwrap($messageText, $availableWidth, "\n\t");
			foreach (explode("\n", $messageText) as $line) {
				$lines[] = $line;
			}

			$lines[] = '';

		}

		// Slice $lines to the last $availableLines
		$lines = array_slice($lines, -1*$availableLines, $availableLines);

		foreach ($lines as $index => $line) {
			$this->addStr($lineNumber, 2, $line);
			$lineNumber++;
		}

		$this->addStr(
			1,
			2,
			$this->currentChannel['name'],
			['reverse' => true]
		);

		return $this;
	}

}

/**
 * The application class
 *
 * Manages the various Panes in the app, manages the event loop, manages input
 * and UX, and other application-level features.
 */
class Slacker
{
	public $slack;
	public $currentChannel;

	public $paneMain;
	public $paneLeft;
	public $paneRight;
	public $paneInput;

	public $running = true;
	public $iterations = 0;
	public $typing = '';

	public $autoreloadRate = 5; // seconds
	public $lastAutoreload = 0; // timestamp


	public function __construct($slack)
	{
		$this->slack = $slack;
		$this->init();
	}

	public function __destruct()
	{
		ncurses_end();
	}

	public function init()
	{
		$this->initSlack();
		$this->initWindow();

		$this->paneLeft->renderMenu()->draw();
		$this->paneRight->renderRoom()->draw();

	}

	public function initSlack()
	{
		$this->slack->getChannels();
		$this->slack->getUsers();
		$this->slack->getGroups();
		$this->slack->getIms();

		// Initialize with the General channel.
		$generalChannel = $this->slack->getChannelByName('general');
		$generalChannel['type'] = 'Channels';
		$this->slack->getMessages($generalChannel);
		$this->currentChannel = $generalChannel;

	}

	public function initWindow()
	{

		ncurses_init();
		ncurses_noecho();
		ncurses_border(0,0, 0,0, 0,0, 0,0);
		ncurses_refresh();

		$this->paneMain = new Pane(0, 0, 0, 0);
		$this->paneLeft = new MenuPane($this->paneMain->height, 24, 0, 0);
		$this->paneRight = new RoomPane($this->paneMain->height - 2, $this->paneMain->width - 23, 0, 23);
		$this->paneInput = new Pane(3, $this->paneMain->width - 23, $this->paneMain->height - 3, 23);

		$this->paneLeft->isBordered = true;
		$this->paneRight->isBordered = true;
		$this->paneInput->isBordered = true;

		$this->paneLeft->slack = &$this->slack;
		$this->paneRight->slack = &$this->slack;
		$this->paneInput->slack = &$this->slack;

		$this->paneLeft->currentChannel = &$this->currentChannel;
		$this->paneRight->currentChannel = &$this->currentChannel;
		$this->paneInput->currentChannel = &$this->currentChannel;

	}

	public function refreshInput($typing)
	{
		$this->paneInput->clear();
		$this->paneInput->addStr(1, 2, $typing);
		$this->paneInput->draw();
		ncurses_wmove($this->paneInput->window, 1, 2 + strlen($typing));
		return $this;
	}

	public function reloadCurrentRoom()
	{
		$this->slack->getMessages($this->currentChannel);
		$this->paneRight->clear()->renderRoom()->draw();
	}


	public function start()
	{

		while ($this->running)
		{
			$this->innerLoop();
			$this->iterations++;
		}

	}

	public function innerLoop()
	{

		// Fill in the textbox
		$this->refreshInput($this->typing);

		// Refresh messagelist
		if (
			$this->autoreloadRate
			&& $this->lastAutoreload < time() - $this->autoreloadRate
		) {
			$this->reloadCurrentRoom();
			$this->lastAutoreload = time();
		}

		//
		$this->handleInput();

	}

	public function getInput()
	{
		$timeout = 1000000;
		$read = array(STDIN);
		$null = null;

		$stream = stream_select(
			$read,
			$null,
			$null,
			floor($timeout / 1000000),
			$timeout % 1000000
		);

		if ($stream !== 1) {
			return null;
		}

		return ncurses_getch();

	}

	public function handleInput()
	{

		$processInput = true; // Continue processing input
		$input = $this->getInput();

		if ($input == ESCAPE_KEY) {
			$this->running = false;
		}

		else if ($input === NCURSES_KEY_DOWN) {
			$this->paneLeft->scrollDown();
		}

		else if ($input === NCURSES_KEY_UP) {
			$this->paneLeft->scrollUp();

		} else if ($input === ENTER_KEY) {

			if (strlen($this->typing) === 0) {

				$this->changeChannel($this->paneLeft->highlightedMenuItemData);

			} else {

				$this->sendMessage($this->typing);
				$this->typing = '';

			}

		}

		else if ($input === NCURSES_KEY_BACKSPACE) {
			$this->typing = substr($this->typing, 0, -1);
		}

		else if (ctype_print($input)) {
			$this->typing .= chr($input);
		}

	}

	public function changeChannel($channel)
	{
		$this->currentChannel = $channel;
		$this->slack->getMessages($this->currentChannel);
		$this->paneRight->clear()->renderRoom()->draw();
		$this->paneLeft->clear()->renderMenu()->draw();
		return $this;
	}

	public function sendMessage($message)
	{
		// Send message
		$response = $this->slack->callPost(
			"chat.postMessage",
			[
				'text'    => $message,
				'channel' => $this->currentChannel['id'],
				'as_user' => true,
				'parse'   => 'full'
			]
		);

		if ($response) {
			array_unshift(
				$this->slack->messages[$this->currentChannel['id']],
				$response
			);
		}

		$this->paneRight->slack = $this->slack;
		$this->paneRight->clear()->renderRoom()->draw();
	}
}

/**
 * The app starts here.
 */


$slack = new Slack();
$slacker = new Slacker($slack);
$slacker->start();

