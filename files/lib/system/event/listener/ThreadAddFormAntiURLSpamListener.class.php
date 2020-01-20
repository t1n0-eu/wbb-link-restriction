<?php
namespace wbb\system\event\listener;
use wcf\system\event\listener\IParameterizedEventListener;
use wcf\util;
use wcf\system\WCF;
use wcf\system\Regex;

/**
 * Disables posts by board newcomers if their posts contain external links
 *
 * @author      Oliver Schlöbe, Marcel Werk, Oliver Kliebisch, Tino Stange
 * @copyright   2001-2009 WoltLab GmbH, 2014-2017 Oliver Schlöbe
 * @license		GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package		WoltLabSuite\Forum\System\Event\Listener
 * @since		5.0
 */
class ThreadAddFormAntiURLSpamListener implements IParameterizedEventListener {
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
	 * list of custom URLs
	 * @var	array<string>
	 */
	protected $customUrls = array();
	
	public $called = false;
	
	/**
	 * @see \wcf\system\event\listener\IParameterizedEventListener::execute()
	 */
	public function execute($eventObj, $className, $eventName, array &$parameters) {
		$actionName = $eventObj->getActionName();
		$parameters = $eventObj->getParameters();
		
		switch ($actionName) {
			case 'triggerPublication':
			case 'update':
				// prevent an recursive loop
				if ($this->called === true) {
					return;
				}
				
				$this->called = true; 
				
				$objects = $eventObj->getObjects();
				if (empty($objects[0])) {
					return;
				}
				
				$data = $objects[0];
				
				// check all disablers
				if ($data->isDisabled || !POST_LINKRESTRICTION_ENABLE
						|| WCF::getSession()->getPermission('user.board.canBypassLinkRestriction')
						|| WCF::getUser()->wbbPosts >= POST_LINKRESTRICTION_MIN_POSTS) {
							return;
						}
						
						if (isset($parameters['data']['message']) && !empty($parameters['data']['message'])) {
							$message = $parameters['data']['message'];
						} else {
							$message = $data->getMessage();
						}
						
						$this->text = $this->parse($message);
						
						if (($this->urlCount > POST_LINKRESTRICTION_MAX_URLS)) {
							$eventObj->disable();
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
		
		// search for urls in text
		preg_match_all('~\<a href(.*)\>(.*)\<\/a\>~isU', $this->text, $matches, PREG_SET_ORDER);
		$count = 0;
		foreach ($matches as $match) {
			$match1 = trim($match[1], '"=\'');
			$match2 = trim($match[2], '"=\'');
			
			if (!\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match1) &&
					!$this->isInternalURLCustom($match1)) {
						$count++;
					}
					
					if (!\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match2) &&
							!$this->isInternalURLCustom($match2)) {
								$count++;
							}
		}
		$this->urlCount = $count;
		
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
