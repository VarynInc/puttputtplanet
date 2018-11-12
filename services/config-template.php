<?php /** config.php -- Global app-wide configuration constants for Putt Putt Planet.
 * This file has per-server specific parameters and is not to be checked in for source control.
 * If config.php is not provided then use this file as the guide to setting it up.
 */

define('ENGINESIS_SITE_ID', 110);
define('DEBUG_ACTIVE', false);
define('DEBUG_SESSION', false);
define('PUBLISHING_MASTER_PASSWORD', '');
define('REFRESH_TOKEN_KEY', '');
define('ADMIN_ENCRYPTION_KEY', '');

// memcache access global table
$_MEMCACHE_HOSTS = ['-l'  => array('port'=>11215, 'host'=>'www.puttputtplanet-l.com'),
                    '-d'  => array('port'=>11215, 'host'=>'www.puttputtplanet-d.com'),
                    '-q'  => array('port'=>11215, 'host'=>'www.puttputtplanet-q.com'),
                    '-x'  => array('port'=>11215, 'host'=>'www.puttputtplanet-x.com'),
                    ''    => array('port'=>11215, 'host'=>'www.puttputtplanet.com')
                   ];

// Define a list of email addresses who will get notifications of internal bug reports
$admin_notification_list = ['support@puttputtplanet.com'];

// API Keys for the PuttPuttPlanet app
$socialServiceKeys = [
    2  => ['service' => 'Facebook', 'app_id' => '', 'app_secret' => '', 'admins' =>''],
    7  => ['service' => 'Google', 'app_id' => '', 'app_secret' => '', 'admins' =>''],
    11 => ['service' => 'Twitter', 'app_id' => '', 'app_secret' => '', 'admins' =>'']
];

// Enginesis developer key for the Enginesis APIs
$developerKey = '';
