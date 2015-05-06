#!/usr/bin/php5
<?php

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

define('SLACK_TOKEN', $token);
define('SLACK_API',  "https://slack.com/api/");
define("ESCAPE_KEY", 27);
define("ENTER_KEY", 13);
define("BACKSPACE_KEY", 263);


/**
 * The next large section will be defining a bunch of classes. This is sloppy
 * and hard to read because I haven't figured out how to make PHARs yet so this
 * all needs to be in one file, for now.
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

	public function addStr($y, $x, $str, $options = array())
	{
		$this->isDirty = true;

		if (isset($options['reverse']) && $options['reverse']) {
			ncurses_wattron($this->window, NCURSES_A_REVERSE);
		}

		ncurses_mvwaddstr($this->window, $y, $x, $str);

		if (isset($options['reverse']) && $options['reverse']) {
			ncurses_wattroff($this->window, NCURSES_A_REVERSE);
		}

		return $this;
	}

	public function draw($force = false)
	{
		if ($this->isDirty || $force) {

			if ($this->isBordered) {
				ncurses_wborder($this->window, 0, 0, 0, 0, 0, 0, 0, 0);
			}

			ncurses_wrefresh($this->window);
		}
	}

}

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

}

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
			$this->paneLeft->highlightedMenuItem++;
			$this->paneLeft->renderMenu()->draw();
		}

		else if ($input === NCURSES_KEY_UP) {
			$this->paneLeft->highlightedMenuItem--;
			$this->paneLeft->renderMenu()->draw();
			$processInput = false;

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

		else if ($input) {
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

