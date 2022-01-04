<?php

namespace WSForm\Processors\Content;

use MWException;
use RequestContext;
use WSForm\Core\Config;
use WSForm\Core\HandleResponse;
use WSForm\Processors\Security\wsSecurity;
use WSForm\Processors\Definitions;
use WSForm\Processors\Utilities\General;
use WSForm\Processors\Files\FilesCore;
use WSForm\WSFormException;

/**
 * Class core
 *
 * @package WSForm\Processors\Content
 */
class ContentCore {

	private static $fields = array();

	/**
	 * @return array
	 */
	public static function getFields(): array {
		return self::$fields;
	}

	/**
	 * Experimental function to get a username from session
	 *
	 * @param bool $onlyName
	 * @return string
	 */
	private static function setSummary( bool $onlyName = false ): string {
		$user = RequestContext::getMain()->getUser();
		if( $user->isAnon() === false ) {
			if( $onlyName === true ) {
				return ( $user->getName() );
			} else {
				return ( '[[User:' . $user->getName() . ']]' );
			}
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
			return ('Anon user: ' . $ip);
		}
	}

	private static function checkFields(){
		if( self::$fields['summary'] === false ) {
			self::$fields['summary'] = self::setSummary();
		}

		if( isset( $_POST['mwleadingzero'] ) ) {
			self::$fields['leadByZero'] = true;
		}

		if( self::$fields['parsePost'] !== false && is_array( self::$fields['parsePost'] ) ) {
			$filesCore = new FilesCore();
			foreach ( self::$fields['parsePost'] as $pp ) {
				$pp = General::makeUnderscoreFromSpace( $pp );
				if( isset( $_POST[$pp] ) ) {
					$_POST[$pp] = $filesCore->parseTitle( $_POST[$pp] );
				}
			}
		}
	}

	/**
	 * @param HandleResponse $response_handler
	 *
	 * @return HandleResponse
	 * @throws MWException
	 * @throws WSFormException
	 */
	public function saveToWiki( HandleResponse $response_handler ): HandleResponse {
		self::$fields = Definitions::createAndEditFields();
		/*
		'parsePost'    => General::getPostString( 'wsparsepost' ),
		'parseLast'    => General::getPostString( 'mwparselast' ),
		'etoken'       => General::getPostString( 'wsedittoken' ),
		'template'     => General::getPostString( 'mwtemplate' ),
		'writepage'    => General::getPostString( 'mwwrite' ),
		'option'       => General::getPostString( 'mwoption' ),
		'returnto'     => General::getPostString( 'mwreturn', false ),
		'returnfalse'  => General::getPostString( 'mwreturnfalse' ),
		'mwedit'       => General::getPostArray( 'mwedit' ),
		'writepages'   => General::getPostArray( 'mwcreatemultiple' ),
		'msgOnSuccess' => General::getPostString( 'mwonsuccess' ),
		'mwfollow'     => General::getPostString( 'mwfollow' ),
		'leadByZero'   => false,
		'summary'      => General::getPostString( 'mwwikicomment' ),
		'slot'		   => General::getPostString( 'mwslot' )
		*/

		self::checkFields();

		/*
		if( self::$fields['returnto'] === false && self::$fields['returnfalse'] === false ) {
			return wbHandleResponses::createMsg('no return url defined','error', self::$fields['returnto'] );
		}
		*/


		// WSCreate single
		if ( self::$fields['template'] !== false && self::$fields['writepage'] !== false ) {
			$create = new create();
			try {
				$result = $create->writePage();
			} catch ( WSFormException $e ) {
				throw new WSFormException( $e->getMessage(), 0, $e );
			}
			if( false === self::$fields['slot'] ) {
				$slot = "main";
			} else $slot = self::$fields['slot'];
			$result['content'] = self::createSlotArray( $slot, $result['content'] );
			$save = new Save();
			try {
				$save->saveToWiki(
					$result['title'],
					$result['content'],
					self::$fields['summary']
				);
			} catch ( WSFormException $e ) {
				throw new WSFormException( $e->getMessage(), 0, $e );
			}
			self::checkFollowPage( $result['title'] );
			if( ! self::$fields['mwedit'] && ! self::$fields['email'] && ! self::$fields['writepages'] ) {
				$response_handler->setMwReturn( self::$fields['returnto'] );
				$response_handler->setReturnType( HandleResponse::TYPE_SUCCESS );
				if( self::$fields['msgOnSuccess'] !== false ) {
					$response_handler->setReturnData( self::$fields['msgOnSuccess'] );
				}

				return $response_handler;
			}
		}

		// We need to do multiple edits
		if ( self::$fields['writepages'] !== false  ) {

		}
		return $response_handler;
	}

	/**
	 * Check if we need to change to returnto url to return to newly created page.
	 * @param string $title
	 *
	 * @return void
	 */
	private static function checkFollowPage( $title ):void {
		$serverUrl = wfGetServerUrl( null ) . '/' . 'index.php';
		if( self::$fields['mwfollow'] !== false ) {
			if( self::$fields['mwfollow'] === 'true' ) {

				self::$fields['returnto'] = $serverUrl . '/' . $title;
			} else {
				if( strpos( self::$fields['returnto'], '?' ) ) {
					self::$fields['returnto'] = self::$fields['returnto'] . '&' . self::$fields['mwfollow'] . '=' . $title;
				} else {
					self::$fields['returnto'] = self::$fields['returnto'] . '?' . self::$fields['mwfollow'] . '=' . $title;
				}
			}
		}
	}

	/**
	 * @param string $slot
	 * @param string $value
	 *
	 * @return array
	 */
	private static function createSlotArray( string $slot, string $value ): array{
		return array( $slot => $value );
	}

	/**
	 * Create content
	 * @return string
	 */
	public static function createContent(): string {
		$ret = '';
		$noTemplate = false;

		if( self::$fields['template'] === strtolower( 'wsnone' ) ) {
			$noTemplate = true;
		}
		if( !$noTemplate ) {
			$ret = "{{" . self::$fields['template'] . "\n";
		}
		foreach ( $_POST as $k => $v ) {
			if ( is_array( $v ) && !Definitions::isWSFormSystemField( $k ) ) {
				$ret .= "|" . General::makeSpaceFromUnderscore( $k ) . "=";
				foreach ( $v as $multiple ) {
					$ret .= wsSecurity::cleanBraces( $multiple ) . ',';
				}
				$ret = rtrim( $ret, ',' ) . PHP_EOL;
			} else {
				if ( !Definitions::isWSFormSystemField( $k ) && $v != "" ) {
					if( !$noTemplate ) {
						$ret .= '|' . General::makeSpaceFromUnderscore( $k ) . '=' . wsSecurity::cleanBraces( $v ) . "\n";
					} else {
						$ret = $v . PHP_EOL;
					}
				}
			}
		}
		if( !$noTemplate ) {
			$ret .= "}}";
		}
		return $ret;
	}

	/**
	 * @return int
	 */
	public static function createRandom(): int {
		return time();
	}

	public static function parseTitle( $title ) {
		$tmp = General::get_all_string_between( $title, '[', ']' );
		foreach ( $tmp as $fieldname ) {
			if( isset( $_POST[General::makeUnderscoreFromSpace($fieldname)] ) ) {
				$fn = $_POST[General::makeUnderscoreFromSpace($fieldname)];
				if( is_array( $fn ) ) {
					$imp = implode( ', ', $fn );
					$title = str_replace('[' . $fieldname . ']', $imp, $title);
				} elseif ( $fn !== '' ) {
					if( Config::getConfigVariable( 'create-seo-titles' ) === true ) {
						$fn = self::urlToSEO( $fn );
					}
					$title = str_replace('[' . $fieldname . ']', $fn, $title);
				} else {
					$title = str_replace('[' . $fieldname . ']', '', $title);
				}
			} else {
				$title = str_replace('[' . $fieldname . ']', '', $title);
			}
			if( $fieldname == 'mwrandom' ) {
				$title = str_replace( '['.$fieldname.']', MakeTitle(), $title );
			}
		}
		return $title;
	}

	/**
	 * @param $string
	 *
	 * @return string
	 */
	public static function urlToSEO( $string ) : string {
		$separator = '-';
		$accents_regex = '~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i';
		$special_cases = array(
			'&' => 'and',
			"'" => ''
		);
		$string = mb_strtolower(
			trim( $string ),
			'UTF-8'
		);
		$string = str_replace(
			array_keys( $special_cases ),
			array_values( $special_cases ),
			$string
		);
		$string = preg_replace(
			$accents_regex,
			'$1',
			htmlentities(
				$string,
				ENT_QUOTES,
				'UTF-8'
			)
		);
		$string = preg_replace(
			"/[^a-z0-9]/u",
			"$separator",
			$string
		);
		$string = preg_replace(
			"/[$separator]+/u",
			"$separator",
			$string
		);

		return trim( $string, '-' );
	}

	/**
	 * @param $nameStartsWith
	 *
	 * @return array|string[]
	 * @throws MWException
	 */
	public static function getNextAvailable( $nameStartsWith ) : array {
		$render   = new Render();
		$postdata = [
			"action"          => "wsform",
			"format"          => "json",
			"what"            => "nextAvailable",
			"titleStartsWith" => $nameStartsWith
		];
		$result = $render->makeRequest( $postdata );
		if( isset( $result['received']['wsform']['error'] ) ) {
			return(array('status' => 'error', 'message' => $result['received']['wsform']['error']['message']));
		} elseif ( isset( $result['received']['error'] ) ) {
			return(array('status' => 'error', 'message' => $result['received']['error']['code'] . ': ' .
														   $result['received']['error']['info'] ) );
		} else {
			return(array('status' => 'ok', 'result' => $result['received']['wsform']['result']));
		}
		die();
	}

	/**
	 * @param $nameStartsWith
	 * @param $range
	 *
	 * @return array
	 */
	public static function getFromRange( $nameStartsWith, $range ){
		$postdata = [
			 "action" => "wsform",
			 "format" => "json",
			 "what" => "getRange",
			 "titleStartsWith" => $nameStartsWith,
			 "range" => $range
		 ];
		$render = new Render();
		$result = $render->makeRequest( $postdata );

		if( isset( $result['received']['wsform']['error'] ) ) {
			return(array('status' => 'error', 'message' => $result['received']['wsform']['error']['message']));
		} else {
			return(array('status' => 'ok', 'result' => $result['received']['wsform']['result']));
		}
		die();
	}




}