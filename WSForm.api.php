<?php
# @Author: Sen-Sai <Charlot>
# @Date:   15-05-2018 -- 10:46:23
# @Last modified by:   Charlot
# @Last modified time: 27-06-2018 -- 13:04:18
# @License: Mine
# @Copyright: 2018



/*
 *    What : WSForm api tasks
 *  Author : Sen-Sai
 *    Date : October 2017
 */
//use \MediawikiApi\Api\ApiUser;
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$currentHost = $_SERVER['HTTP_HOST'];
$referrerHost = parse_url($_SERVER['HTTP_REFERER']);


if ( strcmp($currentHost, $referrerHost['host']) !== 0)
{
    http_response_code(404);
    //include('myCustom404.php'); // provide your own 404 error page
    die('no no no sir'); /* remove this if you want to execute the rest of
              the code inside the file before redirecting. */
}


ERROR_REPORTING(E_ALL);
ini_set('display_errors', 1);

require_once('WSForm.api.class.php');
require_once('classes/recaptcha.class.php');

$i18n = new wsi18n();
$ret = false;

$imageHandler = array();

$imageHandler  = [
    IMAGETYPE_JPEG => [
        'load' => 'imagecreatefromjpeg',
        'save' => 'imagejpeg',
        'quality' => 100
    ],
    IMAGETYPE_PNG => [
        'load' => 'imagecreatefrompng',
        'save' => 'imagepng',
        'quality' => 0
    ],
    IMAGETYPE_GIF => [
        'load' => 'imagecreatefromgif',
        'save' => 'imagegif'
    ]
];

$title = "";

include_once('WSForm.api.include.php');

if( getGetString('version', false) !== false ) {
	echo getVersion();
	exit();
}

if(isset( $_GET['action']) && $_GET['action'] === 'renderWiki' ) {
    $ret = renderWiki();
    header('Content-Type: application/json');
    if (isset($_GET['pp'])) {
        echo json_encode($ret, JSON_PRETTY_PRINT);
    } else {
        echo json_encode($ret);
    }
    exit;
}

if( isset( $_GET['action'] ) && $_GET['action'] === 'handleExternalRequest' ) {
    $external = getGetString('script');
    if($external !== false) {
        // a way to try and keep unwanted requests out (v 0.8.0.1.5)
        if( isset( $_GET['mwdb'] ) && $_GET['mwdb'] !== '' ) {
            $cok = $_GET['mwdb'] . 'UserID';
            if( isset( $_COOKIE[$cok] ) && $_COOKIE[$cok] != "0" ) {
                // ok
            } else die();
        } else die();
        $api = new wbApi();
        if( $api->getStatus() === false ){
	        $ret = createMsg( $api->getStatus( true ) );
        } else {
	        $res = $api->logMeIn();
	        if ( $res === false ) {
		        $ret = createMsg( $res );
	        } else {
		        $IP = $api->app['IP'];
		        if ( file_exists( $IP . '/extensions/WSForm/modules/handlers/' . $external . '.php' ) ) {
			        include_once( $IP . '/extensions/WSForm/modules/handlers/' . $external . '.php' );
		        }
	        }
        }

    } else {
        $ret = createMsg($i18n->wsMessage( 'wsform-external-request-not-found' ) );
    }
    header('Content-Type: application/json');
    if (isset($_GET['pp'])) {
        echo json_encode($ret, JSON_PRETTY_PRINT);
    } else {
        echo json_encode($ret);
    }
    exit;
}

if( isset( $_GET['action'] ) && $_GET['action'] === 'handleQuery' ) {
	$external = getGetString('handler');
	if($external !== false) {
		$extensionsFolder = getcwd()."/modules/handlers/queries/";
		if (file_exists($extensionsFolder . $external . '/query-handler.php')) {
			include_once($extensionsFolder . $external . '/query-handler.php');
		} else {
			$ret = json_encode( createMsg( $i18n->wsMessage( 'wsform-query-handler-not-found' ) ) );
		}
	} else {
		$ret = json_encode( createMsg( $i18n->wsMessage( 'wsform-query-handler-not-found' ) ) );
	}
	header('Content-Type: application/json');
	echo $ret;
	exit;
}


// Setup messages and responses

$identifier = getPostString('mwidentifier');
$pauseBeforeRefresh = getPostString( 'mwpause' );
$messages = new wbHandleResponses($identifier);

// check credentials
$sessInfo = checkDefaultInformation();
if ($sessInfo['mwtoken'] = false ) {
    $messages->doDie( $i18n->wsMessage( 'wsform-session-no-token' ) );
    if ( isset( $_POST['mwreturn'] ) && $_POST['mwreturn'] !== "" ) {
        $messages->redirect($_POST['mwreturn']);
        exit;
    }
}
if ($sessInfo['mwsession'] = false ) {
    $messages->doDie( $i18n->wsMessage( 'wsform-session-expired' ) );
    if ( isset( $_POST['mwreturn'] ) && $_POST['mwreturn'] !== "" ) {
        $messages->redirect($_POST['mwreturn']);
        exit;
    }
}
if ($sessInfo['mwhost'] = false ) {
	$messages->doDie( $i18n->wsMessage( 'wsform-session-no-equal-host' ) );
	if ( isset( $_POST['mwreturn'] ) && $_POST['mwreturn'] !== "" ) {
		$messages->redirect($_POST['mwreturn']);
		exit;
	}
}


if(isset($_FILES['wsformfile'])) {
	if (file_exists($_FILES['wsformfile']['tmp_name']) || is_uploaded_file($_FILES['wsformfile']['tmp_name'])) {
		$res = fileUpload();
		if ($res['status'] == 'error') {
			$messages->doDie(' file : '.$res['msg']);
		}
		$ret = $res; // v0.7.0.3.3 added
	}
}

if( isset($_POST['wsformfile_slim']) ) {
	$ret=fileUploadSlim();
	if (isset($ret['status']) && $ret['status'] === 'error') {
		$messages->doDie( ' slim : '.$ret['msg'] );
	}

}


if( isset( $_POST['wsform_signature'] ) ) {
    $res = signatureUpload();
    if ($res['status'] == 'error') {
        $messages->doDie( ' singature : '.$res['msg'] );
    }
    $ret = $res; // v0.7.0.3.3 added
}

$captchaAction = getPostString( 'mw-captcha-action', false );
$captchaToken = getPostString( 'mw-captcha-token', false );
if( $captchaAction !== false && $captchaToken !== false ) {
    $api = new wbApi();
	if( $api->getStatus() === false ){
		$arr = array();
		$arr['msg'] = $i18n->wsMessage( 'wsform-config-not-found' );
		$arr['status'] = 'error';
		die();
	}
    $retCaptcha = array();
    $returnto = getPostString('mwreturn');
    $retCaptcha['mwreturn'] = $returnto;

    if( $returnto === false ) {
        $retCaptcha['msg'] = 'no return url defined';
        $retCaptcha['status'] = 'error';

        $messages->handleResonse( $retCaptcha );
        die();
    }
    if( $captchaToken === '' || $captchaAction === '' ){
        $retCaptcha['msg'] = 'no captcha details';
        $retCaptcha['status'] = 'error';
        $messages->handleResonse( $retCaptcha );
        die();
    }
    //secret, token, action
    $capClass = new wsform\recaptcha\render();
    $captchaResult = $api->googleSiteVerify($capClass::$rc_secret_key, $captchaToken, $captchaAction );
    if( $captchaResult === false ){
        $retCaptcha['msg'] = 'Your Captcha score is to low. You form is not submitted';
        $retCaptcha['status'] = 'error';
        $messages->handleResonse( $retCaptcha );
        die();
    }
}

if ( getPostString('mwaction') !== false ) {
	$action = getPostString('mwaction');
	unset( $_POST['mwaction'] );

	switch ( $action ) {

		case "addToWiki" :
			$ret = saveToWiki();
 			break;

		case "get" :
			$ret = createGet();
			if ( $ret ) {
				$messages->redirect( $ret );
				exit;
			} else {
				$messages->doDie( $i18n->wsMessage( 'wsform-noreturn-found' ) );
			}
			break;

		case "mail" :
			$ret = saveToWiki(true);
			break;
	}
}


$extension = getPostString('mwextension' );

if( $extension !== false ) {
	$extensionsFolder = getcwd()."/modules/handlers/posts/";
	if( file_exists($extensionsFolder . $extension . '/post-handler.php') ) {
		$usrt = setSummary(true);
		$wsPostFields = setWsPostFields();
		include($extensionsFolder . $extension . '/post-handler.php');
	}
}

//die('testing..');

if( $ret !== false ) {
	$messages->handleResonse( $ret );
} else {
	die( $i18n->wsMessage( 'wsform-norequest-made' ) );
}

if ( isset( $_POST['mwreturn'] ) && $_POST['mwreturn'] !== "" ) {
	$messages->redirect( $_POST['mwreturn'] );
	exit;
} elseif($identifier !== 'ajax') {
    $messages->outputMsg( $i18n->wsMessage( 'wsform-noreturn-found' ) );
}
