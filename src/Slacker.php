<?php 
namespace TidalLabs\Slacker;


/**
 * The application class
 *
 * Manages the various Panes in the app, manages the event loop, manages input
 * and UX, and other application-level features.
 */
class Slacker
{
	public $slack;

	/**
	 * $currentChannel is not always a channel, could be a Group or an IM too
	 *
	 * It has a specific format. Looks like this:
	 *
	 *     [
	 *         'name' => ...
	 *         'id' => ...
	 *         'type' => Groups, Channels, or IMs
	 *     ]
	 */
	public $currentChannel;

	public $paneMain;
	public $paneLeft;
	public $paneRight;
	public $paneInput;

	public $running = true;
	public $iterations = 0;

	// Current contents of the message box
	public $typing = '';

	// Auto-reloads the _current_ room only. This is not relevant to unread
	// counts for all channels
	public $autoreloadRate = 1; // seconds
	public $lastAutoreload = 0; // timestamp

	public $channelInfoReloadRate = 3; // seconds
	public $lastChannelInfoReload = 0; // timestamp
	public $groupInfoReloadRate = 5; // seconds
	public $lastGroupInfoReload = 0; // timestamp
	public $imInfoReloadRate = 15; // seconds
	public $lastImInfoReload = 0; // timestamp

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

	/**
	 * App starting point
	 */
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

		// Refresh channels.info
		if (
			$this->channelInfoReloadRate
			&& $this->lastChannelInfoReload < time() - $this->channelInfoReloadRate
		) {
			$this->slack->getNextChannelInfo();
			$this->lastChannelInfoReload = time();
		}

		// Refresh groups.info
		if (
			$this->groupInfoReloadRate
			&& $this->lastGroupInfoReload < time() - $this->groupInfoReloadRate
		) {
			$this->slack->getNextGroupInfo();
			$this->lastGroupInfoReload = time();
		}

		// Refresh IMs
		if (
			$this->imInfoReloadRate
			&& $this->lastImInfoReload < time() - $this->imInfoReloadRate
		) {
			$this->slack->ims = [];
			$this->slack->getIms();
			$this->lastImInfoReload = time();
		}
		$this->handleInput();

	}

	/**
	 * Non-blocking user input
	 *
	 * ncurses_getch is a blocking command, which we don't want. So we first
	 * check STDIN via stream_select to see if there are any messages there
	 * that would be non-blocking. If we find one, we call ncurses_getch --
	 * which is blocking, but won't block because there's a keystroke queued.
	 * If we don't find anything just return null
	 *
	 * @return int|null character key code (decode with `chr()`), or null
	 */
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

	/**
	 * Handles user input logic
	 *
	 * Key Up and Key Down to select rooms
	 * Enter will post message if one is written, or select room if no message
	 * is written
	 * Escape exits the program
	 * Backspace does what's expected of it
	 * Otherwise, if we get a printable character, add it to $this->typing and
	 * display it in the textbox
	 */
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

	/**
	 * Switch to a new room
	 */
	public function changeChannel($channel)
	{
		$this->currentChannel = $channel;
		$this->slack->getMessages($this->currentChannel);
		$this->paneRight->clear()->renderRoom()->draw();
		$this->paneLeft->clear()->renderMenu()->draw();
		return $this;
	}

	/**
	 * Post a message to the current room
	 *
	 * Will also refresh the current room
	 *
	 * @param $message string the message to send
	 */
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
