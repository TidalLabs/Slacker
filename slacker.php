#!/usr/bin/php5
<?php

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
 * From http://php.net/manual/en/function.ncurses-getch.php
 */
function getch_nonblock($timeout = 10) {
	$read = array(STDIN);
	$null = null;    // stream_select() uses references, thus variables are necessary for the first 3 parameters

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

class Slack
{

	public $channels;
	public $users;
	public $messages;

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

	public function getChannels()
	{
		$result = $this->callGet('channels.list', ['exclude_archived' => 1]);

		$this->channels = array();
		foreach ($result['channels'] as $channel) {
			$this->channels[$channel['id']] = $channel;
		}

		return $this->channels;
	}

	public function getUsers()
	{

		$result = $this->callGet('users.list');

		$this->users = array();
		foreach ($result['members'] as $user) {
			$this->users[$user['id']] = $user;
		}

		return $this->users;
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

	public function getMessages($channelId)
	{

		// Check if channel has existing messages
		$parameters = ['channel' => $channelId];

		if (isset($this->messages[$channelId])) {
			$messages = $this->messages[$channelId];
			$parameters['oldest'] = $messages[0]['ts'];
		} else {
			$messages = [];
		}

		$result = $this->callGet(
			'channels.history',
			$parameters
		);

		$incomingMessages = $result['messages'];
		$incomingMessages = array_reverse($incomingMessages);

		foreach ($incomingMessages as $incomingMessage) {
			array_unshift($messages, $incomingMessage);
		}

		$this->messages[$channelId] = $messages;

		return $this->messages[$channelId];
	}
}

$slack = new Slack();
$slack->getChannels();
$slack->getUsers();
$generalChannel = $slack->getChannelByName('general');
$slack->getMessages($generalChannel['id']);

ncurses_init();
ncurses_border(0,0, 0,0, 0,0, 0,0);

$main = ncurses_newwin(0,0,0,0);
ncurses_getmaxyx($main, $height, $width);

$left = ncurses_newwin($height, 24, 0, 0);
$right = ncurses_newwin($height - 2, $width - 23, 0, 23);
$textbox = ncurses_newwin(3, $width - 23, $height - 3, 23);

if (ncurses_has_colors()) {
	ncurses_start_color();
	ncurses_init_pair(1,NCURSES_COLOR_RED,NCURSES_COLOR_BLACK);
	ncurses_init_pair(2,NCURSES_COLOR_BLUE,NCURSES_COLOR_BLACK);
	ncurses_init_pair(3,NCURSES_COLOR_YELLOW,NCURSES_COLOR_BLACK);
	ncurses_init_pair(4,NCURSES_COLOR_BLUE,NCURSES_COLOR_BLACK);
	ncurses_init_pair(5,NCURSES_COLOR_MAGENTA,NCURSES_COLOR_BLACK);
	ncurses_init_pair(6,NCURSES_COLOR_CYAN,NCURSES_COLOR_BLACK);
	ncurses_init_pair(7,NCURSES_COLOR_WHITE,NCURSES_COLOR_BLACK);
}

$selectedMenuItem = 0;
$selectedMenuItemId = null;
$currentRoom = $generalChannel['id'];
$running = true;
$refreshRate = 50000; // in microseconds. 50000 = 50 ms = 0.05 s
$autoreloadRate = 500; // mod of $refreshRate
$iterations = 0;
$typing = '';

while ($running) {

	// Start left
	ncurses_wborder($left, 0,0,0,0,0,0,0,0);
	ncurses_getmaxyx($left, $leftHeight, $leftWidth);

	$index = 0;
	foreach ($slack->channels as $channel) {

		if (
			$currentRoom === $channel['id']
			|| $selectedMenuItem === $index
		) {
			ncurses_wattron($left, NCURSES_A_REVERSE);
			ncurses_mvwaddstr($left, 3+$index, 2, $channel['name']);
			ncurses_wattroff($left, NCURSES_A_REVERSE);
		} else {
			ncurses_mvwaddstr($left, 3+$index, 2, $channel['name']);
		}

		if ($selectedMenuItem === $index) {
			$selectedMenuItemId = $channel['id'];
		}

		$index++;
	}

	ncurses_mvwaddstr($left, $height-1, 2, $iterations);
	ncurses_wrefresh($left);
	// Finish left


	// Start right
	ncurses_wclear($right);
	ncurses_wborder($right, 0,0,0,0,0,0,0,0);

	ncurses_getmaxyx($right, $rightHeight, $rightWidth);
	$availableLines = $rightHeight - 4;
	$availableWidth = $rightWidth - 10;
	$lineNumber = 3;
	$messages = $slack->messages[$currentRoom];
	$messages = array_reverse($messages);
	$lines = [];

	foreach ($messages as $index => $message) {

		if (isset($message['user']) && isset($slack->users[$message['user']])) {
			$user = $slack->users[$message['user']];
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
		ncurses_mvwaddstr($right, $lineNumber, 2, $line);
		$lineNumber++;
	}

	ncurses_wattron($right, NCURSES_A_REVERSE);
	ncurses_mvwaddstr($right, 0, 2, $slack->channels[$currentRoom]['name']);
	ncurses_wattroff($right, NCURSES_A_REVERSE);

	ncurses_wrefresh($right);
	// Finish right

	// Start Textbox
	ncurses_wclear($textbox);
	ncurses_wborder($textbox, 0,0,0,0,0,0,0,0);
	ncurses_mvwaddstr($textbox, 1, 2, $typing);
	ncurses_wrefresh($textbox);
	// End textbox
	//
	ncurses_wmove($textbox, 1, 2 + strlen($typing));

	// Refresh messagelist 
	if ($autoreloadRate && $iterations % $autoreloadRate === 0) {
		$slack->getMessages($currentRoom);
	}

	$processInput = true; // Continue processing input
	$input = getch_nonblock();

	if ($input == ESCAPE_KEY) {
		$running = false;
		$processInput = false;
	}

	if ($processInput
		&& $input === NCURSES_KEY_DOWN) {
		$selectedMenuItem++;
		$processInput = false;
	}

	if ($processInput
		&& $input === NCURSES_KEY_UP) {
		$selectedMenuItem--;
		$processInput = false;
	}


	if ($processInput && $input === ENTER_KEY) {

		if (strlen($typing) === 0) {
			$currentRoom = $selectedMenuItemId;
			$slack->getMessages($currentRoom);
			ncurses_wclear($right);
		} else {
			// Send message
			$response = $slack->callPost(
				"chat.postMessage", 
				[
					'text'    => $typing,
					'channel' => $currentRoom,
					'as_user' => true,
					'parse'   => 'full'
				]
			);
			$typing = '';

			if ($response) {
				array_unshift(
					$slack->messages[$currentRoom],
					$response
				);
			}
		}

		$processInput = false;
	}


	if ($processInput && $input === NCURSES_KEY_BACKSPACE) {
		$typing = substr($typing, 0, -1);
		$processInput = false;
	}

	if ($processInput && $input) {
		$typing .= chr($input);
	}

	usleep($refreshRate);
	$iterations++;
}

ncurses_end();

