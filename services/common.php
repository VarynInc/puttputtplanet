<?php // Enginesis common PHP functions. Usually include this or commonAdmin in any Enginesis PHP class or file.

/**
 * Turn on or off the PHP level error reporting. Typically we want this on for all servers except production.
 * @param $setFlag true to enable PHP error reporting, false to disable it.
 */
function setErrorReporting ($setFlag) {
    if ($setFlag) {
        error_reporting(E_ALL);
        ini_set('error_reporting', E_ALL);
        ini_set("display_errors", 'On');
        ini_set('html_errors', 'On');
    } else {
        error_reporting(0);
        ini_set('error_reporting', 0);
        ini_set("display_errors", 'Off');
        ini_set('html_errors', 'Off');
    }
}
setErrorReporting(true);
require_once('LogMessage.php');
$enginesisLogger = new LogMessage([
    'log_active' => true,
    'log_level' => LogMessageLevel::All,
    'log_to_output' => false,
    'log_to_file' => true
]);

date_default_timezone_set('America/New_York');
define('LOGFILE_PREFIX', 'puttputtplanet_php_');
define('SERVICES_REPLY_ENCRYPTED', false);
define('COREG_TOKEN_KEY', 'DEAF39BB95AC1693');

// determines where there is a secured writable area we can manipulate file storage
if (isset($_SERVER['DOCUMENT_ROOT']) && strlen($_SERVER['DOCUMENT_ROOT']) > 0) {
    $serverRootPath = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR;
} else {
    $serverRootPath = '';
}
define('SERVER_ROOT', $serverRootPath);
define('SERVER_DATA_PATH', $serverRootPath . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR);
define('SERVICE_ROOT', $serverRootPath . '..' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR);

// access to our session data
define('SESSION_DAYSTAMP_HOURS', 48);      // access tokens are good for 48 hours
define('SESSION_REFRESH_HOURS', 4380);     // refresh tokens are good for 6 months
define('SESSION_REFRESH_INTERVAL', 'P6M'); // refresh tokens are good for 6 months
define('SESSION_COOKIE', 'pppsession');
define('REFRESH_COOKIE', 'ppprefreshtoken');
define('SESSION_HASH', 'cr');
define('SESSION_AUTHTOKEN', 'authtok');
define('SESSION_ADMIN_COOKIE', 'ppp_admin');
define('SESSION_USERID_CACHE', 'ppp_userid');
define('SESSION_USERNAME_CACHE', 'ppp_username');
define('SESSION_PARAM_CACHE', 'pppsession_params');
define('SESSION_USERINFO', 'pppsession_user');
define('TIME_PERIOD_ORPHAN', 1); // time period to delete orphan upload files (in days)
define('TIME_PERIOD_LAST_ACCESS', 1); // time period to delete upload files based on last access date (in months)
define('CDN_CACHE_TYPE', 'Akamai'); // for Content Delivery Network cache control
define('ACTIVE_DATABASE', 'enginesis'); // constants for database server access
$serverName = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'puttputtplanet-l.com';
$serverStage = '';
preg_match('/-[d|l|q|x]./i', $serverName, $matched); // match any host that ends with -l., -d., -q., -x. if none of these assume a live stage
if (count($matched) > 0) {
    $serverStage = strtolower(str_replace('.', '', $matched[0]));
}
setErrorReporting($serverStage != ''); // do not report errors on the Live server

require_once('version.php');
require_once('config.php');

$dbConnectionTable = [];
$lastDBConnectionName = null;

// ===============================================================================================
//	Error logging and debugging functions. Note we are moving this to LogMessage/$enginesisLogger.
// ===============================================================================================
/**
 * This function would determine how to handle an error based on context and server.
 * @param string $msg A message to report.
 * @param string $file The file name that generated the report.
 * @param int $line The line on $file that generated the report.
 * @param string $fn The function name that generated the report.
 * @return string The message that was logged.
 */
function reportError ($msg, $file = '', $line = 0, $fn = '') {
    global $enginesisLogger;

    if (strlen($file) == 0) {
        $file = __FILE__;
    }
    if ($line < 1) {
        $line = __LINE__;
    }
    if (strlen($fn) > 0) {
        $msg = "$fn | " . $msg;
    }
    $enginesisLogger->log($msg, LogMessageLevel::Error, 'System', $file, $line);
    return $msg;
}

function dieIfNotLive ($msg) {
    global $enginesisLogger;
    $enginesisLogger->log("dieIfNotLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
    if ( ! isLive()) {
        echo $msg;
        exit;
    }
}

function dieIfLive ($msg) {
    global $enginesisLogger;
    $enginesisLogger->log("dieIfLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
    if (isLive()) {
        echo $msg;
        exit;
    }
}

/**
 * Return the name of the page we are currently on.
 * @return string
 */
function currentPageName() {
    return basename($_SERVER['PHP_SELF'], '.php');
}

/**
 * Copy a key/value in the source array to the target if it does not already exist in the target array. Use the
 * force parameter to force the copy and overwrite the target value.
 * @param $source Array The source array to copy a key/value from.
 * @param $target Array the target array to copy the key/value to.
 * @param $key String The key to copy.
 * @param bool $force Set to true to force the value to the target if it exists or not.
 * @return bool true if a copy was done, false if no copy was done.
 */
function copyArrayKey($source, & $target, $key, $force = false) {
    $copied = false;
    if ( ! isset($target[$key]) && isset($source[$key])) {
        $target[$key] = $source[$key];
        $copied = true;
    } elseif (isset($source[$key]) && $force) {
        $target[$key] = $source[$key];
        $copied = true;
    }
    return $copied;
}

// =================================================================
// HTTP and client/server helper functions
// =================================================================

function encodeURLParams ($parameters) {
    $encodedURLParams = '';
    foreach ($parameters as $key => $value) {
        if ($encodedURLParams != '') {
            $encodedURLParams .= '&';
        }
        $encodedURLParams .= urlencode($key) . '=' . urlencode($value);
    }
    return $encodedURLParams;
}

function decodeURLParams ($encodedURLParams) {
    $parameters = [];
    $urlParams = explode('&', $encodedURLParams);
    $i = 0;
    while ($i < count($urlParams)) {
        $equalsPos = strpos($urlParams[$i], '=');
        if ($equalsPos > 0) {
            $itemKey = substr($urlParams[$i], 0, $equalsPos);
            $itemVal = substr($urlParams[$i], $equalsPos + 1, strlen($urlParams[$i]) - $equalsPos);
            $parameters[urldecode($itemKey)] = urldecode($itemVal);
        }
        $i ++;
    }
    return $parameters;
}

function saveQueryString ($parameters = null) {
    if ($parameters == null) {
        $parameters = $_GET;
    }
    return encodeURLParams($parameters);
}

function cleanXmlEntities ($string) {
    return str_replace(['&', '"', "'", '<', '>'], ['&amp;', '&quot;', '&apos;', '&lt;', '&gt;'], $string);
}

function getServiceProtocol () {
    // return http or https. you should use the result of this and never hard-code http:// into any URLs.
    if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        $protocol = 'https';
    } else {
        $protocol = 'http';
    }
    return $protocol;
}

/**
 * Return a variable that was posted from a form, or in the REQUEST object (GET or COOKIES), or a default if not found.
 * This way POST is the primary concern but if not found will fallback to the other methods.
 * @param $varName {string|Array} variable to read from request. If array, iterates array of strings until the first entry returns a result.
 * @param null $defaultValue
 * @return null
 */
function getPostOrRequestVar ($varName, $defaultValue = NULL) {
    $value = null;
    if (is_array($varName)) {
        for ($i = 0; $i < count($varName); $i ++) {
            $value = getPostOrRequestVar($varName[$i], null);
            if ($value != null) {
                break;
            }
        }
        if ($value == null) {
            $value = $defaultValue;
        }
    } else {
        if (isset($_POST[$varName])) {
            $value = $_POST[$varName];
        } elseif (isset($_GET[$varName])) {
            $value = $_GET[$varName];
        } elseif (isset($_REQUEST[$varName])) {
            $value = $_REQUEST[$varName];
        } else {
            $value = $defaultValue;
        }
    }
    return $value;
}

/**
 * Return a variable that was posted from a form, or a default if not found.
 * @param $varName
 * @param null $defaultValue
 * @return null
 */
function getPostVar ($varName, $defaultValue = NULL) {
    return isset($_POST[$varName]) ? $_POST[$varName] : $defaultValue;
}

/**
 * The blowfish encryption algorithm requires data length is a multiple of 8 bytes. This
 * function pads the string to the nearest 8 byte boundary.
 */
function blowfishPad ($text) {
    $imod = 8 - (strlen($text) % 8);
    for ($i = 0; $i < $imod; $i ++) {
        $text .= chr($imod);
    }
    return $text;
}

/**
 * After blowfish decryption, remove any padding that was applied to the original data.
 */
function blowfishUnpad ($text) {
    $textLen = strlen($text);
    if ($textLen > 0) {
        $padLen = ord($text[$textLen - 1]);
        if ($padLen > 0 && $padLen <= 8) {
            return substr($text, 0, $textLen - $padLen);
        }
    }
    return $text;
}

/**
 * Replace base64 chars that are not URL safe.
 */
function base64URLDecode($data) {
    return base64_decode(strtr($data, ['-' => '+', '_' => '/', '~' => '='])); // '-_~', '+/='));
}

/**
 * Replace base64 chars that are not URL safe.
 */
function base64URLEncode($data) {
    return strtr(base64_encode($data), ['+' => '-', '/' => '_', '=' => '~']); // '+/=', '-_~');
}

/**
 * Encrypt a string of data with a key.
 * @param $data {string} A clear string of data to encrypt.
 * @param $key {string} The encryption key, represented as a hex string.
 * @return {string} a base-64 representation of the encrypted data.
 */
function encryptString($data, $key) {
    $keyLength = strlen($key);
    if ($keyLength < 16) {
        $key = str_repeat($key, ceil(16/$keyLength));
    }
    return base64URLEncode(openssl_encrypt(blowfishPad($data), 'BF-ECB', pack('H*', $key), OPENSSL_RAW_DATA | OPENSSL_NO_PADDING));

    // return base64URLEncode(mcrypt_encrypt(MCRYPT_BLOWFISH, pack('H*', $key), blowfishPad($data), MCRYPT_MODE_ECB, pack('H*', '0000000000000000')));
}

/**
 * Decrypt a string of data that was encrypted with `encryptString()` using the same key.
 * @param $data {string} An encrypted string of data to decrypt.
 * @param $key {string} The encryption key, represented as a hex string.
 * @return {string} the clear string that was originally encrypted.
 */
function decryptString($data, $key) {
    $keyLength = strlen($key);
    if ($keyLength < 16) {
        $key = str_repeat($key, ceil(16/$keyLength));
    }
    return blowfishUnpad(openssl_decrypt(base64URLDecode($data), 'BF-ECB', pack('H*', $key), OPENSSL_RAW_DATA | OPENSSL_NO_PADDING));

    // return blowfishUnpad(mcrypt_decrypt(MCRYPT_BLOWFISH, pack('H*', $key), base64URLDecode($data), MCRYPT_MODE_ECB, pack('H*', '0000000000000000')));
}

/**
 * String obfuscator takes an input string and xor's it with a key. Call with a clear string to obfuscate, then
 * call again with the obfuscated string and the same key to return the original string.
 * @param $string
 * @param $key
 * @return string
 */
function xorString($string, $key) {
    $xorString = '';
    $stringLength = strlen($string);
    $keyLength = strlen($key);
    for ($i = 0; $i < $stringLength; $i ++) {
        $xorString .= $string[$i] ^ $key[$i % $keyLength];
    }
    return $xorString;
}

/**
 * Call this function to generate a password hash to save in the database instead of the password.
 * Generate random salt, can only be used with the exact password match.
 * This calls PHP's crypt function with the specific setup for blowfish. mcrypt is a required PHP module.
 * @param string the user's password
 * @returns string the hashed password.
 */
function hashPassword ($password) {
    $chars = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $salt = '$2a$10$';
    for ($i = 0; $i < 22; $i ++) {
        $salt .= $chars[mt_rand(0, 63)];
    }
    return crypt($password, $salt);
}

/**
 * Test a password and the user's stored hash of that password
 * @param string the user's password
 * @param string the password we looked up in the database
 * @returns bool true if the password is a match. false if password does not match.
 */
function verifyHashPassword ($password, $hashStoredInDatabase) {
    return ! empty($password) && ! empty($hashStoredInDatabase) && $hashStoredInDatabase == crypt($password, $hashStoredInDatabase);
}

/**
 * Get any web page on the WWW and return its contents as a string
 * @param string is the URL to contact without any query string (use $get_params)
 * @param array GET parameters are key => value arrays
 * @param array POST parameters as a key => value array.
 * @returns string the web page content as a string.
 */
function getURLContents ($url, $get_params = null, $post_params = null) {
    $post_string = '';
    if ($get_params != null) {
        $query_string = '';
        foreach ($get_params as $var => $value) {
            $query_string .= ($query_string == '' ? '' : '&') . urlencode($var) . '=' . urlencode($value);
        }
        if ($query_string != '') {
            $url .= '?' . $query_string;
        }
    }
    if ($post_params != null) {
        foreach ($post_params as $var => $value) {
            $post_string .= ($post_string == '' ? '' : '&') . urlencode($var) . '=' . urlencode($value);
        }
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if ($post_string != '') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
    }
    $page = curl_exec($ch);
    curl_close($ch);
    return $page;
}

/**
 * Make an Enginesis API request over HTTP using cURL.
 * @param $fn string the API to call
 * @param $serverURL string is the URL to contact without any query string (use $paramArray)
 * @param $paramArray array key => value array of parameters e.g. array('site_id' => 100);
 * @return array|mixed|string response from server or null if failed.
 * TODO: This needs to handle HTTPS.
 */
function callEnginesisAPI ($fn, $serverURL, $paramArray) {
    if ( ! isset($paramArray['response'])) {
        $paramArray['response'] = 'json';
    }
    $response = $paramArray['response'];
    if ( ! isset($paramArray['state_seq'])) {
        $paramArray['state_seq'] = 1;
    }
    if ( ! isset($paramArray['fn'])) {
        $paramArray['fn'] = $fn;
    }
    $ch = curl_init();
    if ($ch) {
        curl_setopt($ch, CURLOPT_URL, $serverURL);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
        curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, encodeURLParams($paramArray));
        $contents = curl_exec($ch);
        curl_close($ch);
        $succeeded = strlen($contents) > 0;
        // TODO: We should verify the response is a valid EnginesisReponse object
        if ( ! $succeeded) {
            $contents = makeErrorResponse('SYSTEM_ERROR', 'System error: ' . $serverURL . ' replied with no data.', $response, $paramArray);
        }
    } else {
        $contents = makeErrorResponse('SYSTEM_ERROR', 'System error: unable to contact ' . $serverURL . ' or the server did not respond.', $response, $paramArray);
    }
    return $contents;
}

// =================================================================
// Server identity crisis helpers
// =================================================================

/**
 * Verify the sever stage we are running on is sufficient to run Enginesis. There are a set of required
 * modules we need in order for the platform to operate. This function returns an array of either only
 * the failed tests, or the status of all tests.
 * @param $includePassedTests boolean set to false to return only failed tests, set to true to return
 *        both failed tests and passed tests. default is false.
 * @return array a key value array where the key is the test performed and the value is a boolean
 *        indicating the test passed (true) or the test failed (false).
 */
function verifyStage($includePassedTests = false) {
    global $enginesisLogger;
    $testStatus = [];

    // Test for required PHP version
    $test = 'php-version';
    $isValid = version_compare(phpversion(), '7.2.0', '>=');
    if ( ! $isValid || ($isValid && $includePassedTests)) {
        $testStatus[$test] = $isValid;
    }

    // Test for required modules/extensions
    $requiredExtensions = ['openssl', 'curl', 'json', 'gd', 'PDO', 'pdo_mysql'];
    $extensions = get_loaded_extensions();
    foreach($requiredExtensions as $i => $test) {
        $isValid = in_array($test, $extensions);
        if ( ! $isValid || ($isValid && $includePassedTests)) {
            $testStatus[$test] = $isValid;
        }
    }

    // Test for required gd support
    $test = 'gd';
    $isValid = function_exists('gd_info');
    if ($isValid) {
        $gdInfo = gd_info();
        $test = 'gd-jpg';
        $isValid = $gdInfo['JPEG Support'];
        if ( ! $isValid || ($isValid && $includePassedTests)) {
            $testStatus[$test] = $isValid;
        }
        $test = 'gd-png';
        $isValid = $gdInfo['PNG Support'];
        if ( ! $isValid || ($isValid && $includePassedTests)) {
            $testStatus[$test] = $isValid;
        }
    } else {
        $testStatus[$test] = $isValid;
    }

    // test for required openssl support
    $test = 'openssl';
    $isValid = function_exists('openssl_encrypt') && function_exists('openssl_get_cipher_methods');
    if ( ! $isValid || ($isValid && $includePassedTests)) {
        $testStatus[$test] = $isValid;
    }

    // Verify we have the right version of openssl
    $test = 'openssl-version';
    $openSSLMinVersion = 9470367;
    $isValid = OPENSSL_VERSION_NUMBER >= $openSSLMinVersion;
    if ( ! $isValid || ($isValid && $includePassedTests)) {
        $testStatus[$test] = $isValid;
    }

    // verify Logger is working
    $test = 'logger';
    $enginesisLogger->log("Validating stage", LogMessageLevel::Info, 'Sys', __FILE__, __LINE__);
    $isValid = $enginesisLogger->isValid();
    if ( ! $isValid || ($isValid && $includePassedTests)) {
        $testStatus[$test] = $isValid;
    }
    return $testStatus;
}

/**
 * Return the host name of the server we are running o. e.g. www.enginesis-q.com
 * @return string
 */
function serverName () {
    $serverName = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'enginesis-l.com';
    if (strpos($serverName, ':') !== false ) {
        $serverName = substr($serverName, 0, strpos($serverName, ':'));
    }
    return $serverName;
}

/**
 * Return the domain name and TLD only (remove server name, protocol, anything else) e.g. this function
 * converts http://www.games.com into games.com or http://www.games-q.com into games-q.com
 * @param null $serverName
 * @return null|string
 */
function serverTail ($serverName = null) {
    $domain = '';
    $tld = '';
    if (strlen($serverName) == 0) {
        $serverName = serverName();
    }
    if ($serverName != 'localhost') {
        $urlParts = explode('.', $serverName);
        $numParts = count($urlParts);
        if ($numParts > 1) {
            $tld = '.' . $urlParts[$numParts - 1];
            $domain = $urlParts[$numParts - 2];
        } else {
            $domain = $urlParts[0];
            $tld = '';
        }
        if (strpos($domain, '://') > 0) {
            $domain = substr($domain, strpos($domain, '://') + 3);
        }
    }
    $serverName = $domain . $tld;
    return $serverName;
}

/**
 * Return the host domain only, removing bottom-level server name if it is there.
 * Turns www.enginesis.com into enginesis.com
 * @param $targetHost
 * @return string
 */
function domainDropServer ($targetHost) {
    $alteredHost = $targetHost;
    $pos = strpos($alteredHost, '://'); // remove the protocol
    if ($pos > 0) {
        $alteredHost = substr($alteredHost, $pos + 3);
    }
    $firstSlash = strpos($alteredHost, '/'); // remove everything after the domain
    if ($firstSlash > 0) {
        $alteredHost = substr($alteredHost, 0, $firstSlash);
    }
    $domainParts = explode('.', $alteredHost);
    if (count($domainParts) > 2) {
        $alteredHost = '';
        for ($i = 1; $i < count($domainParts); $i ++) {
            $alteredHost .= ($i == 1 ? '' : '.') . $domainParts[$i];
        }
    } elseif (count($domainParts) == 2) {
        $alteredHost = $domainParts[0] . '.' . $domainParts[1];
    }
    return $alteredHost;
}

/**
 * Transform the current host name into the matching host name requested. For example, if we are currently on
 * www.enginesis-q.com and the $targetPlatform is -l, return www.enginesis-l.com.
 * @param $targetPlatform -l/-d/-x/-q/ or live
 * @return string
 */
function domainForTargetPlatform ($targetPlatform) {
    // was getDomainForTargetPlatform
    $hostName = serverName();
    $lastDot = strrpos($hostName, '.'); // find the tld
    if ($lastDot >= 0) {
        $domain = substr($hostName, 0, $lastDot);
        $tld = substr($hostName, $lastDot + 1);
    } else { // no .tld!
        $domain = $hostName;
        $tld = '';
    }
    // TODO: remove -? from $hostname, then concat everything
    $domain = preg_replace('/\-[l|d|q|x]$/', '', $domain);
    return $domain . $targetPlatform . '.' . $tld;
}

/**
 * Return just the -l, -d, -q, -x part, or '' for live, from the current host name.
 * @param null $hostName
 * @return string
 */
function serverStage ($hostName = null) {
    $targetPlatform = ''; // assume live until we prove otherwise
    if (strlen($hostName) == 0) {
        $hostName = serverName();
    }
    if (strpos($hostName, '-l.') >= 2) {
        $targetPlatform = '-l';
    } elseif (strpos($hostName, '-d.') >= 2) {
        $targetPlatform = '-d';
    } elseif (strpos($hostName, '-q.') >= 2) {
        $targetPlatform = '-q';
    } elseif (strpos($hostName, '-x.') >= 2) {
        $targetPlatform = '-x';
    }
    return $targetPlatform;
}

/**
 * Returns true if we are on a testing stage - either -l or -d.
 * @param null $serverStage
 * @return bool
 */
function isTestServerStage ($serverStage = null) {
    if ($serverStage === null) {
        $serverStage = serverStage();
    }
    return $serverStage == '-l' || $serverStage == '-d';
}

/**
 * Fix the input string to match the current stage we are on. E.g. if we are given http://www.enginesis.com/index.php
 * and we are currently running on -l, then return http://www.enginesis-l.com/index.php.
 * @param $targetFile
 * @return string
 */
function serverStageMatch ($targetFile) {
    $whichEnv = serverStage(); // determine which server we are running on, from -l, -q, -d or live
    if ($whichEnv != '') { // we need to set the correct server environment
        $protocolStr = '';
        $targetURL = $targetFile;
        $pos = strpos($targetURL, '//'); // get the protocol. This could be // or http:// or https://
        if ($pos > 0) {
            $protocolStr = substr($targetURL, 0, $pos + 2);
            $targetURL = substr($targetURL, $pos + 2);
        }
        $firstSlash = strpos($targetURL, '/'); // save everything after the domain
        if ($firstSlash > 0) {
            $urlPath = substr($targetURL, $firstSlash);
            $domainStr = substr($targetURL, 0, $firstSlash);
        } else {
            $urlPath = '';
            $domainStr = $targetURL;
        }
        $domainStr = strtolower($domainStr);
        if (strtolower(serverName()) != strtolower($domainStr)) {
            $lastDot = strrpos($domainStr, '.'); // now fix the domain to match the current server stage
            if ($lastDot >= 0) {
                $domainStr = substr($domainStr, 0, $lastDot) . $whichEnv . substr($domainStr, $lastDot);
            }
        }
        $targetFile = $protocolStr . $domainStr . $urlPath;
    } else { // We are on live. Does the input string have a stage specification in it? if so, take it out.
        // preg_match( /-[l|d|q|x]\./ )
    }
    return $targetFile;
}

function domainStageMatchDropServer ($targetHost) {
    // return the host domain only, removing bottom-level server name if it is there.
    // Turns www.enginesis.com into enginesis.com, or if running on -q, turns www.enginesis.com into enginesis-q.com

    $whichEnv = serverStage(); // determine which server we are running on, from -l, -q, -d or live
    $alteredHost = $targetHost;
    $pos = strpos($alteredHost, '://'); // remove the protocol
    if ($pos > 0) {
        $alteredHost = substr($alteredHost, $pos + 3);
    }
    $firstSlash = strpos($alteredHost, '/'); // remove everything after the domain
    if ($firstSlash > 0) {
        $alteredHost = substr($alteredHost, 0, $firstSlash);
    }
    $domainParts = explode('.', $alteredHost);
    if (count($domainParts) > 2) {
        $alteredHost = $domainParts[1] . $whichEnv;
        for ($i = 2; $i < count($domainParts); $i ++) {
            $alteredHost .= '.' . $domainParts[$i];
        }
    } elseif (count($domainParts) == 2) {
        $alteredHost = $domainParts[0] . $whichEnv . '.' . $domainParts[1];
    }
    return $alteredHost;
}

function isLive() {
    return serverStage() == '';
}

function coregDataFolder($site_id) {
    // return the location of co-reg interface files
    return siteDataFolder($site_id);
}

function serverDataFolder() {
    // This folder is not shared on the live servers. Use for server specific data (such as log files)
    return SERVER_DATA_PATH . 'enginesis' . DIRECTORY_SEPARATOR;
}

function getServerHTTPProtocol ($return_full_protocol = true) {
    $serverProtocol = getServiceProtocol();
    if ($return_full_protocol) {
        $serverProtocol .= '://';
    }
    return $serverProtocol;
}

function siteDataFolder($site_id) {
    // TODO: was getSiteDataFolder
    // This folder is site based. It is shared among all the load-balanced servers. (was getStageDataFolder)
    global $site_data;
    $siteDataPath = $site_data[$site_id]['site_data_path'];
    if (strlen($siteDataPath) == 0) {
        $serverNameMain = serverTail();
        if( strpos($serverNameMain, '.') < 1 ) {
            reportError("bad host $serverNameMain", __FILE__, __LINE__, 'siteDataFolder');
        } elseif (strpos($serverNameMain, '-') !== false) {
            $siteDataPath = SERVER_DATA_PATH . substr($serverNameMain, 0, strpos($serverNameMain, '-')) . substr($serverNameMain, strpos($serverNameMain, '-') + 2, strlen($serverNameMain)) . '/';
        } else {
            $siteDataPath = SERVER_DATA_PATH . substr($serverNameMain, 0, strpos($serverNameMain, '.')) . '/';
        }
    } else {
        $siteDataPath = SERVER_DATA_PATH . $siteDataPath . '/';
    }
    return $siteDataPath;
}

function enginesisParameterObjectMake ($fn, $site_id, $parameters) {
    global $sync_id;
    $serverParams = [];
    $serverParams['fn'] = $fn;
    $serverParams['site_id'] = $site_id;
    $serverParams['state_seq'] = ++ $sync_id;
    $serverParams['response'] = 'json';
    foreach ($parameters as $key => $value) {
        $serverParams[$key] = urlencode($value);
    }
    return $serverParams;
}

function gameParameterStringMake ($result_array) {
    $resultStr = '';
    foreach($result_array as $fieldname => $fielddata) {
        if (strlen($resultStr) > 0) {
            $resultStr .= '&';
        }
        $resultStr .= $fieldname . '=' . $fielddata;
    }
    return($resultStr);
}

function gameKeyMake ($site_id, $game_id) {
    return md5(COREG_TOKEN_KEY . $site_id . $game_id);
}

function randomString ($length, $maxCodePoint = 32, $reseed = false) {
    // create Random String: Calculates a random string based on a length given
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-+:;<=>?@()[]{}!@#$%^&*-|_",.~`/\'\\';
    if ($reseed) {
        srand((double)microtime() * 9057254886133);
    }
    $i = 0;
    $string = '';
    if ($maxCodePoint < 10) {
        $maxCodePoint = 10;
    } elseif ($maxCodePoint > strlen($chars)) {
        $maxCodePoint = strlen($chars);
    }
    while ($i < $length) {
        $string = $string . substr($chars, rand() % $maxCodePoint, 1);
        $i++;
    }
    return $string;
}

/**
 * Search $text for tokens in the form %#% and replace them with their respective function arguments.
 * Counting starts at 1 (because $text is item 0) and we expect to find at least as many function arguments
 * as there are references in $text. Example:
 *    $updatedText = tokenArgsReplace ( "This %1% is a %2% %1%.", "sandwich", "turkey" )
 * will return "This sandwich is a turkey sandwich."
 * @param $text
 * @return string replaced text
 */
function tokenArgsReplace ($text) {
    $args  = func_get_args();
    for ($i = 1; $i <= count($args); $i ++) {
        $token = "%$i%";
        if (stripos($text, $token) !== false ) {
            $text = str_replace($token, $args[$i], $text);
        }
    }
    return $text;
}

/**
 * Search $text for tokens in the form %token% and replace them with their respective parameter value.
 * Example:
 *    $updatedText = ReplaceTokenArgs ( "This %food% is a %meat% %food%.", array("food" => "sandwich", "meat" => "turkey" )
 * will return "This sandwich is a turkey sandwich."
 * @param $text
 * @param $paramsArray
 * @return string replaced text
 */
function tokenReplace ($text, $paramsArray) {
    if ( ! empty($text) && is_array($paramsArray) && count($paramsArray) > 0) {
        foreach ($paramsArray as $token => $value) {
            $token = "%$token%";
            if (stripos($text, $token) !== false) {
                $text = str_replace($token, $value, $text);
            }
        }
    }
    return $text;
}

/**
 * Convert an array into a string.
 * @param $array
 * @return string
 */
function arrayToString ($array) {
    if (isset($array) && is_array($array)) {
        return '[' . implode(',', $array) . ']';
    } else {
        return '[null]';
    }
}

/**
 * Determine is a variable is considered empty. This goes beyond PHP empty() function to support Flash and JavaScript
 * possibilities.
 * @param $str
 * @return bool
 */
function isEmpty ($str) {
    if (isset($str)) {
        return (is_null($str) || strlen($str) == 0 || $str == 'undefined' || strtolower($str) == 'null');
    } else {
        return true;
    }
}

/**
 * Determine if a string begins with a specific string.
 * @param $haystack
 * @param $needle string|array
 * @return bool
 */
function startsWith($haystack, $needle) {
    if (is_array($needle)) {
        for ($i = 0; $i < count($needle); $i ++) {
            if (startsWith($haystack, $needle[$i])) {
                return true;
            }
        }
        return false;
    } else {
        return (substr($haystack, 0, strlen($needle)) === $needle);
    }
}

/**
 * Deterine if a string ends with a specific string.
 * @param $haystack
 * @param $needle string|array
 * @return bool
 */
function endsWith($haystack, $needle) {
    if (is_array($needle)) {
        for ($i = 0; $i < count($needle); $i ++) {
            if (endsWith($haystack, $needle[$i])) {
                return true;
            }
        }
        return false;
    } else {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * Transform a string into a safe to show inside HTML string. Unsafe HTML chars are converted to their escape equivalents.
 * @param $string a string to transform.
 * @return string the transformed string.
 */
function safeForHTML ($string) {
    $htmlEscapeMap = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&#x27;',
        '/' => '&#x2F;'
    ];
    $htmlEscapePattern = [
        '/&/',
        '/</',
        '/>/',
        '/"/',
        '/\'/',
        '/\//'
    ];
    return preg_replace($htmlEscapePattern, $htmlEscapeMap, $string);
}

/**
 * Determine if a string has any character of a string of select characters.
 * @param $string string to check
 * @param $selectChars string of individual character to check if contained in $string
 * @param int $start start position in $string to begin checking, default is the beginning.
 * @param int $length ending position in $string to stop checking, default is the end.
 * @return bool true if at least one character in $selectChars is also in $string, false if none.
 */
function str_contains ($string, $selectChars, $start = 0, $length = 0) {
    if ($length == 0) {
        $length = strlen($string);
    }
    if ($start < 0) {
        $start = 0;
    }
    for ($i = $start; $i < $length; $i ++) {
        if (strpos($selectChars, $string[$i]) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Find the earliest numeric position of any one of a set of substrings in a string. If more than one is found
 *   in target string then the occurrence with the smallest numeric position is returned. false is returned if
 *   none of the substrings are found.
 * @param string $haystack the string to search.
 * @param array $needles list of substrings to locate in $haystack.
 * @param int $offset starting position in $haystack to begin search from.
 * @return bool|int the offset from the beginning of the string where the earliest match of $needles occurs, and false if
 *   no $needles are found.
 */
function strpos_array ($haystack, $needles, $offset = 0) {
    $matches = [];
    $i = 0;
    foreach ($needles as $needle) {
        $position = strpos($haystack, $needle, $offset);
        if ($position !== false) {
            $matches[$i++] = $position;
        }
    }
    return count($matches) == 0 ? false : min($matches);
}

/**
 * Convert an integer value to its boolean representation.
 * @param $val
 * @return bool
 */
function castIntToBool ($val) {
    if (is_string($val)) {
        $val = strtolower($val);
        if ($val == 'true' || $val == 'false') {
            return ($val != 'false');
        } else {
            return ($val != '0');
        }
    } else {
        return ($val != 0);
    }
}

/**
 * Convert a boolean value to an integer representation. Typically we need this for the database as we only save
 * 1 or 0.
 * @param $value
 * @return int
 */
function castBoolToInt ($value) {
    if (is_string($value)) {
        $value = strtolower($value);
        if ($value == 'false' || $value == '0' || $value == 'n' || $value == 'no') {
            $value = false;
        } else {
            $value = true;
        }
    }
    return $value ? 1 : 0;
}

/**
 * Return a string representation of a boolean value. If the value is not a true boolean then it will be
 * implicitly cast to boolean.
 * @param $value
 * @return string
 */
function castBoolToString($value) {
    return $value ? 'true' : 'false';
}

/**
 * Determine if a given value is something we an take to be a boolean value.
 * @param $value int|string must be scalar int or string
 * @return bool
 */
function isValidBool($value) {
    if (is_integer($value)) {
        return $value === 1 || $value === 0;
    } elseif (is_string($value)) {
        return in_array(strtolower($value), ['1', '0', 't', 'f', 'y', 'n', 'o', 'yes', 'no', 'true', 'false', 'on', 'off', 'checked']);
    }
    return false;
}

/**
 * Determine if the id is a valid id for a database object. That typically means the id cannot be 0, null, or negative.
 * @param $id int expected otherwise implicitly cast to int.
 * @return bool
 */
function isValidId($id) {
    return $id !== null && $id > 0;
}

/**
 * Performs basic user name validation. A user name must be between 3 and 20 characters
 *   and we only accept certain characters (a-z, 0-9,_ - . $ @ ! | ~. Note that a user name may contain
 *   only digits, and then we have to decide if it is a user name or a user-id.
 * @param $userName string The user name to check.
 * @returns bool true if acceptable otherwise false.
 */
function isValidUserName ($userName) {
    $len = strlen(trim($userName));
    return $len == strlen($userName) && preg_match('/^[a-zA-Z0-9_@!~\$\.\-\|\s]{3,20}$/', $userName) === 1;
}

/**
 * Remove and bad chars from a proposed user name.
 * @param $userName string The user name to clean up
 * @return string the clean user name
 */
function cleanUserName ($userName) {
    return preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-Z0-9_@!~\$\.\-\|\s]/', '', trim($userName)));
}

/**
 * Performs basic user password validation. The password can be any printable characters between 4 and 20 in length
 * with no leading or trailing spaces.
 * @param string $password The password to check.
 * @returns bool true if acceptable otherwise false.
 */
function isValidPassword ($password) {
    $len = strlen(trim($password));
    return $len == strlen($password) && ctype_graph($password) && $len > 3 && $len < 21;
}

/**
 * Make sure a proposed gender value is valid. THis is intended to be used to validate forms and user input and make
 * certain we have a value our system can deal with.
 * @param $gender {string} a proposed value for gender, either a single character M, F, or N, or a word Male, Female, or Neutral.
 * @return string One of the gender setting we will accept.
 * TODO: This should be localized, so move the possible names table into a lookup table.
 */
function validateGender ($gender) {
    $validGenders = ['Male', 'Female', 'Neutral'];
    $gender = trim($gender);
    if (strlen($gender) == 1) {
        $gender = strtoupper($gender);
        if ($gender != $validGenders[0][0] && $gender != $validGenders[1][0] && $gender != $validGenders[2][0]) {
            $gender = $validGenders[2][0];
        }
    } else {
        $gender = ucwords($gender);
        if ($gender != $validGenders[0] && $gender != $validGenders[1] && $gender != $validGenders[2]) {
            $gender = $validGenders[2];
        }
    }
    return $gender;
}

/**
 * Given an email address test to see if it appears to be valid.
 * @param $email {string} an email address to check
 * @return bool true if we think the email address looks valid, otherwise false.
 */
function checkEmailAddress ($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function cleanString ($input) {
    // clean extended chars out of the string
    $search = [
        '/[\x60\x82\x91\x92\xb4\xb8]/i',             // single quotes
        '/[\x84\x93\x94]/i',                         // double quotes
        '/[\x85]/i',                                 // ellipsis ...
        '/[\x00-\x0d\x0b\x0c\x0e-\x1f\x7f-\x9f]/i'   // all other non-ascii
    ];
    $replace = [
        '\'',
        '"',
        '...',
        ''
    ];
    return preg_replace($search, $replace, $input);
}

function cleanFilename ($filename) {
    return str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '', $filename);
}

function strip_tags_attributes ($source, $allowedTags = [], $disabledAttributes = ['onclick', 'ondblclick', 'onkeydown', 'onkeypress', 'onkeyup', 'onload', 'onmousedown', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onunload']) {
    if (empty($disabledEvents)) {
        return strip_tags($source, implode('', $allowedTags));
    } else {
        return preg_replace('/<(.*?)>/ie', "'<' . preg_replace(array('/javascript:[^\"\']*/i', '/(" . implode('|', $disabledAttributes) . ")=[\"\'][^\"\']*[\"\']/i', '/\s+/'), array('', '', ' '), stripslashes('\\1')) . '>'", strip_tags($source, implode('', $allowedTags)));
    }
}

function profanityFilter (&$strTest) {
    // TODO: This needs work
    $strTest = strtolower($strTest);
    $strOld = $strTest;
    $fullwordlistban = "ass|asshole|pussy";
    $partialwordlistban = "fuck|cunt|shit|dick|bitch|penis";
    $strTest = preg_replace("/\b($fullwordlistban)\b/ie", 'preg_replace("/./","*","\\1")', $strTest);
    $strTest = preg_replace("/($partialwordlistban)/ie", 'preg_replace("/./","*","\\1")', $strTest);
    if ($strTest == $strOld) {
        return false;
    }
    return true;
}

/**
 * In order to provide some flexibility with dates, our API will accept a PHP date, a Unix timestamp,
 * a date string, or null. This function will try to figure our what date was provided and convert what ever
 * it is into a valid MySQL date string. If null it returns the current date-time.
 * @param $phpDate mixed One of PHP Date, integer, a string, or null.
 * @param $includeTime boolean include the time in the return value.
 * @return string a valid MySQL date
 */
function DateToMySQLDate ($phpDate, $includeTime = true) {
    if ($includeTime) {
        $mySQLDateFormat = 'Y-m-d H:i:s';
    } else {
        $mySQLDateFormat = 'Y-m-d';
    }
    if (is_null($phpDate)) {
        return date($mySQLDateFormat, time()); // no date given, use now
    } elseif (is_object($phpDate)) {
        return $phpDate->format($mySQLDateFormat);
    } elseif (is_string($phpDate)) {
        return date($mySQLDateFormat, strtotime($phpDate));
    } else {
        return date($mySQLDateFormat, $phpDate);
    }
}

function MySQLDateToDate ($mysqlDate) {
    // Convert MySQL date to php Date
    return strtotime($mysqlDate);
}

function MySQLDateToHumanDate ($mysqlDate) {
    // MySQL date is YYYY-mm-dd convert it to mm/dd/yyyy
    return substr($mysqlDate, 5, 2) . '/' . substr($mysqlDate, 8, 2) . '/' . substr($mysqlDate, 0, 4);
}

function HumanDateToMySQLDate ($humanDate) {
    // Convert mm/dd/yyyy into yyyy-mm-dd
    $dateParts = explode('/', $humanDate, 3);
    if(strlen($dateParts[0]) < 2) {
        $dateParts[0] = '0' . $dateParts[0];
    }
    if(strlen($dateParts[1]) < 2) {
        $dateParts[1] = '0' . $dateParts[1];
    }
    if(strlen($dateParts[2]) < 3) {
        if ((int) $dateParts[2] < 76) { // we are having Y2K issues
            $dateParts[2] = '20' . $dateParts[2];
        } else {
            $dateParts[2] = '19' . $dateParts[2];
        }
    }
    return $dateParts[2] . '-' . $dateParts[0] . '-' . $dateParts[1] . ' 00:00:00';
}

/**
 * Determine if the color value is considered a dark color.
 * @param $htmlHexColorValue
 * @return bool
 */
function isDarkColor ($htmlHexColorValue) {
    $htmlHexColorValue = str_replace('#', '', $htmlHexColorValue);
    return (((hexdec(substr($htmlHexColorValue, 0, 2)) * 299) + (hexdec(substr($htmlHexColorValue, 2, 2)) * 587) + (hexdec(substr($htmlHexColorValue, 4, 2)) * 114)) / 1000 >= 128) ? false : true;
}

/**
 * Convert an HTML color hex string into a key/value RGB array of decimal color values 0-255.
 * @param $hex
 * @param bool $alpha
 * @return mixed
 */
function hexToRgb($hex, $alpha = 1.0) {
    $hex      = str_replace('#', '', $hex);
    $length   = strlen($hex);
    $rgb['r'] = hexdec($length == 6 ? substr($hex, 0, 2) : ($length == 3 ? str_repeat(substr($hex, 0, 1), 2) : 0));
    $rgb['g'] = hexdec($length == 6 ? substr($hex, 2, 2) : ($length == 3 ? str_repeat(substr($hex, 1, 1), 2) : 0));
    $rgb['b'] = hexdec($length == 6 ? substr($hex, 4, 2) : ($length == 3 ? str_repeat(substr($hex, 2, 1), 2) : 0));
    $rgb['a'] = $alpha;
    return $rgb;
}

/**
 * Convert an RGB color array into it HTML hex string equivalent.
 * @param $rgb {array}
 * @return string
 */
function rgbToHex($rgb) {
    if (isset($rgb['r']) && isset($rgb['g']) && isset($rgb['b'])) {
        return sprintf("#%02x%02x%02x", $rgb['r'], $rgb['g'], $rgb['b']);
    } elseif (is_array($rgb) && count($rgb) > 2) {
        return sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
    }
    return '#000000';
}

// =================================================================
// Session services: session functions deal with logged in users.
// =================================================================

/**
 * Figure out which domain we want to save the cookie under.
 * @param null $serverName
 * @return null|string
 */
function sessionCookieDomain ($serverName = null) {
    $newDomain = null;
    $domain = serverTail($serverName);
    if (strlen($domain) > 0) {
        $newDomain = '.' . $domain;
    }
    return $newDomain;
}

/**
 * Generate a time stamp for the current time rounded to the nearest SESSION_DAYSTAMP_HOURS hour.
 * This is used for access tokens as theya re short-lived.
 * @return int
 */
function sessionDayStamp () {
    return floor(time() / (SESSION_DAYSTAMP_HOURS * 60 * 60)); // good for X hours
}

/**
 * Generate a time stamp for the current time rounded to the nearest SESSION_REFRESH_HOURS hour.
 * This is used for refresh tokens as they are long-lived.
 * @return int
 */
function sessionRefreshStamp () {
    return floor(time() / (SESSION_REFRESH_HOURS * 60 * 60));
}

/**
 * Create a timestamp string for the current refresh token expiration.
 * @return string
 */
function sessionRefreshExpireTimestamp() {
    $dateExpires = new DateTime();
    $dateExpires->add(new DateInterval(SESSION_REFRESH_INTERVAL));
    return $dateExpires->format('Y-m-d H:i:s');
}

/**
 * Determine if a day stamp is currently valid. Day stamps expire after SESSION_DAYSTAMP_HOURS.
 * @param $dayStamp
 * @return bool
 */
function sessionIsValidDayStamp ($dayStamp) {
    $day_stamp_current = sessionDayStamp();
    return ! ($dayStamp < ($day_stamp_current - (SESSION_DAYSTAMP_HOURS / 24)) || $dayStamp > $day_stamp_current);
}

/**
 * Determine if a refresh time stamp is currently valid. Refresh stamps expire after SESSION_REFRESH_HOURS.
 * @param $refreshStamp
 * @return bool
 */
function sessionIsValidRefreshStamp ($refreshStamp) {
    $day_stamp_current = sessionRefreshStamp();
    return ! ($refreshStamp < ($day_stamp_current - (SESSION_REFRESH_HOURS / 24)) || $refreshStamp > $day_stamp_current);
}

/**
 * Create a user-based and time-sensitive session identifier. Typically used to identify a unique game session
 * for a specific user so another user can't spoof that user.
 * @param $site_id
 * @param $user_id
 * @param $game_id
 * @return string
 */
function sessionMakeId ($site_id, $user_id, $game_id) {
    global $site_data;
    if (isset($site_data[$site_id])) {
        $developer_key = $site_data[$site_id]['encryption_key'];
        if ( ! isset($user_id) || $user_id == null || $user_id < 1) {
            $user_id = 0;
        }
        if ( ! isset($game_id) || $game_id == null || $game_id < 1) {
            $game_id = 0;
        }
        return md5($developer_key . sessionDayStamp() . '' . $user_id . '' . $game_id);
    } else {
        return null;
    }
}

/**
 * Create a session hash that serves as a one-way hash to check if any session data was tampered with.
 * This requires a logged in user otherwise the session id generated will not be unique and will look
 * the same for all anonymous users on the same day-stamp.
 * @param $site_id {int} must be a valid site-id.
 * @param $user_id {int} must be a valid user-id on site-id.
 * @param $user_name {string} user name associated to user-id, would make the session invalid if the user changes their name.
 * @param $site_user_id {string} co-reg/SSO user-id.
 * @param $access_level {int} user's access level on site-id, would make the session invalid if the level of access changed.
 * @param $day_stamp {int} the Enginesis day-stamp to limit the valid time of the session
 * @return {string} session user hash.
 */
function sessionMakeHash ($site_id, $user_id, $user_name, $site_user_id, $access_level, $day_stamp) {
    global $site_data;
    global $enginesisLogger;

    if ( ! isset($site_data[$site_id])) {
        $enginesisLogger->log("Invalid site id", LogMessageLevel::Error, 'sessionMakeHash', __FILE__, __LINE__);
        return '';
    }
    if (isEmpty($user_id)) {
        $enginesisLogger->log("User id must be provided", LogMessageLevel::Error, 'sessionMakeHash', __FILE__, __LINE__);
        return '';
    }
    $site_key = $site_data[$site_id]['encryption_key'];
    return md5('s=' . $site_id . '&u=' . $user_id . '&d=' . $day_stamp . '&n=' . $user_name . '&i=' . $site_user_id . '&l=' . $access_level . '&k=' . $site_key);
}

/**
 * Create a game session hash that serves as a one-way hash to check if any game session data was tampered with.
 * At least one of user-id, game-id, or site-mark must be provided and the combination must be unique across
 * all users of the site on this day.
 * @param $site_id {int} must be a valid site-id
 * @param $user_id {int} valid user-id on site-id, or 0 if creating an anonymous session
 * @param $user_name {string} user name associated to user-id, would make the session invalid if the user changes their name.
 * @param $game_id {int} valid game-id on site-id, or 0 if creating a site-wide session
 * @param $day_stamp {int} the Enginesis day-stamp to limit the valid time of the session
 * @param $site_mark {int} a numeric value to make this session unique when user-id and game-id are not provided (for example, an anonymous user playing a game.)
 * @return {string} session user game hash.
 */
function sessionMakeGameHash ($site_id, $user_id, $user_name, $game_id, $day_stamp, $site_mark) {
    global $site_data;
    global $enginesisLogger;

    if ( ! isset($site_data[$site_id])) {
        $enginesisLogger->log("Invalid site id", LogMessageLevel::Error, 'sessionMakeGameHash', __FILE__, __LINE__);
        return '';
    }
    if (isEmpty($user_id) && isEmpty($game_id) && isEmpty($site_mark)) {
        $enginesisLogger->log("At least one of user-id($user_id), game-id($game_id), site-make($site_mark) must be provided", LogMessageLevel::Error, 'sessionMakeGameHash', __FILE__, __LINE__);
        return '';
    }
    $site_key = $site_data[$site_id]['encryption_key'];
    return md5('s=' . $site_id . '&u=' . $user_id . '&d=' . $day_stamp . '&n=' . $user_name . '&m=' . $site_mark . '&g=' . $game_id . '&k=' . $site_key);
}

/**
 * Anytime we look at the session cookie we should validate the hash in case someone tampered the cookie.
 * Compliment to `sessionMakeHash`.
 * @param $cr {string} Session hash that was previously generated with `sessionMakeHash()`.
 * @param $site_id {int} must be a valid site-id.
 * @param $user_id {int} must be a valid user-id on site-id.
 * @param $user_name {string} user name associated to user-id, would make the session invalid if the user changes their name.
 * @param $site_user_id {string} co-reg/SSO user-id.
 * @param $access_level {int} user's access level on site-id, would make the session invalid if the level of access changed.
 * @param $day_stamp {int} the Enginesis day-stamp to limit the valid time of the session
 * @return {boolean} true if the hash matches, false if it does not.
 */
function sessionVerifyHash ($cr, $site_id, $user_id, $user_name, $site_user_id, $access_level, $day_stamp) {
    return $cr == sessionMakeHash($site_id, $user_id, $user_name, $site_user_id, $access_level, $day_stamp);
}

/**
 * Anytime we look at the session cookie we should validate the hash in case someone tampered the cookie.
 * Compliment to `sessionMakeGameHash`.
 * @param $cr {string} Session hash that was previously generated with `sessionMakeGameHash()`.
 * @param $site_id {int} must be a valid site-id
 * @param $user_id {int} valid user-id on site-id, or 0 if creating an anonymous session
 * @param $user_name {string} user name associated to user-id, would make the session invalid if the user changes their name.
 * @param $game_id {int} valid game-id on site-id, or 0 if creating a site-wide session
 * @param $day_stamp {int} the Enginesis day-stamp to limit the valid time of the session
 * @param $site_mark {int} a numeric value to make this session unique when user-id and game-id are not provided (for example, an anonymous user playing a game.)
 * @return {boolean} true if the hash matches, false if it does not.
 */
function sessionVerifyGameHash ($cr, $site_id, $user_id, $user_name, $game_id, $day_stamp, $site_mark) {
    return $cr == sessionMakeGameHash($site_id, $user_id, $user_name, $game_id, $day_stamp, $site_mark);
}

/**
 * Generate a (hopefully) unique site mark. This is a pseudo-user-id to accommodate anonymous users who
 * use the site and we need to generate a unique session id on their behalf and not have it clash with
 * any other anonymous user on the site in this day-stamp window of time.
 * @return {int} mock user-id. Should be a minimum of 6 digits.
 */
function makeSiteMark() {
    return mt_rand(187902, mt_getrandmax());
}

/**
 * Make the plain-text version of the authentication token. This should never be handed out unless it is encrypted
 * (see sessionMakeAuthenticationTokenEncrypted).
 * @param $site_id {int|array) if an array we expect all parameters to be keys in this array.
 * @param $user_id
 * @param $user_name
 * @param $site_user_id
 * @param $network_id
 * @param $access_level
 * @param $day_stamp
 * @return string
 */
function sessionMakeAuthenticationToken($site_id, $user_id, $user_name, $site_user_id, $network_id, $access_level, $day_stamp) {
    if (is_array($site_id)) {
        return sessionMakeAuthenticationToken($site_id['siteid'], $site_id['userid'], $site_id['username'], $site_id['siteuserid'], $site_id['networkid'], $site_id['accesslevel'], $site_id['daystamp']);
    } else {
        return "siteid=$site_id&userid=$user_id&siteuserid=$site_user_id&networkid=$network_id&username=$user_name&accesslevel=$access_level&daystamp=$day_stamp";
    }
}

/**
 * Once a user is validated as logged in (UserLogin() or UserLoginCoreg()) then create an authentication token and save it
 * either in a session cookie or pass it around as a $POST variable (authtok). This will serve to identify a specific
 * user. The token times out and should be refreshed every interval.
 *
 * This function makes an authentication token to communicate to a client the info to identify a user (user_id, user_name, site_user_id, access_level)
 * These values go in encrypted so a hacker can't view source and figure how to hack the game. Returns null if we cannot make a token.
 * Note we use the developer encryption key and not the site key as this key is discoverable in the client.
 *
 * @param $site_id
 * @param $user_id
 * @param $user_name
 * @param $site_user_id
 * @param $access_level
 * @param $network_id
 * @param string $language_code
 * @param null $sqlConn - needed if we need to look up the user by network-id/site-user-id
 * @return mixed
 */
function sessionMakeAuthenticationTokenEncrypted($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code = 'en', $sqlConn = null) {
    global $site_data;
    if (($user_id == null || $user_id < 1) && (! empty($site_user_id) || ! empty($user_name))) { // no user id, derive one from site_user_id
        $user_id = userIdFromSiteUserId ($site_id, $site_user_id, $user_name, $network_id, $language_code, $sqlConn);
    }
    $developerKey = $site_data[$site_id]['encryption_key'];
    $dayStamp = sessionDayStamp(); // make sure the token has an expiration
    $decryptedData = sessionMakeAuthenticationToken($site_id, $user_id, $user_name, $site_user_id, $network_id, $access_level, $dayStamp);
    return encryptString($decryptedData, $developerKey);
}

/**
 * Make the encrypted refresh token. The user can present this token to get a new access token. We need the info to
 * identify the user so this token is only good for this user.
 * @param $site_id
 * @param $user_id
 * @param $user_name
 * @param $site_user_id
 * @param $access_level
 * @param $network_id
 * @param string $language_code
 * @param null $sqlConn - needed if we need to find or make a user-id from the network-id/site-user-id.
 * @return mixed
 */
function sessionMakeRefreshTokenEncrypted($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code = 'en', $sqlConn = null) {
    global $site_data;
    if (($user_id == null || $user_id < 1) && (! empty($site_user_id) || ! empty($user_name))) { // no user id, derive one from site_user_id
        $user_id = userIdFromSiteUserId ($site_id, $site_user_id, $user_name, $network_id, $language_code, $sqlConn);
    }
    $refreshKey = $site_data[$site_id]['encryption_key'] . REFRESH_TOKEN_KEY;
    $dayStamp = sessionRefreshStamp(); // make sure the token has an expiration
    $decryptedData = sessionMakeAuthenticationToken($site_id, $user_id, $user_name, $site_user_id, $network_id, $access_level, $dayStamp);
    return encryptString($decryptedData, $refreshKey);
}

/**
 * Decrypt an authentication token and return an array of items contained in it. This function is designed to undo
 * what sessionMakeAuthenticationTokenEncrypted did but returning an array of the input parameters.
 * @param $site_id {int} we require the site id to know ow the token was encrypted.
 * @param $authenticationToken {string} the encrypted token.
 * @return array|null Returns null if the token could not be decrypted, when successful returns an array matching the
 *     input parameters of sessionMakeAuthenticationTokenEncrypted.
 */
function sessionDecryptAuthenticationToken ($site_id, $authenticationToken) {
    global $site_data;
    $dataArray = null;
    if (isset($site_data[$site_id])) {
        $tokenData = decryptString($authenticationToken, $site_data[$site_id]['encryption_key']);
        if ($tokenData != null && $tokenData != '') {
            $dataArray = decodeURLParams($tokenData);
        }
    }
    return $dataArray;
}

/**
 * Decrypt the refresh token and return its constituent parameters. We do not validate it here.
 * @param $site_id
 * @param $refreshToken
 * @return array|null
 */
function sessionDecryptRefreshToken ($site_id, $refreshToken) {
    global $site_data;
    $dataArray = null;
    if (isset($site_data[$site_id])) {
        $refreshKey = $site_data[$site_id]['encryption_key'] . REFRESH_TOKEN_KEY;
        $tokenData = decryptString($refreshToken, $refreshKey);
        if ($tokenData != null && $tokenData != '') {
            $dataArray = decodeURLParams($tokenData);
        }
    }
    return $dataArray;
}

/**
 * For testing, make a refresh token from the current session.
 * @param $site_id
 * @param $logged_in_user_id
 * @param $language_code
 * @return string the refresh token or empty string if the current session was not set or invalid.
 */
function sessionMakeRefreshTokenFromSession($site_id, $logged_in_user_id, $language_code) {
    $authenticationToken = sessionGetAuthenticationToken();
    $refreshToken = '';
    if ( ! empty($authenticationToken)) {
        $session_site_id = $site_id;
        $user_id = $logged_in_user_id;
        $user_name = '';
        $site_user_id = '';
        $access_level = 0;
        $network_id = 1;
        if (sessionValidateAuthenticationToken($authenticationToken, $session_site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, null) == '') {
            $refreshToken = sessionMakeRefreshTokenEncrypted($session_site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, null);
        }
    }
    return $refreshToken;
}

/**
 * Assuming some process before this validated the session, generate the encrypted token the client can use
 * to converse with the server later and not have to revalidate.
 * @param $site_id
 * @param $user_id
 * @param $user_name
 * @param $site_user_id
 * @param $access_level
 * @param $network_id {int} The network id related to $site_user_id if it is used.
 * @return string An error code, '' if successful.
 */
function sessionSet ($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id) {
    $language_code = sessionGetLanguageCode();
    $authenticationToken = sessionMakeAuthenticationTokenEncrypted($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, null);
    return sessionSave($user_id, $authenticationToken);
}

/**
 * Clear any session data and forget any logged in user.
 * @return string An error code if the function fails to clear the cookies, or an empty string if successful.
 */
function sessionClear () {
    $rc = '';
    if (setcookie(SESSION_COOKIE, null, time() - 86400, '/', sessionCookieDomain()) === false) {
        reportError('setcookie failed', __FILE__, __LINE__, 'sessionClear');
        $rc = 'CANNOT_SET_SESSION';
    }
    setcookie(SESSION_USERINFO, null, time() - 86400, '/', sessionCookieDomain());
    $_COOKIE[SESSION_COOKIE] = null;
    $_COOKIE[SESSION_AUTHTOKEN] = null;
    $_COOKIE[SESSION_USERINFO] = null;
    $GLOBALS[SESSION_USERID_CACHE] = null;
    return $rc;
}

/**
 * Save the authenticated session to cookie so we can retrieve it next time this user returns.
 * @param $user_id int the user's id.
 * @param $authenticationToken string the encrypted authentication token generated by sessionMakeAuthenticationTokenEncrypted.
 * @return string An error code, '' if successful.
 */
function sessionSave ($user_id, $authenticationToken) {
    $rc = '';
    $errorLevel = error_reporting(); // turn off warnings so we don't generate crap in the output stream. If we don't do this fucking php writes an error and screws up the output stream. (I cant get the try/catch to work without it)
    error_reporting($errorLevel & ~E_WARNING);
    try {
        if (setcookie(SESSION_COOKIE, $authenticationToken, time() + (SESSION_DAYSTAMP_HOURS * 60 * 60), '/', sessionCookieDomain()) === false) {
            reportError('setcookie failed', __FILE__, __LINE__, 'sessionSave');
            $rc = 'CANNOT_SET_SESSION';
        }
    } catch (Exception $e) {
        reportError('could not set cookie: ' . $e->getMessage(), __FILE__, __LINE__, 'sessionSave');
        $rc = 'CANNOT_SET_SESSION';
    }
    error_reporting($errorLevel); // put error level back to where it was
    if ($rc == '') {
        $_COOKIE[SESSION_COOKIE] = $authenticationToken;
        $GLOBALS[SESSION_USERID_CACHE] = $user_id;
        // $_POST['authtok'] = $authenticationToken; // not sure about this
    }
    return $rc;
}

/**
 * Save the authenticated user info to cookie so we can retrieve it next time this user returns. We do this
 * to allow clients to access the logged in user info easily after a login. Always verify the token is
 * valid before relying on this data.
 * @param $userInfo object the user's info as returned from a userLogin.
 * @return string An error code, '' if successful.
 */
function sessionUserInfoSave ($site_id, $userInfo) {
    global $site_data;
    $siteBaseURL = $site_data[$site_id]['site_base_url'];
    $siteCookieRoot = sessionCookieDomain(serverStageMatch($siteBaseURL));
    $rc = '';
    $errorLevel = error_reporting(); // turn off warnings so we don't generate crap in the output stream. If we don't do this fucking php writes an error and screws up the output stream. (I cant get the try/catch to work without it)
    error_reporting($errorLevel & ~E_WARNING);
    try {
        $userInfoJson = json_encode($userInfo);
        if (setcookie(SESSION_USERINFO, $userInfoJson, time() + (SESSION_DAYSTAMP_HOURS * 60 * 60), '/', $siteCookieRoot) === false) {
            reportError('setcookie failed', __FILE__, __LINE__, 'sessionUserInfoSave');
            $rc = 'CANNOT_SAVE_USERINFO';
        }
    } catch (Exception $e) {
        reportError('could not set cookie: ' . $e->getMessage(), __FILE__, __LINE__, 'sessionUserInfoSave');
        $rc = 'CANNOT_SAVE_USERINFO';
    }
    error_reporting($errorLevel); // put error level back to where it was
    return $rc;
}

/**
 * Retrieve the authenticated user info from cookie. Always verify the token is
 * valid before relying on this data.
 * @return object The userInfo object or null if invalid or not set.
 */
function sessionUserInfoGet () {
    $userInfo = null;
    $errorLevel = error_reporting(); // turn off warnings so we don't generate crap in the output stream. If we don't do this fucking php writes an error and screws up the output stream. (I cant get the try/catch to work without it)
    error_reporting($errorLevel & ~E_WARNING);
    if (isset($_COOKIE[SESSION_USERINFO])) {
        try {
            $userInfo = json_decode($_COOKIE[SESSION_USERINFO]);
        } catch (Exception $e) {
            reportError('could not get cookie: ' . $e->getMessage(), __FILE__, __LINE__, 'sessionUserInfoGet');
        }
    }
    error_reporting($errorLevel); // put error level back to where it was
    return $userInfo;
}

/**
 * Return the HTTP authorization headers. This is where we expect to find our authentication token.
 * @returns {string|null} The authorization header, or null if it was not sent in this request.
 */
function getAuthorizationHeader () {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        // Nginx or fast CGI
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    return $headers;
}

/**
 * Find and return the Bearer token supplied in the HTTP request, if it's there.
 * @returns {string|null} the HTTP bearer token or null if it was not sent.
 */
function getBearerTokenInRequest() {
    $headers = getAuthorizationHeader();
    if ( ! empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

/**
 * Get the authentication token, either it was provided in the http get/post, or it is in the cookie. POST overrides cookie.
 * This function does not determine if the authentication token is actually valid (use sessionValidateAuthenticationToken for that.)
 * The authentication token can come in 3 ways:
 *   1. In HTTP headers HTTP_AUTHORIZATION Bearer,
 *   2. In the session cookie
 *   3. as a POST or GET parameter (least desirable)
 * @return string token, null if no token could be found.
 */
function sessionGetAuthenticationToken () {
    $authenticationToken = getBearerTokenInRequest();
    if ($authenticationToken == null) {
        if (isset($_COOKIE[SESSION_COOKIE])) {
            $authenticationToken = $_COOKIE[SESSION_COOKIE];
        } else {
            $authenticationToken = getPostOrRequestVar('authtok', null);
        }
    }
    return $authenticationToken;
}

/**
 * Determine if we previously established a valid session. If so, return session parameters and refresh the
 * session cookie. You can check the session cookie later.
 * @param $site_id
 * @param $user_id
 * @param $user_name
 * @param $site_user_id
 * @param $access_level
 * @param $network_id {int}
 * @param string $language_code
 * @param null $sqlConn
 * @return string
 */
function sessionValidate ( & $site_id, & $user_id, & $user_name, & $site_user_id, & $access_level, & $network_id, $language_code = 'en', $sqlConn = null) {
    $site_id = getPostOrRequestVar('site_id', sessionGetSiteId());
    $authenticationToken = sessionGetAuthenticationToken();
    if ($authenticationToken != '') {
        $rc = sessionValidateAuthenticationToken($authenticationToken, $site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, $sqlConn);
        if ($rc == '') { // token is valid, set the session cookie as well
            sessionSet($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id);
        }
    } else {
        $rc = 'NOT_LOGGED_IN';
    }
    if ($rc != '') {
        $user_id = 0;
        $user_name = '';
        $site_user_id = '';
        $access_level = 0;
        sessionClear();
    }
    return $rc;
}

/**
 * This is a private helper function used to get a token out of the authentication token. It's useful for one-off
 * queries where you want to extract a specific item out of the token. But since it will decrypt the token on
 * each call, try to use sessionValidate() if you need more than one token.
 * @param $key {string} The key of the item you are looking for.
 * @param null $site_id
 * @param null $authenticationToken
 * @return string the value of the item requested or '' if not found.
 */
function sessionGetDataForKey ($key, $site_id = null, $authenticationToken = null) {
    global $site_data;
    $value = '';

    if ( ! isset($site_data[$site_id])) {
        $site_id = getPostOrRequestVar('site_id', 0);
    }
    if (empty($authenticationToken)) {
        $authenticationToken = sessionGetAuthenticationToken();
    }
    if (strlen($authenticationToken) > 0 && isset($site_data[$site_id])) {
        $dataArray = sessionDecryptAuthenticationToken($site_id, $authenticationToken);
        if (isset($dataArray['daystamp'])) {
            $dayStamp = $dataArray['daystamp'];
            if (sessionIsValidDayStamp($dayStamp)) {
                if (isset($dataArray[$key])) {
                    $value = $dataArray[$key];
                }
            }
        }
    }
    return $value;
}

function sessionGetSessionId ($site_id, $user_id, $game_id) { // return a session identifier - only good for SESSION_DAYSTAMP_HOURS hours
    $session_id = sessionGetDataForKey('sessionid', $site_id, null);
    if ($session_id == '') {
        $session_id = sessionMakeId($site_id, $user_id, $game_id);
    }
    return $session_id;
}

/**
 * Get the user id stored in the session cookie. If not in the session or the session token is bad
 * then we attempt to see if it was previously saved in the cookie parameter cache. Since the session
 * cookie is encrypted this is not the fastest way to do this.
 * @return int - user id or 0 if the function fails.
 */
function sessionGetUserId () {
    $user_id = sessionGetDataForKey('userid', null, null);
    if ($user_id == 0 && isset($GLOBALS[SESSION_USERID_CACHE])) {
        $user_id = (int) $GLOBALS[SESSION_USERID_CACHE];
    }
    if (empty($user_id)) {
        $user_id = 0;
    }
    return $user_id;
}

/**
 * Get the site id stored in the session cookie. If not in the session or the session token is bad
 * then we attempt to see if it was included in the HTTP request. Since the session cookie is encrypted this is not the
 * fastest way to do this.
 * @return int - site id or 0 if the function fails.
 */
function sessionGetSiteId () {
    $site_id = sessionGetDataForKey('siteid', null, null);
    if (empty($site_id)) {
        $site_id = (int) getPostOrRequestVar('site_id', 0);
    }
    return $site_id;
}

/**
 * Get the site-user-id network-id stored in the session cookie. If not in the session or the session token is bad
 * then we attempt to see if it was included in the HTTP request. Since the session cookie is encrypted this is not the
 * fastest way to do this.
 * @return int - network id or 0 if the function fails.
 */
function sessionGetNetworkId () {
    $network_id = sessionGetDataForKey('networkid', null, null);
    if (empty($network_id)) {
        $network_id = (int) getPostOrRequestVar('network_id', 0);
    }
    return $network_id;
}

/**
 * Get the site-user-id stored in the session cookie. Since the session cookie is encrypted this is not the
 * fastest way to do this.
 * @return string - site user id or empty if the function fails.
 */
function sessionGetSiteUserId () {
    return sessionGetDataForKey('siteuserid', null, null);
}

/**
 * Get the user access level stored in the session cookie. Since the session cookie is encrypted this is not the
 * fastest way to do this.
 * @return int access level or 0 if the function fails.
 */
function sessionGetAccessLevel () {
    $access_level = sessionGetDataForKey('accesslevel', null, null);
    if (empty($access_level)) {
        $access_level = 0;
    }
    return $access_level;
}

/**
 * Get the user name stored in the session cookie. Since the session cookie is encrypted this is not the
 * fastest way to do this.
 * @return string user name of empty if the function fails.
 */
function sessionGetUserName () {
    return sessionGetDataForKey('username', null, null);
}

/**
 * Attempt to figure out the clients language code/locale. If we cannot, default to 'en'.
 * @return string Language code.
 */
function sessionGetLanguageCode () {
    $language_code = getPostOrRequestVar('language_code', null);
    if ($language_code == null) {
        $language_code = getPostOrRequestVar('locale', null);
        if ($language_code == null) {
            $language_code = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2)) : null;
        }
    }
    if ($language_code == null) {
        $language_code = 'en';
    }
    return $language_code;
}

/**
 * This is a debug function to echo out the current session cookie, it does not check if it is valid.
 * @param $site_id {int} If not provided we try to get it from $POST or $GET.
 */
function sessionCookieDump ($site_id) {
    global $site_data;
    $authenticationToken = sessionGetAuthenticationToken();
    if (strlen($authenticationToken) > 0) {
        if ( ! isset($site_data[$site_id])) {
            $site_id = getPostOrRequestVar('site_id', 0);
        }
        echo("<p>sessionCookieDump $site_id, $authenticationToken</p>\n");
        $dataArray = sessionDecryptAuthenticationToken($site_id, $authenticationToken);
        print_r($dataArray);
    } else {
        echo('<p>sessionCookieDump ' . SESSION_COOKIE . " cookie is not set or is not valid on site $site_id</p>\n");
    }
}

/**
 * This function validates a token used to communicate to a client the logged in user_id, user_name, site_user_id, access_level.
 * @param $authenticationToken {string} a token to validate, generated with sessionMakeAuthenticationTokenEncrypted.
 * @param $site_id {int} site id.
 * @param $user_id {int} user id who is logged in.
 * @param $user_name {string} user name who is logged in.
 * @param $site_user_id {string} co-reg user id who is logged in, validated by the co-reg network SSO.
 * @param $access_level {int} level of access (from site-roles.)
 * @param $network_id {int} network to use to validate site_user_id
 * @param string $language_code {string} language code or locale.
 * @param null $sqlConn {object} existing database connection or null to use the connection pool.
 * @return string The result is an error code, '' if successful.
 */
function sessionValidateAuthenticationToken ($authenticationToken, & $site_id, & $user_id, & $user_name, & $site_user_id, & $access_level, & $network_id, $language_code = 'en', $sqlConn = null) {
    global $site_data;
    $errorCode = '';

    if (empty($authenticationToken)) {
        $authenticationToken = sessionGetAuthenticationToken();
    }
    if (strlen($authenticationToken) > 0) {
        if ( ! isset($site_data[$site_id])) {
            $site_id = getPostOrRequestVar('site_id', 0);
        }
        $dataArray = sessionDecryptAuthenticationToken($site_id, $authenticationToken);
        if (isset($dataArray['daystamp'])) {
            $dayStamp = $dataArray['daystamp'];
            if (sessionIsValidDayStamp($dayStamp) && isset($dataArray['siteid'])) {
                $site_id = $dataArray['siteid'];
                $user_id = $dataArray['userid'];
                $user_name = $dataArray['username'];
                $site_user_id = $dataArray['siteuserid'];
                $access_level = $dataArray['accesslevel'];
                $network_id = $dataArray['networkid'];
            } else {
                $errorCode = 'TOKEN_EXPIRED';
            }
        } else {
            $errorCode = 'INVALID_TOKEN';
        }
    } else {
        $errorCode = 'NO_TOKEN';
    }
    return $errorCode;
}

/**
 * Given a refresh token exchange it for the short-lived access token. Caller must check the error code returned
 * to know if a token was actually exchanged. When successful the session is also updated with the new info.
 * @param $site_id
 * @param $refreshToken
 * @param $authenticationToken
 * @param string $language_code
 * @param null $sqlConn
 * @return string error code, empty if token exchanged.
 */
function sessionExchangeRefreshTokenForAuthenticationToken($site_id, & $refreshToken, & $authenticationToken, $language_code = 'en', $sqlConn = null) {
    global $site_data;
    $errorCode = '';

    if (strlen($refreshToken) > 0) {
        if ( ! isset($site_data[$site_id])) {
            $site_id = getPostOrRequestVar('site_id', 0);
        }
        $dataArray = sessionDecryptRefreshToken($site_id, $refreshToken);
        if (isset($dataArray['daystamp'])) {
            $dayStamp = $dataArray['daystamp'];
            if (sessionIsValidRefreshStamp($dayStamp) && isset($dataArray['siteid'])) {
                $site_id = $dataArray['siteid'];
                $user_id = $dataArray['userid'];
                $user_name = $dataArray['username'];
                $site_user_id = $dataArray['siteuserid'];
                $access_level = $dataArray['accesslevel'];
                $network_id = $dataArray['networkid'];
                $authenticationToken = sessionMakeAuthenticationTokenEncrypted($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, $sqlConn);
                $refreshToken = sessionMakeRefreshTokenEncrypted($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id, $language_code, $sqlConn);
                sessionSet($site_id, $user_id, $user_name, $site_user_id, $access_level, $network_id);
            } else {
                $errorCode = 'TOKEN_EXPIRED';
            }
        } else {
            $errorCode = 'INVALID_TOKEN';
        }
    } else {
        $errorCode = 'NO_TOKEN';
    }
    return $errorCode;
}

function sessionCacheAdditionalParams ($additional_params) {
    // when the game comes back with a social request we need to recall any special parameters sent in to validate the request
    $imploded_params = encodeURLParams($additional_params);
    $_COOKIE[SESSION_PARAM_CACHE] = $imploded_params;
    $GLOBALS[SESSION_PARAM_CACHE] = $imploded_params;
    if (setcookie(SESSION_PARAM_CACHE, $imploded_params, 0, '/', sessionCookieDomain()) === false) {
        reportError('setcookie failed', __FILE__, __LINE__, 'sessionCacheAdditionalParams');
    }
}

function sessionRecallAdditionalParams ($add_to_globals = '') {
    // compliment to sessionCacheAdditionalParams, get the cached items
    $imploded_params = '';
    $additional_params = null;
    if (isset($GLOBALS[SESSION_PARAM_CACHE])) {
        $imploded_params = $GLOBALS[SESSION_PARAM_CACHE];
    } elseif (isset($_COOKIE[SESSION_PARAM_CACHE])) {
        $imploded_params = $_COOKIE[SESSION_PARAM_CACHE];
    }
    if ($imploded_params != '') {
        $additional_params = decodeURLParams($imploded_params);
        if ($add_to_globals == 'GET' || $add_to_globals == '_GET') {
            foreach ($additional_params as $key => $value) {
                $_GET[$key] = $value;
            }
        } elseif ($add_to_globals == 'POST' || $add_to_globals == '_POST') {
            foreach ($additional_params as $key => $value) {
                $_POST[$key] = $value;
            }
        }
    }
    return $additional_params;
}

// =================================================================
//	General utilities and helper functions:
// =================================================================

function imageFileReceive ($saveItHere, $imageType) {
    $rc = false;
    if (isset($_POST['width']) && isset($_POST['height'])) {
        $w = (int) $_POST['width'];
        $h = (int) $_POST['height'];
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, 0xFFFFFF);
        $rows = 0;
        $cols = 0;
        for ($rows = 0; $rows < $h; $rows ++) {
            $c_row = explode(',', $_POST['px' . $rows]);
            for ($cols = 0; $cols < $w; $cols ++) {
                $value = $c_row[$cols];
                if ($value != '') {
                    $hex = $value;
                    while (strlen($hex) < 6) {
                        $hex = '0' . $hex;
                    }
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                    $imgData = imagecolorallocate($img, $r, $g, $b);
                    imagesetpixel($img, $cols, $rows, $imgData);
                }
            }
        }
        $imageType = strtolower($imageType);
        if ($imageType == 'jpg' || $imageType == 'jpeg') {
            $rc = imagejpeg($img, $saveItHere, 100);
        } elseif ($imageType == 'png') {
            $rc = imagepng($img, $saveItHere, 0);
        } elseif ($imageType == 'gif') {
            $rc = imagegif($img, $saveItHere);
        }
    }
    return $rc;
}

function loginUrlMake ($site_id, $game_id = null) {
    // was generateLoginUrl
    global $redirect_urls;
    $url = $redirect_urls[$site_id]['login'];
    if ($site_id > 0 && $game_id > 0) {
        $sql = dbQuery('select IF(length(trim(site_specific_game_id)) > 0 and site_specific_game_id > 0, site_specific_game_id, game_id) as game_id from site_games	where game_id = ? and site_id = ?', [$game_id, $site_id]);
        $row = dbFetch($sql);
        if (isset($row)) {
            $game_id = $row['game_id'];
            $url = str_replace("%game_id%", $game_id, $url);
        }
    }
    return $url;
}

/**
 * Parse a string of tags into individual tags array, making sure each tag is properly formatted.
 * A tag must be at least 1 character and no more than 50, without any leading or trailing whitespace,
 * and without any HTML tags (entities should be OK.)
 * @param $tags string of tags to consider.
 * @param string $delimiter how each tag in the input string is separated.
 * @return array individual tags, null if there are no tags.
 */
function tagParse ($tags, $delimiter = ';') {
    if ($tags != null && strlen($tags) > 0) {
        $tagList = explode($delimiter, $tags);
        for ($i = count($tagList) - 1; $i >= 0; $i --) {
            $tagList[$i] = trim(substr(strip_tags(trim($tagList[$i])), 0, 50));
            if (strlen($tagList[$i]) < 2) {
                array_splice($tagList, $i, 1);
            }
        }
        if (count($tagList) == 0) {
            $tagList = null;
        }
    } else {
        $tagList = null;
    }
    return $tagList;
}

/**
 * Delete all files in a directory then remove the directory.
 * @param $directory
 * @return bool
 */
function directoryDelete ($directory) {
    $rc = false;
    if ($directory[strlen($directory) - 1] != '/') {
        $directory .= '/';
    }
    if (is_dir($directory)) {
        $dir_handle = opendir($directory);
        if ($dir_handle != 0) {
            while ($file = readdir($dir_handle)) {
                if ($file != '.' && $file != '..') {
                    $filename = $directory . $file;
                    if ( ! is_dir($filename)) {
                        unlink($filename);
                    } else {
                        directoryDelete($filename);
                    }
                }
            }
            closedir($dir_handle);
            rmdir($directory);
            $rc = true;
        }
    }
    return $rc;
}

/**
 * Return the file extension from a file name. Or, more precisely, return everything after the last
 * . character in a string.
 * @param $fileName
 * @return string
 */
function getExtension ($fileName) {
    $ext = '';
    $i = strrpos($fileName, '.');
    if ($i >= 0) {
        $ext = substr($fileName, $i + 1, strlen($fileName) - $i);
    }
    return $ext;
}

/**
 * Generate a random string of base64 characters of the requested length. I have no
 * idea where this algorithm came from or how effective it is.
 * @param int $length
 * @return string
 */
function makeRandomToken ($length = 12) {
    $token = '';
    for ($i = 0; $i < $length; ++ $i) {
        if ($i % 2 == 0) {
            mt_srand(time() % 2147 * 1000000 + (double) microtime() * 1000000);
        }
        $rand = 48 + mt_rand() % 64;
        if ($rand > 57) {
            $rand += 7;
        }
        if ($rand > 90) {
            $rand += 6;
        }
        if ($rand == 123) {
            $rand = 45;
        } elseif ($rand == 124) {
            $rand = 46;
        }
        $token .= chr($rand);
    }
    return $token;
}

/**
 * If the flag parameter is determined to be true (implicit cast to bool) then return a checkbox string.
 * @param $flag
 * @return string
 */
function showBooleanChecked($flag) {
    if ($flag) {
        return ' checked';
    } else {
        return '';
    }
}
    /**
     * Set the CORS access control header. For now we allow anyone to access our resources. We may decide to change
     * this policy to a whitelist.
     */
function setAccessControlHeader () {
/*    $request_headers = apache_request_headers();
    if (isset($request_headers['Origin'])) {
        $http_origin = $request_headers['Origin'];
    }
    // TODO: Whitelist table: This table should come from the allowable site_ids in siteconfig.php "site_base_url"
    $allowed_http_origins = [
        'http://enginesis.com',
        'http://enginesis-l.com',
        'http://enginesis-d.com',
        'http://enginesis-q.com',
        'http://varyn.com',
        'http://varyn-l.com',
        'http://varyn-d.com',
        'http://varyn-q.com',
        'http://jumpydot.com',
        'http://jumpydot-l.com',
        'http://jumpydot-d.com',
        'http://jumpydot-q.com',
        'http://killerquiz.com',
        'http://killerquiz-l.com',
        'http://killerquiz-d.com',
        'http://killerquiz-q.com',
        'http://localhost',
    ];
    if (in_array($http_origin, $allowed_http_origins)){
        @header('Access-Control-Allow-Origin: ' . $http_origin);
    } else {
        @header('Access-Control-Allow-Origin: ' . $http_origin);
    }
*/
    switch($_POST['response']) {
        case 'json':
            $contentType = 'application/json';
            break;
        case 'xml':
            $contentType = 'text/xml';
            break;
        default:
            $contentType = 'text/html';
            break;
    }
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Methods: GET, POST');
    header('Content-Type: ' . $contentType);
    header('Server: Enginesis');
}

/**
 * Log a mail message so we can track outbound emails. This function is designed to be sent into
 * the EnginesisMailer log function.
 * @param $infoMessage
 */
function logMailSent ($infoMessage) {
    global $enginesisLogger;
    $enginesisLogger->log("Mail sent $infoMessage", LogMessageLevel::Info, 'Mail', __FILE__, __LINE__);
}