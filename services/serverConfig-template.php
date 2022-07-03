<?php
/**
 * Define sensitive data in this configuration file. If serverConfig.php is missing, then it should
 * be setup like this.
 * User: jf
 * Date: Feb-13-2016
 */
date_default_timezone_set('America/New_York');
define('LOGFILE_PREFIX', 'enginesis');
define('SITE_SESSION_COOKIE', 'enguser');
define('ENGINESIS_SITE_NAME', 'Enginesis');
define('ENGINESIS_SITE_ID', 100);
define('ENGINESIS_SITE_DOMAIN', 'enginesis.com');
define('DEBUG_ACTIVE', false);
define('DEBUG_SESSION', false);
define('PUBLISHING_MASTER_PASSWORD', '');
define('REFRESH_TOKEN_KEY', '');
define('ADMIN_ENCRYPTION_KEY', '');
define('COREG_TOKEN_KEY', '');
define('ENGINESIS_DEVELOPER_API_KEY', '');
define('ENGINESIS_CMS_API_KEY', '');
define('SESSION_REFRESH_HOURS', 4380);     // refresh tokens are good for 6 months
define('SESSION_REFRESH_INTERVAL', 'P6M'); // refresh tokens are good for 6 months
define('SESSION_AUTHTOKEN', 'authtok');
define('SESSION_PARAM_CACHE', 'engsession_params');

// memcache access global table
$_MEMCACHE_HOSTS = ['-l'  => array('port'=>11215, 'host'=>'www.enginesis-l.com'),
                    '-d'  => array('port'=>11215, 'host'=>'www.enginesis-d.com'),
                    '-q'  => array('port'=>11215, 'host'=>'www.enginesis-q.com'),
                    '-x'  => array('port'=>11215, 'host'=>'www.enginesis-x.com'),
                    ''    => array('port'=>11215, 'host'=>'www.enginesis.com')
                   ];

// Define a list of email addresses who will get notifications of internal bug reports
$admin_notification_list = ['support@enginesis.com'];

// API Keys for the website app
$socialServiceKeys = [
    2  => ['service' => 'Facebook', 'app_id' => '', 'app_secret' => '', 'admins' =>''],
    7  => ['service' => 'Google', 'app_id' => '', 'app_secret' => '', 'admins' =>''],
    11 => ['service' => 'Twitter', 'app_id' => '', 'app_secret' => '', 'admins' =>'']
];
$developerKey = '';
$siteId = ENGINESIS_SITE_ID;
$languageCode = 'en';
