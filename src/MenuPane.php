<?php 
namespace TidalLabs\Slacker;

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

	/**
	 * Render a submenu (Channels, IMs, Groups)
	 */
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
		$this->addStr($index, 2, $title, ['bold' => true]);
		$index += 2;


		foreach ($items as $item) {

			$options = [];
			if (
				$this->currentChannel['id'] === $item['id']
				|| $this->highlightedMenuItem === $index
			) {
				$options['reverse'] = true;
			}

			$text = '';
			if (isset($item['unread_count_display']) && $item['unread_count_display']) {
				$text .= "[".$item['unread_count_display']."] ";
			}

			$text .= $item['name'];

			$this->addStr($index, 2, $text, $options);

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

	/**
	 * Render the whole menu
	 *
	 * Does some logic to figure out the spacing, that's all. Otherwise just
	 * calls the other helper methods.
	 */
	public function renderMenu()
	{

		$this->renderChannels(1);

		$imStartLine = count($this->slack->channels) + 4;
		$this->renderIms($imStartLine);

		$groupStartLine = $imStartLine + count($this->slack->ims) + 4;
		$this->renderGroups($groupStartLine);


		return $this;

	}

	/**
	 * Call this to scroll up one item
	 */
	public function scrollUp()
	{
		$this->highlightedMenuItem--;
		$this->fixScrollTop();
		$this->clear()->renderMenu()->draw();
		return $this;
	}

	/**
	 * Call this to scroll down one item
	 */
	public function scrollDown()
	{
		$this->highlightedMenuItem++;
		$this->fixScrollTop();
		$this->clear()->renderMenu()->draw();
		return $this;
	}

	/**
	 * Keeps the current highlighted item on-screen
	 */
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
