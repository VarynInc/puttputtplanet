<?php
/**
 * Define sensitive data in this configuration file.
 * User: jf
 * Date: Feb-13-2016
 */
date_default_timezone_set('America/New_York');
define('LOGFILE_PREFIX', 'puttputtplanet_php_');
define('SITE_SESSION_COOKIE', 'pppuser');
define('ENGINESIS_SITE_NAME', 'Putt Putt Planet');
define('ENGINESIS_SITE_ID', 110);
define('DEBUG_ACTIVE', true);
define('DEBUG_SESSION', true);
define('PUBLISHING_MASTER_PASSWORD', 'fzEIT~990!24nf9o@enw2f9223n');
define('REFRESH_TOKEN_KEY', '81674D2309EFC5AB81674D23');
define('ADMIN_ENCRYPTION_KEY', '4f50504952474a46');
define('COREG_TOKEN_KEY', 'DEAF39BB95AC1693');
define('ENGINESIS_DEVELOPER_TOKEN', 'DEAF39BB95AC1693');
define('SESSION_REFRESH_HOURS', 4380);     // refresh tokens are good for 6 months
define('SESSION_REFRESH_INTERVAL', 'P6M'); // refresh tokens are good for 6 months
define('SESSION_AUTHTOKEN', 'authtok');
define('SESSION_PARAM_CACHE', 'pppsession_params');

// memcache access global table
$_MEMCACHE_HOSTS = ['-l'  => array('port'=>11215, 'host'=>'www.puttputtplanet-l.com'),
                    '-d'  => array('port'=>11215, 'host'=>'www.puttputtplanet-d.com'),
                    '-q'  => array('port'=>11215, 'host'=>'www.puttputtplanet-q.com'),
                    '-x'  => array('port'=>11215, 'host'=>'www.puttputtplanet-x.com'),
                    ''    => array('port'=>11215, 'host'=>'www.puttputtplanet.com')
                   ];

// Define a list of email addresses who will get notifications of internal bug reports
$admin_notification_list = ['support@puttputtplanet.com', 'support@varyn.com', 'john@varyn.com', 'jlf990@gmail.com'];

// API Keys for the PuttPuttPlanet app
$socialServiceKeys = [
    2  => ['service' => 'Facebook', 'app_id' => '489296364486097', 'app_secret' => 'b3e467c573bf5ebc334a8647a88ddfd6', 'admins' =>''],
    7  => ['service' => 'Google', 'app_id' => '1065156255426-al1fbn6kk4enqfq1f9drn8q1111optvt.apps.googleusercontent.com', 'app_secret' => '10xMn5CfHOVSpH8FWyOqyB5a', 'admins' =>''],
    11 => ['service' => 'Twitter', 'app_id' => 'DNJM5ALaCxE1E2TnpnJtEl2ml', 'app_secret' => 'nErbZceOKAcDZpMFQo1N1x1l7Z71kCSv3esKQDfQyDIZRFltJn', 'admins' =>'']
];
$developerKey = '9A783949224D9629';
$siteId = ENGINESIS_SITE_ID;
$languageCode = 'en';
