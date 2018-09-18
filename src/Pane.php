<?php 
namespace TidalLabs\Slacker;


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

	/**
	 * Clears the window
	 */
	public function clear()
	{
		$this->isDirty = true;
		ncurses_werase($this->window);
		return $this;
	}

	/**
	 * Get stats on the window write buffer.
	 *
	 * Returns an assoc array with 'min' (the first line number in the buffer),
	 * 'max' (highest line number in the buffer, and 'height' (the total height
	 * of the buffer).
	 */
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

	/**
	 * Height of the current buffer
	 */
	public function bufferHeight()
	{
		$stats = $this->bufferStats();
		return $stats['height'];
	}

	/**
	 * Write the buffer to the screen
	 *
	 * Also resets the buffer
	 */
	public function playBuffer()
	{
		foreach ($this->buffer as $item) {
			$this->playBufferItem($item);
		}

		$this->buffer = [];

		return $this;
	}

	/**
	 * Write an individual buffer command to the screen
	 *
	 * Probably never needs to be called directly
	 */
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

		$optionMapping = [
			'reverse' => NCURSES_A_REVERSE,
			'bold' => NCURSES_A_BOLD
		];

		foreach ($optionMapping as $option => $ncursesAttribute) {
			if (isset($item['options'][$option]) && $item['options'][$option]) {
				ncurses_wattron($this->window, $ncursesAttribute);
			}
		}

		ncurses_mvwaddstr($this->window, $item['y'], $item['x'], $item['str']);

		foreach ($optionMapping as $option => $ncursesAttribute) {
			if (isset($item['options'][$option]) && $item['options'][$option]) {
				ncurses_wattroff($this->window, $ncursesAttribute);
			}
		}

		return $this;
	}

	/**
	 * Write a string to a location on the screen
	 *
	 * This method actually adds the command to the buffer for playback later.
	 * Do not expect this command to write immediately to the screen. That
	 * happens when playBuffer is called, which happens in draw().
	 *
	 * @param $y int column
	 * @param $x int row
	 * @param $str string the string to write
	 * @param $options array additional options. Currently only 'reverse' (for NCURSES_A_REVERSE) is supported.
	 *
	 * @return this
	 */
	public function addStr($y, $x, $str, $options = array())
	{
		$this->isDirty = true;
		$this->buffer[] = ['y' => $y, 'x' => $x, 'str' => $str, 'options' => $options];

		return $this;
	}

	/**
	 * Commit the buffer to the screen
	 *
	 * Also manages the border and refreshing the window
	 */
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
