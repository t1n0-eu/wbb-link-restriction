<?php
namespace wbb\system\event\listener;
use wcf\system\event\IEventListener;
use wcf\util;
use wcf\system\WCF;

/**
 * Disables posts by board newcomers if their posts contains external links
 *
 * @author      Oliver Schlöbe, Marcel Werk, Oliver Kliebisch
 * @copyright   2001-2009 WoltLab GmbH, 2014 Oliver Schlöbe
 * @license		GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package		com.woltlab.wcf
 * @subpackage	system.event.listener
 * @category	Community Framework
 */
class ThreadAddFormAntiURLSpamListener implements IEventListener {
	private $illegalChars = '[^\x0-\x2C\x2E\x2F\x3A-\x40\x5B-\x60\x7B-\x7F]+';
	private $sourceCodeRegEx = null;

	public $text = '';

	/**
	 * url count in text
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
	 * Stores whether the disabled flasg was set.
	 */
	protected $disabledWasSet = false;
	
	/**
	 * @see	\wcf\system\event\IEventListener::execute()
	 */
	protected $obj = null;

	/**
	 * @see }wcf\system\event\IEventListener::execute()
	 */
	public function execute($obj, $className, $eventName) {		
		$controller = $_GET['controller'];
		$returnValues = $obj->getReturnValues();
		$actionName = $obj->getActionName();
		
		if( in_array($className, array('wbb\data\post\ThreadAction', 'wbb\data\post\PostAction')) ) {
			switch( $actionName ) {
				case 'triggerPublication':
				case 'update':
					$objects = $obj->getObjects();
					if( empty($objects[0]) ) return;
					
					$data = $objects[0];
						
					// check all disablers
					if ( $data->isDisabled || !POST_LINKRESTRICTION_ENABLE
						|| WCF::getSession()->getPermission('user.board.canBypassLinkRestriction')
						|| WCF::getUser()->wbbPosts > POST_LINKRESTRICTION_MIN_POSTS ) return;
						
					// get parsed text
					$text = $this->parse( $data->getMessage() );
						
					if( ($this->urlCount > POST_LINKRESTRICTION_MAX_URLS)
						|| (POST_LINKRESTRICTION_ENABLE_IMAGE_RESTRICTION && $this->imgCount > POST_LINKRESTRICTION_MAX_IMAGES) ) {
						$obj->disable();
					}
					break;
			}
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
			$match1 = trim($match[0], '"=\'');
			$match2 = trim($match[1], '"=\'');
			$match3 = trim($match[2], '"=\'');
			if( !\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match2) &&
				!\wcf\system\application\ApplicationHandler::getInstance()->isInternalURL($match3)
			) $count++;
		}
		$this->urlCount = $count;

		if( POST_LINKRESTRICTION_ENABLE_IMAGE_RESTRICTION ) {
			// search in text for img bbcodes
			// quite primitve at the moment
			preg_match_all('~\[img(.*)\](.*)(\[\/img\])?~isU', $this->text, $matches, PREG_SET_ORDER);
			$this->imgCount = count($matches);
		}

		return $this->text;
	}
}
?>