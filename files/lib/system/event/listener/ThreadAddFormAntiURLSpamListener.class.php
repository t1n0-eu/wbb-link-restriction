<?php
namespace wbb\system\event\listener;
use wcf\system\event\IEventListener;
use wcf\util;
use wcf\system\WCF;
use wcf\system\Regex;

/**
 * Disables posts by board newcomers if their posts contain external links
 *
 * @author      Oliver Schlöbe, Marcel Werk, Oliver Kliebisch
 * @copyright   2001-2009 WoltLab GmbH, 2014-2015 Oliver Schlöbe
 * @license		GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package		com.woltlab.wcf
 * @subpackage	system.event.listener
 * @category	Community Framework
 */
class ThreadAddFormAntiURLSpamListener implements IEventListener {
	private $illegalChars = '[^\x0-\x2C\x2E\x2F\x3A-\x40\x5B-\x60\x7B-\x7F]+';
	private $sourceCodeRegEx = null;

	/**
	 * holds the event object on execution
	 *
	 * @var object
	 */
	protected $obj = null;

	/**
	 * post/thread message
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * external url count in text
	 *
	 * @var integer
	 */
	protected $urlCount = 0;

	/**
	 * image count in text
	 *
	 * @var integer
	 */
	protected $imgCount = 0;

	/**
	 * list of custom URLs
	 * @var	array<string>
	 */
	protected $customUrls = array();


	/**
	 * @see \wcf\system\event\IEventListener::execute()
	 */
	public function execute($obj, $className, $eventName) {
		$actionName = $obj->getActionName();
		$parameters = $obj->getParameters();

		switch ($actionName) {
			case 'triggerPublication':
			case 'update':
				$objects = $obj->getObjects();
				if (empty($objects[0])) {
					return;
				}

				$data = $objects[0];
				
				// check all disablers
				if ($data->isDisabled || !POST_LINKRESTRICTION_ENABLE
					|| WCF::getSession()->getPermission('user.board.canBypassLinkRestriction')
					|| WCF::getUser()->wbbPosts > POST_LINKRESTRICTION_MIN_POSTS) {
					return;
				}

				if (isset($parameters['data']['message']) && !empty($parameters['data']['message'])) {
					$message = $parameters['data']['message'];
				} else {
					$message = $data->getMessage();
				}

				$this->text = $this->parse($message);

				if (($this->urlCount > POST_LINKRESTRICTION_MAX_URLS)
					|| (POST_LINKRESTRICTION_ENABLE_IMAGE_RESTRICTION && $this->imgCount > POST_LINKRESTRICTION_MAX_IMAGES)) {
					$obj->disable();
				}
				break;
		}
	}

	/**
	 * Slightly modified parse method
	 *
	 * @see URLParser::parse()
	 */
	public function parse($text) {
		$this->text = $text;

		// define pattern
		$urlPattern = '~(?<!\B|"|\'|=|/|\]|,|\?)
			(?:						# hostname
				(?:ftp|https?)://'.$this->illegalChars.'(?:\.'.$this->illegalChars.')*
				|
				www\.(?:'.$this->illegalChars.'\.)+
				(?:[a-z]{2,4}(?=\b))
			)

			(?::\d+)?					# port

			(?:
				/
				[^!.,?;"\'<>()\[\]{}\s]*
				(?:
					[!.,?;(){}]+ [^!.,?;"\'<>()\[\]{}\s]+
				)*
			)?
			~ix';

		// add url tags
		$this->text = preg_replace($urlPattern, '[url]\\0[/url]', $this->text);

		// search in text w/o code bbcodes for urls
		preg_match_all('~\[url(.*)\](.*)\[\/url\]~isU', $this->text, $matches, PREG_SET_ORDER);
		$count = 0;
		foreach ($matches as $match) {
			$match1 = trim($match[1], '"=\'');
			$match2 = trim($match[2], '"=\'');
			if (!\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match1) &&
				!$this->isInternalURLCustom($match1) &&
				!\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match2) &&
				!$this->isInternalURLCustom($match2)) {
				$count++;
			}
		}
		$this->urlCount = $count;

		if (POST_LINKRESTRICTION_ENABLE_IMAGE_RESTRICTION) {
			// search in text for img bbcodes
			// quite primitve at the moment
			preg_match_all('~\[img(.*)\](.*)(\[\/img\])?~isU', $this->text, $matches, PREG_SET_ORDER);
			$this->imgCount = count($matches);
		}

		return $this->text;
	}

	/**
	 * Additional custom URL checks
	 */
	private function isInternalURLCustom($url) {
		$this->customUrls = $this->getCustomURLS();
		$protocolRegex = new Regex('^https(?=://)');
		
		if( count($this->customUrls) > 0 ) {
			foreach ($this->customUrls as $pageURL) {
				if (stripos($protocolRegex->replace($url, 'http'), $pageURL) === 0) {
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Get custom URLs from options
	 */
	private function getCustomURLS() {
		$customURLs = explode(',', POST_LINKRESTRICTION_CUSTOM_URLS);
		$customURLs = array_map(array($this, 'addHttpToCustomURLs'), $customURLs);
		return $customURLs;
	}
	
	/**
	 * Auto add http scheme if it's not present
	 */
	function addHttpToCustomURLs($url) {
		if (!empty($url) && $url!='' && !preg_match("~^(?:f|ht)tps?://~i", $url)) {
			$url = "http://".$url;
		}
		return $url;
	}
}