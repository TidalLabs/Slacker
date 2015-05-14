<?php 
namespace TidalLabs\Slacker;


/**
 * Manages the chat room view
 *
 * Concrete class that manages word-wrapping and rendering chat history.
 */
class RoomPane extends Pane
{

	/**
	 * Renders the contents of the room
	 *
	 * Does some cool word/line wrapping to make sure we're formatted nicely
	 * for our window size
	 */
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
			$messageText = $this->slack->formatMessage($messageText);
			$messageText = wordwrap($messageText, $availableWidth, "\n\t");

			foreach (explode("\n", $messageText) as $line) {
				$lines[] = $line;
			}

			// Blank line below each message
			$lines[] = '';
		}

		// Slice $lines to the last $availableLines
		$lines = array_slice($lines, -1*$availableLines, $availableLines);

		// Actually writes to the buffer, finally
		foreach ($lines as $index => $line) {
			$this->addStr($lineNumber, 2, $line);
			$lineNumber++;
		}

		// Print the channel name at the top
		$this->addStr(
			1,
			2,
			$this->currentChannel['name'],
			['reverse' => true]
		);

		return $this;
	}

}
