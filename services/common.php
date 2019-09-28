<?php
/**
 * Common utility PHP functions for sites and services that communicate with the Enginesis backend.
 * This file defines the following globals, which are global so they can be used in subordinate PHP pages:
 *   SERVER_ROOT is the file path to the root of the web site file structure. 
 *   $enginesis: a global to access this object instance
 *   $siteId: enginesis site_id for this website.
 *   $serverStage: stage for this instance: -l, -d, -q, or '' for Live
 *   $serverName: name of this server?
 *   $server: which enginesis server to converse with, full protocol/domain/url e.g. https://www.enginesis.com
 *   $enginesisServer: location/root URL of the enginesis server we are conversing with
 *   $enginesisLogger: reference to the logging system
 *   $webServer: our (this) web server e.g. varyn.com
 *   $isLoggedIn: true if the user is logged in
 *   $userId: the id of the logged in user
 *   $page: unique page identifier for each page or section of the website
 *   serverConfig.php holds server-specific configuration variables and is not to be checked in to version control.
 */
setErrorReporting(true);
session_start();
require_once('version.php');
require_once('serverConfig.php');
require_once('Enginesis.php');
require_once('LogMessage.php');
if (isset($_SERVER['DOCUMENT_ROOT']) && strlen($_SERVER['DOCUMENT_ROOT']) > 0) {
    define('ROOTPATH', $_SERVER['DOCUMENT_ROOT'] . '/');
    $serverRootPath = dirname(ROOTPATH) . '/';
} else {
    define('ROOTPATH', '../');
    $serverRootPath = ROOTPATH;
}
define('SERVER_ROOT', $serverRootPath);
define('SERVER_DATA_PATH', $serverRootPath . 'data/');
define('SERVER_PRIVATE_PATH', $serverRootPath . 'private/');
define('SERVICE_ROOT', $serverRootPath . 'services/');
define('VIEWS_ROOT', $serverRootPath . 'views/');

/**
 * @description
 *   Turn on or off all error reporting. Typically we want this on for development, off for production.
 * @param {bool} true to turn on error reporting, false to turn it off.
 * @return {bool} just echos back the flag.
 */
function setErrorReporting ($reportingFlag) {
    if ($reportingFlag) {
        error_reporting(E_ALL);
        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', 'On');
        ini_set('html_errors', 'On');
    } else {
        error_reporting(E_ERROR);
        ini_set('error_reporting', E_ERROR);
        ini_set('display_errors', 'Off');
        ini_set('html_errors', 'Off');
    }
    return $reportingFlag;
}

// ===============================================================================================
//	Error logging and debugging functions. Depends on LogMessage/$enginesisLogger.
// ===============================================================================================
/**
 * This function would determine how to handle an error based on context and server.
 * @param string $msg A message to report.
 * @param string $file The file name that generated the report.
 * @param int $line The line on $file that generated the report.
 * @param string $fn The function name that generated the report.
 * @return string The message that was logged.
 */
function reportError($msg, $file = '', $line = 0, $fn = '') {
    global $enginesisLogger;

    if (strlen($file) == 0) {
        $file = __FILE__; // TODO: This makes no sense, maybe try to get the call stack?
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

function dieIfNotLive($msg) {
    global $enginesisLogger;
    if ( ! isLive()) {
        $enginesisLogger->log("dieIfNotLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
        echo $msg;
        exit;
    }
}

function dieIfLive($msg) {
    global $enginesisLogger;
    if (isLive()) {
        $enginesisLogger->log("dieIfLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
        echo $msg;
        exit;
    }
}

/**
 * Create a failed response for cases when we are going to fail locally without transaction
 * with the server.
 */
function makeErrorResponse($errorCode, $errorMessage, $parameters) {
    $service = isset($parameters['fn']) ? $parameters['fn'] : 'UNKNOWN';
    $stateSequence = isset($parameters['stateSeq']) ? $parameters['stateSeq'] : 0;
    $contents = '{"results":{"status":{"success":"0","message":"' . $errorCode . '","extended_info":"' . $errorMessage . '"},"passthru":{"fn":"' . $service . '","state_seq":' . $stateSequence . '}}}';
    return $response;
}

// =================================================================
// HTTP and client/server helper functions
// =================================================================

/**
 * Return the name of the page we are currently on.
 * @return string
 */
function currentPageName() {
    return basename($_SERVER['PHP_SELF'], '.php');
}

/**
 * Return the full URL of the page we are currently on.
 */
function currentPageURL() {
    return getServiceProtocol() . '://' . serverName() . $_SERVER['REQUEST_URI'];
}

/**
 * Append a query parameter on to the end of a URL string. This helper function handles
 * the edge cases.
 * @param $url {string} The initial URL. Can be null or empty string.
 * @param $key {string} A key to add as a query parameter. Cannot be empty.
 * @param $value {string} The value for the key. Cannot be null.
 */
function appendQueryParameter($url, $key, $value) {
    if ( ! empty($key) && $value !== null) {
        if (empty($url)) {
            $url = '';
        }
        $queryString = urlencode($key) . '=' . urlencode($value);
        $hasQuery = strpos($url, '?');
        if ($hasQuery === false) {
            $updatedURL = $url. '?' . $queryString;
        } elseif ($hasQuery == (strlen($url) - 1)) {
            $updatedURL = $url . $queryString;
        } else {
            $updatedURL = $url . '&' . $queryString;
        }
    } else {
        $updatedURL = $url;
    }
    return $updatedURL;
}

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
    $parameters = array();
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
    return str_replace(array('&', '"', "'", '<', '>'), array('&amp;', '&quot;', '&apos;', '&lt;', '&gt;'), $string);
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
 * processTrackBack: process a possible track back request when a page loads.
 * @param e: the event we are tracking, such as "Clicked Logo". While these are arbitrary, we should try to use
 *     the same value for the same event across all pages. Where are these id's documented?
 * @param u: the anonymous userId who generated the event.
 * @param: i: which newsletter this event came from.
 *
 * This data gets recorded in the database to be processed later.
 *
 */
function processTrackBack () {
    global $enginesis;
    $event = getPostOrRequestVar('e', '');
    $userId = getPostOrRequestVar('u', '');
    $newsletterId = getPostOrRequestVar('i', '');
    if ($newsletterId == '') {
        $newsletterId = getPostOrRequestVar('id', '');
    }
    if ($event != '' && $userId != '' && $newsletterId != '') {
        if (isset($_SERVER['HTTP_REFERER'])) {
            $url = parse_url($_SERVER['HTTP_REFERER']);
            $referrer = $url['host'];
        } else {
            $referrer = 'varyn.com';
        }
        $enginesis->newsletterTrackingRecord($userId, $newsletterId, $event, '', $referrer);
    }
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
 */
function callEnginesisAPI ($fn, $serverURL, $paramArray) {
    global $enginesisLogger;

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
    $response = $parameters['response'];
    $setSSLCertificate = false;
    $isLocalhost = serverStage() == '-l';
    $setSSLCertificate = startsWith(strtolower($serverURL), 'https://');
    $ch = curl_init($serverURL);
    if ($ch) {
        $referrer = serverName() . currentPagePath();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Enginesis PHP SDK');
        curl_setopt($ch, CURLOPT_REFERER, $referrer);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 600);
        if ($isLocalhost) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
        }
        if ($setSSLCertificate) {
            $certPath = $this->m_serverPaths['PRIVATE'] . 'cacert.pem';
            if (file_exists($certPath)) {
                curl_setopt($ch, CURLOPT_CAINFO, $certPath);
                curl_setopt($ch, CURLOPT_CAPATH, $certPath);
            } else {
                $enginesisLogger->log("Cant locate SSL certs $certPath", LogMessageLevel::Error, 'callEnginesisAPI', __FILE__, __LINE__);
            }
        }
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeURLParams($parameters));
        $contents = curl_exec($ch);
        if (empty($contents)) {
            $errorInfo = 'System error: ' . $this->m_serviceEndPoint . ' replied with no data. ' . curl_error($ch);
            $enginesisLogger->log($errorInfo, LogMessageLevel::Error, 'callEnginesisAPI', __FILE__, __LINE__);
            $contents = makeErrorResponse('SYSTEM_ERROR', $errorInfo, $parameters);
        }
        curl_close($ch);
    } else {
        $contents = makeErrorResponse('SYSTEM_ERROR', 'System error: unable to contact ' . $serverURL . ' or the server did not respond.', $parameters);
    }
    if ($enginesisLogger) {
        $enginesisLogger->log("parameters for $fn: " . $this->encodeURLParams($parameters), LogMessageLevel::Info, 'callEnginesisAPI', __FILE__, __LINE__);
        $enginesisLogger->log("response from $fn: $contents", LogMessageLevel::Info, 'callEnginesisAPI', __FILE__, __LINE__);
    }
    if ($response == 'json') {
        $contentsObject = json_decode($contents);
        // TODO: We should verify the response is a valid EnginesisReponse object
        if ($contentsObject == null) {
            $enginesisLogger->log("callServerAPI could not parse JSON into an object: $contents", LogMessageLevel::Error, 'callEnginesisAPI', __FILE__, __LINE__);
        }
    }
    return $contentsObject;
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
    if (isset($enginesisLogger) && $enginesisLogger != null) {
        $enginesisLogger->log("Validating stage", LogMessageLevel::Info, 'Sys', __FILE__, __LINE__);
        $isValid = $enginesisLogger->isValid();
    } else {
        $isValid = false;
    }
    if ( ! $isValid || ($isValid && $includePassedTests)) {
        $testStatus[$test] = $isValid;
    }
    return $testStatus;
}

/**
 * Return the host name of the server we are running on. e.g. www.enginesis-q.com
 * @return string server host name only, e.g. www.enginesis.com.
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
        $serverName = $domain . $tld;
    }
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
 * Transform the host name into the matching stage-qualified host name requested. For example, if we are currently on
 * www.enginesis-q.com and the $targetPlatform is -l, return www.enginesis-l.com.
 * @param string $targetPlatform one of -l, -d, -x, -q or '' for live.
 * @param string|null $hostName A host name to check, or if not provided then the current host. This is a domain, not a URL.
 * @return string The requalified host name.
 */
function domainForTargetPlatform ($targetPlatform, $hostName = null) {
    if (empty($hostName)) {
        $hostName = serverName();
    }
    $lastDot = strrpos($hostName, '.');
    if ($lastDot === false) {
        // no .tld!
        $domain = $hostName;
    } else {
        $domain = substr($hostName, 0, $lastDot);
        $tld = substr($hostName, $lastDot + 1);
        $domain = preg_replace('/-[ldqx]$/', '', $domain) . $targetPlatform . '.' . $tld;
    }
    return $domain;
}

/**
 * Parse the given host name to determine which stage we are currently running on.
 * @param $hostName string - host name or domain name to parse. If null we try the current `serverName()`.
 * @return string the -l, -d, -q, -x part, or '' for live.
 */
function serverStage($hostName = null) {
    // assume live until we prove otherwise
    $targetPlatform = '';
    if (strlen($hostName) == 0) {
        $hostName = serverName();
    }
    if (preg_match('/-[dlqx]\./i', $hostName, $matchedStage)) {
        $targetPlatform = substr($matchedStage[0], 0, 2);
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

function enginesisParameterObjectMake ($fn, $site_id, $parameters) {
    global $sync_id;
    $serverParams = array();
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
 * Create a token that is good on this server for 30 minutes. We use this token in sensitive input forms
 * to not accept input after this expiration time.
 * @return string the token the form should return.
 */
function makeInputFormHackerToken () {
    global $enginesis;
    $expirationTime = 30;
    $hackerToken = md5($enginesis->getServerName()) . '' . floor(time() / ($expirationTime * 60));
    return $hackerToken;
}

/**
 * Given a token from an input form check to verify it has not yet expired.
 * @param $token generated with makeInputFormHackerToken.
 * @return boolean true when the token is good.
 */
function validateInputFormHackerToken ($token) {
    return makeInputFormHackerToken() == $token;
}

/**
 * Helper function to determine if the current session is valid. What we are looking for:
 *   1. User id and token exist
 *   2. user id matches token
 *   3. not expired
 * @param $userId
 * @param $token
 * @return bool
 */
function verifySessionIsValid($userId, $token) {
    // TODO: We need to write the code for this!
    return true;
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
    $htmlEscapeMap = array(
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&#x27;',
        '/' => '&#x2F;'
    );
    $htmlEscapePattern = array(
        '/&/',
        '/</',
        '/>/',
        '/"/',
        '/\'/',
        '/\//'
    );
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
 * Convert a boolean value to a string.
 * @param $variable
 * @return string
 */
function boolToString($variable) {
    return $variable ? 'true' : 'false';
}

/**
 * Convert a value to its boolean representation.
 * @param $variable - any type will be coerced to a boolean value.
 * @return boolean
 */
function valueToBoolean($variable) {
    if (is_string($variable)) {
        $variable = strtoupper($variable);
        $result =  $variable == '1' || $variable == 'Y' || $variable == 'T' || $variable == 'YES' || $variable == 'TRUE' || $variable == 'CHECKED';
    } elseif (is_numeric($variable)) {
        $result = ! ! $variable;
    } else {
        $result = $variable != null;
    }
    return $result;
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
    $validGenders = array('Male', 'Female', 'Neutral');
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
    $search = array(
        '/[\x60\x82\x91\x92\xb4\xb8]/i',             // single quotes
        '/[\x84\x93\x94]/i',                         // double quotes
        '/[\x85]/i',                                 // ellipsis ...
        '/[\x00-\x0d\x0b\x0c\x0e-\x1f\x7f-\x9f]/i'   // all other non-ascii
    );
    $replace = array(
        '\'',
        '"',
        '...',
        ''
    );
    return preg_replace($search, $replace, $input);
}

function cleanFilename ($filename) {
    return str_replace(array('\\', '/', ':', '*', '?', '"', '<', '>', '|'), '', $filename);
}

function strip_tags_attributes ($sSource, $aAllowedTags = array(), $aDisabledAttributes = array('onclick', 'ondblclick', 'onkeydown', 'onkeypress', 'onkeyup', 'onload', 'onmousedown', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onunload')) {
    if (empty($aDisabledEvents)) {
        return strip_tags($sSource, implode('', $aAllowedTags));
    } else {
        return preg_replace('/<(.*?)>/ie', "'<' . preg_replace(array('/javascript:[^\"\']*/i', '/(" . implode('|', $aDisabledAttributes) . ")=[\"\'][^\"\']*[\"\']/i', '/\s+/'), array('', '', ' '), stripslashes('\\1')) . '>'", strip_tags($sSource, implode('', $aAllowedTags)));
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
function dateToMySQLDate ($phpDate, $includeTime = true) {
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

/**
 * Given a MySQL date string return a human readable date string.
 * @param $date
 * @return bool|string
 */
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

/**
 * @function: ageFromDate: Determine age (number of years) since date.
 * @param {date} Date to calculate age from.
 * @param {date} Date to calculate age to, default is today.
 * @return int number of years from date to today.
 */
function ageFromDate ($checkDate, $referenceDate = null) {
    $timestamp = strtotime($checkDate);
    if ($referenceDate == null) {
        $referenceDateTime = time();
    } else {
        $referenceDateTime = strtotime($referenceDate);
    }
    $years = date("Y", $referenceDateTime) - date("Y", $timestamp);
    if (date("md", $timestamp) > date("md", $referenceDateTime)) {
        $years --;
    }
    return $years;
}

// =================================================================
// Session services: session functions deal with logged in users.
// =================================================================

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
        $sql = dbQuery('select IF(length(trim(site_specific_game_id)) > 0 and site_specific_game_id > 0, site_specific_game_id, game_id) as game_id from site_games	where game_id = ? and site_id = ?', array($game_id, $site_id));
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

function debugLog($message) {
    global $enginesisLogger;
    $enginesisLogger->log($message, LogMessageLevel::Info, 'System');
}

// "Global" PHP variables available to all scripts. See also serverConfig.php.
$enginesisLogger = new LogMessage([
    'log_active' => true,
    'log_level' => LogMessageLevel::All,
    'log_to_output' => false,
    'log_to_file' => true
]);
$page = '';
$webServer = '';
$enginesis = new Enginesis($siteId, null, $developerKey);
$enginesis->setDebugFunction('reportError');
$serverName = $enginesis->getServerName();
$serverStage = $enginesis->getServerStage();
// turn on errors for all stages except LIVE TODO: Remove from above when we are going live.
setErrorReporting($serverStage != '');
$isLoggedIn = $enginesis->isLoggedInUser();
if ($isLoggedIn) {
    $userId = $enginesis->getUserId();
    $authToken = $enginesis->getAuthToken();
} else {
    $userId = 0;
    $authToken = '';
}
processTrackBack();
