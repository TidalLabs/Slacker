<?php 
namespace TidalLabs\Slacker;


/**
 * Abstracts the Slack API.
 *
 * Helps maintain and organize data on channels, users, messages, groups, ims,
 * etc. Lots of convenience functions for accessing and manipulating this data.
 */
class Slack {

	// API token for the current user and team
	private $token;

	/**
	 * These store results of API calls. Most are formatted as assoc arrays
	 * with the key as the Slack ID of the object.
	 *
	 * The exception is the $messages variable, which is a 2D array. The first
	 * dimension is the channel ID of the messages underneath it.
	 */
	public $channels;
	public $users;
	public $messages;
	public $groups;
	public $ims;
	public $me;

	// Counter used internally to rate limit channels.info calls
	public $channelInfoCounter = 0;
	public $groupInfoCounter = 0;

	/**
	 * Constructor takes a Slack token
	 */
	public function __construct($token)
	{
		$this->token = $token;
		$this->getMe();
	}

	/**
	 * Submit a POST request to Slack API
	 *
	 * @param $methodName string XMLRPC-style method name for the API function you want
	 * @param $data array associative array of POST data
	 *
	 * @return array|null returns the 'message' portion of the post request
	 */
	function callPost($methodName, $data)
	{
		$url = SLACK_API.$methodName;
		$data['token'] = $this->token;

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

	/**
	 * Call a GET method from the Slack API
	 *
	 * @param $methodName string method name to call
	 * @param $args array optional query params
	 *
	 * @return mixed the results of the call, or null
	 */
	function callGet($methodName, $args = null)
	{
		if ($args === null) {
			$args = array();
		}

		$args['token'] = $this->token;

		$raw = file_get_contents(
			SLACK_API.$methodName.'?'.http_build_query($args)
		);

		$json = json_decode($raw, true);

		return $json;
	}

	/**
	 * Fetch and store auth.test info
	 */
	public function getMe()
	{
		$response = $this->callGet('auth.test');
		if ($response['ok']) {
			$this->me = $response;
		} else {
			throw new AuthException('Auth response was not OK');
		}

		return $this->me;
	}

	/**
	 * Fetch and store groups.info
	 *
	 * @param $groupId string group id
	 */

	public function getGroupInfo($groupId)
	{
		$response = $this->callGet('groups.info', ['group' => $groupId]);

		if ($response && $response['ok'] && $response['group']) {
			$this->groups[$groupId] = $response['group'];
		}

		return $this->groups[$groupId];
	}

	/**
	 * Fetch and store channels.info
	 *
	 * @param $channelId string channel id
	 */

	public function getChannelInfo($channelId)
	{
		$response = $this->callGet('channels.info', ['channel' => $channelId]);

		if ($response && $response['ok'] && $response['channel']) {
			$this->channels[$channelId] = $response['channel'];
		}

		return $this->channels[$channelId];
	}

	/**
	 * Call getChannelInfo for all channels
	 */
	public function getAllChannelInfo()
	{
		foreach ($this->channels as $channelId => $channel) {
			$this->getChannelInfo($channelId);
		}
	}

	/**
	 * Calls getgroupInfo for a different group each time it's called
	 *
	 * Used so we can refresh only one group per sample period.
	 */
	public function getNextGroupInfo()
	{
		$i = 0;
		foreach ($this->groups as $groupId => $group) {
			if ($i === $this->groupInfoCounter) {
				$this->getGroupInfo($groupId);
				break;
			}
			$i++;
		}

		$this->groupInfoCounter++;

		if ($this->groupInfoCounter > count($this->groups)) {
			$this->groupInfoCounter = 0;
		}
	}

	/**
	 * Calls getChannelInfo for a different channel each time it's called
	 *
	 * Used so we can refresh only one channel per sample period.
	 */
	public function getNextChannelInfo()
	{
		$i = 0;
		foreach ($this->channels as $channelId => $channel) {
			if ($i === $this->channelInfoCounter) {
				$this->getChannelInfo($channelId);
				break;
			}
			$i++;
		}

		$this->channelInfoCounter++;

		if ($this->channelInfoCounter > count($this->channels)) {
			$this->channelInfoCounter = 0;
		}
	}

	/**
	 * Fetch data from an endpoint, and re-key it into one of the instance
	 * variables in this class.
	 *
	 * This method is used by getChannels, getUsers, etc, to make an API
	 * request and then re-index the array with their object IDs.
	 *
	 * @param $methodName string the method name to call
	 * @param $parameters array query parameters to pass to the API. Required, but empty array OK
	 * @param $localVariable string the name of the local instance variable to put the results into ("channels", "users", etc)
	 * @param $resultVariable string the name of the Slack API response array key to grab results from
	 *
	 * @return the instance variable specified by $localVariable
	 */
	private function _fetchAndStore($methodName, $parameters, $localVariable, $resultVariable)
	{
		$result = $this->callGet($methodName, $parameters);

		$this->{$localVariable} = [];

		foreach ($result[$resultVariable] as $item) {
			$this->{$localVariable}[$item['id']] = $item;
		}

		return $this->{$localVariable};
	}

	/**
	 * Grab all the channels
	 *
	 * Removes channels that you're not a member of from the list. We could
	 * simply hide these channels from display. But it's fewer lines of code to
	 * just remove them completely. And removing them completely means they
	 * don't get refreshed every couple of seconds.
	 */
	public function getChannels()
	{
		$this->_fetchAndStore(
			'channels.list',
			['exclude_archived' => 1],
			'channels',
			'channels'
		);

		foreach ($this->channels as $channelId => $channel) {
			if (isset($channel['is_member']) && $channel['is_member'] === false) {
				unset($this->channels[$channelId]);
			}
		}

		return $this->channels;
	}

	/**
	 * Grab all the users from the API
	 */
	public function getUsers()
	{

		return $this->_fetchAndStore(
			'users.list',
			[],
			'users',
			'members'
		);

	}

	/**
	 * Grab all the groups from the API
	 */
	public function getGroups()
	{
		return $this->_fetchAndStore(
			'groups.list',
			['exclude_archived' => 1],
			'groups',
			'groups'
		);
	}

	/**
	 * Grab all the IMs from the api.
	 *
	 * This method does a little more work to clean up the query results. The
	 * IM objects don't include a 'name' param like the others do, so we fill
	 * it in from the users list for convenience and normalization.
	 */
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

	/**
	 * Find a channel object in our local cache by the channel's name
	 *
	 * Only really used for initializing the client to display "General" on startup.
	 */
	public function getChannelByName($name)
	{

		foreach ($this->channels as $channel) {
			if ($name === $channel['name']) {
				return $channel;
			}
		}

		return null;
	}

	/**
	 * Fetch messages for a channel from the API
	 *
	 * Handles Channels, Groups, and IMs. Stores the messages in our local
	 * cache. Also manages passing the timestamp for the most-recent message
	 * we've seen to the API. We also have to do a little work to make sure we
	 * have the messages in the right order.
	 */
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

	/**
	 * Replace Slack message tokens like <@UXORUER> with their human-readable
	 * counterparts.
	 *
	 * This method will replace user tags and channel tags. See here:
	 * https://api.slack.com/docs/formatting
	 *
	 * @param $messageText string text of the message to format
	 *
	 * @return string ready-to-display message
	 */
	public function formatMessage($messageText) {
		preg_match_all("/<(.*?)>/", $messageText, $matches);

		foreach ($matches[1] as $match) {

			$itemId = substr($match, 1);
			if (($pipePos = strpos($itemId, '|')) !== false) {
				$itemId = substr($itemId, 0, $pipePos);
			}

			$fullMatch = '<'.$match.'>';
			if ('@' === substr($match, 0, 1)) {
				// It's a user.
				if (isset($this->users[$itemId])) {
					$messageText = str_replace(
						$fullMatch,
						'@'.$this->users[$itemId]['name'],
						$messageText
					);
				}
			} else if ('#' === substr($match, 0, 1)) {
				// It's a Channel
				if (isset($this->channels[$itemId])) {
					$messageText = str_replace(
						$fullMatch,
						'#'.$this->channels[$itemId]['name'],
						$messageText
					);
				}
			}
		}

		return $messageText;
	}

}
