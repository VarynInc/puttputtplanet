<?php
/**
 * Common utility PHP functions for sites and services that communicate with the Enginesis backend.
 * This file defines the following globals, which are global so they can be used in subordinate PHP pages:
 *   SERVER_ROOT is the file path to the root of the web site file structure.
 *   $enginesis: a global to access this object instance
 *   $siteId: enginesis site_id for this website.
 *   $serverStage: stage for this instance: -l, -d, -q, or '' for Live
 *   $serverName: name of this server?
 *   $enginesisServer: which enginesis server to converse with, full protocol/domain/url e.g. https://enginesis.com
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
if ( ! empty($_SERVER['DOCUMENT_ROOT'])) {
    define('ROOTPATH', $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR);
    $serverRootPath = dirname(ROOTPATH) . DIRECTORY_SEPARATOR;
} else {
    $siteRoot = getcwd();
    while (true) {
        // check if current dir has data folder
        if (is_dir($siteRoot . DIRECTORY_SEPARATOR . 'public') && is_dir($siteRoot . DIRECTORY_SEPARATOR . 'data')) {
            $siteRoot = rtrim($siteRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            break;
        }
        // if not, go up one level
        $pathComponents = explode(DIRECTORY_SEPARATOR, $siteRoot);
        if (count($pathComponents) > 1) {
            array_pop($pathComponents);
            $siteRoot = implode(DIRECTORY_SEPARATOR, $pathComponents);
        } else {
            $siteRoot = '..' . DIRECTORY_SEPARATOR;
            break;
        }
    }
    define('ROOTPATH', $siteRoot);
    $serverRootPath = ROOTPATH;
}
define('SERVER_ROOT', $serverRootPath);
define('SERVER_DATA_PATH', $serverRootPath . 'data' . DIRECTORY_SEPARATOR);
define('SERVER_PRIVATE_PATH', $serverRootPath . 'private' . DIRECTORY_SEPARATOR);
define('SERVICE_ROOT', $serverRootPath . 'services' . DIRECTORY_SEPARATOR);
define('VIEWS_ROOT', $serverRootPath . 'views' . DIRECTORY_SEPARATOR);

/**
 * Turn on or off all error reporting. Typically we want this on for development, off for production.
 * @param boolean True to turn on error reporting, false to turn it off.
 * @return boolean The current setting of the error reporting flag.
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
    if ($enginesisLogger != null) {
        $stackTrace = null;

        if (strlen($file) == 0) {
            $stackTrace = debug_backtrace(FALSE, 1);
            $file = $stackTrace[1]['file'];
        }
        if ($line < 1) {
            if ($stackTrace == null) {
                $stackTrace = debug_backtrace(FALSE, 1);
            }
            $line = $stackTrace[1]['line'];
        }
        if (strlen($fn) > 0) {
            $msg = "$fn | " . $msg;
        }
        $enginesisLogger->log($msg, LogMessageLevel::Error, 'System', $file, $line);
    }
    return $msg;
}

function dieIfNotLive($msg) {
    global $enginesisLogger;
    if ( ! isLive()) {
        if ($enginesisLogger != null) {
            $enginesisLogger->log("dieIfNotLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
        }
        echo $msg;
        exit;
    }
}

function dieIfLive($msg) {
    global $enginesisLogger;
    if (isLive()) {
        if ($enginesisLogger != null) {
            $enginesisLogger->log("dieIfLive $msg", LogMessageLevel::Error, 'System', __FILE__, __LINE__);
        }
        echo $msg;
        exit;
    }
}

/**
 * Create a failed response for cases when we are going to fail locally without transaction
 * with the server.
 * @param string $errorCode The EnginesisErrors error code.
 * @param string $errorMessage The additional error message information to further explain $errorCode.
 * @param array|null $parameters Parameters that were supplied to the service to be echoed back as the service passthru information.
 * @return string A JSON string representing the error response.
 */
function makeErrorResponse($errorCode, $errorMessage, $parameters) {
    $service = isset($parameters['fn']) ? $parameters['fn'] : 'UNKNOWN';
    $stateSequence = isset($parameters['stateSeq']) ? $parameters['stateSeq'] : 0;
    $contents = '{"results":{"status":{"success":"0","message":"' . $errorCode . '","extended_info":"' . $errorMessage . '"},"passthru":{"fn":"' . $service . '","state_seq":' . $stateSequence . '}}}';
    return $contents;
}

/**
 * Determine if the response looks like a valid Enginesis response.
 *
 * @param EnginesisResponse $enginesisResponse An EnginesisResponse object.
 * @return boolean Indicates if the EnginesisResponse is considered a valid object.
 */
function isValidEnginesisResponse($enginesisResponse) {
    return is_object($enginesisResponse) && isset($enginesisResponse->results) && isset($enginesisResponse->results->status);
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

/**
 * Append all query parameters on to the end of a URL string. This helper function handles
 * the edge cases.
 * @param string The initial URL. Can be null or empty string.
 * @param string|object Either a query string (k=v&k=v) or an key/value object.
 * @return string A URL with updated query string.
 */
function appendQueryParameters($url, $keyValues) {
    if (is_object($keyValues)) {
        $parameters = $keyValues;
    } elseif (is_string($keyValues)) {
        parse_str($keyValues, $parameters);
    }
    $updatedURL = $url;
    foreach($parameters as $key => $value) {
        $updatedURL = appendQueryParameter($updatedURL, $key, $value);
    }
    return $updatedURL;
}

/**
 * Append a query parameter string on to the end of a URL string.
 * @param string $url The initial URL. Can be null or empty string.
 * @param string $parameters A string of parameters in the form k=v&k=v.
 * @return string A new URL with query parameters.
 */
function appendQueryString($url, $parameters) {
    if (empty($url)) {
        $updatedURL = '';
    } else {
        $updatedURL = $url;
    }
    if (empty($parameters)) {
        $parameters = '';
    }
    $hasQuery = strpos($updatedURL, '?');
    if ($hasQuery === false) {
        $updatedURL .= '?';
    }
    if ($parameters[0] == '&' || $parameters[0] == '?') {
        $updatedURL .= substr($parameters, 1);
    } else {
        $updatedURL .= $parameters;
    }
    return $updatedURL;
}

/**
 * Turn a key/value array into a query string with each parameter URL encoded.
 * For example it will return a=1&b=2 for the array ['a' => 1, 'b' => 2]
 * @param Array $parameters A key/value array of parameters.
 * @return String A URL query parameter string (without the leading '?')
 */
function encodeURLParams ($parameters) {
    $encodedURLParams = '';
    foreach ($parameters as $key => $value) {
        if ($encodedURLParams != '') {
            $encodedURLParams .= '&';
        }
        if (is_null($value)) {
            $value = '';
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
    return str_replace(['&', '"', "'", '<', '>'], ['&amp;', '&quot;', '&apos;', '&lt;', '&gt;'], $string);
}

/**
 * Return the protocol (http or https) the current server is running. This is designed to be used to help create fully qualified
 * URLs for client links so they match the current protocol.
 * @return string Current protocol (https|http).
 */
function getServiceProtocol () {
    if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        return 'https';
    }
    return 'http';
}

/**
 * Return a variable that was posted from a form, or in the REQUEST object (GET or COOKIES), or a default if not found.
 * This way POST is the primary concern but if not found will fallback to the other methods.
 * @param string|array $varName variable to read from request. If array, iterates array of strings until the first entry returns a result.
 * @param mixed $defaultValue A value to return if the parameter is not provided in the request.
 * @return mixed The value of the parameter or $defaultValue.
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
 * Return a variable we expect to be an integer that was posted from a form, or in the REQUEST object (GET or COOKIES), or a default if not found.
 * This way POST is the primary concern but if not found will fallback to the other methods.
 * @param string|array $varName variable to read from request. If array, iterates array of strings until the first entry returns a result.
 * @param mixed $defaultValue A value to return if the parameter is not provided in the request.
 * @return mixed The value of the parameter or $defaultValue.
 */
function getPostOrRequestInt ($varName, $defaultValue = 0) {
    return intval(getPostOrRequestVar($varName, $defaultValue));
}

/**
 * Return a variable that was posted from a form, or a default if not found.
 * @param string|array $varName variable to read from POST. If array, iterates array of strings until the first entry returns a result.
 * @param mixed $defaultValue A value to return if the parameter is not provided in the POST.
 * @return mixed The value of the parameter or $defaultValue.
 */
function getPostVar ($varName, $defaultValue = NULL) {
    if (is_array($varName)) {
        for ($i = 0; $i < count($varName); $i ++) {
            $value = getPostVar($varName[$i], null);
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
        } else {
            $value = $defaultValue;
        }
    }
    return $value;
}

/**
 * Return a variable that we expect to be an integer that was posted from a form, or a default if not found.
 * @param string|array $varName variable to read from POST. If array, iterates array of strings until the first entry returns a result.
 * @param mixed $defaultValue A value to return if the parameter is not provided in the POST.
 * @return mixed The value of the parameter or $defaultValue.
 */
function getPostInt ($varName, $defaultValue = 0) {
    return intval(getPostVar($varName, $defaultValue));
}

/**
 * Return the HTTP header value for a specified header key. For example,
 * if the header set is "Authentication: Bearer token", then calling
 * getHTTPHeader('Authentication') should return "Bearer token".
 *
 * @param string $headerName The name of an expected entry in the HTTP headers sent by a client request.
 * @return string|null The header value is returned, if found. If not found, null is returned.
 */
function getHTTPHeader ($headerName) {
    $headerValue = null;
    $httpHeaders = getallheaders();
    if (is_array($httpHeaders) && count($httpHeaders) > 0) {
        $headerName = strtolower($headerName);
        foreach ($httpHeaders as $name => $value) {
            if ($headerName == strtolower($name)) {
                $headerValue = $value;
                break;
            }
        }
    }
    return $headerValue;
}

/**
 * Set up correct HTTP response headers.
 */
function setHTTPHeader() {
    if (headers_sent()) {
        return;
    }
    global $ALLOWED_DOMAINS;
    $stage = serverStage();
    $domains = "https://" . ENGINESIS_SITE_KEY . "$stage.com https://enginesis$stage.com https://*.enginesis$stage.com https://enginesis." . ENGINESIS_SITE_KEY . "$stage.com";
    if (! empty($ALLOWED_DOMAINS)) {
        $domains .= ' ' . $ALLOWED_DOMAINS;
    }
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header("Content-Security-Policy: *; img-src *;");
    header("Content-Security-Policy: default-src 'self' $domains;");
    header("Content-Security-Policy: worker-src 'self' $domains blob:;");

}

/**
 * Determine the origin of the request.
 * @return string Request origin.
 */
function getHTTPOrigin() {
    if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
        $origin = $_SERVER['HTTP_ORIGIN'];
        $urlParts = parse_url($origin);
        if ( ! empty($urlParts['host'])) {
            $origin = $urlParts['host'];
        }
    } elseif (array_key_exists('HTTP_REFERER', $_SERVER)) {
        $origin = $_SERVER['HTTP_REFERER'];
        $urlParts = parse_url($origin);
        if ( ! empty($urlParts['host'])) {
            $origin = $urlParts['host'];
        }
    } else {
        $origin = getHTTPClient();
    }
    return $origin;
}

/**
 * Return the referring client information. You can request just the referrer or a complete report
 * that includes origin and remote address separated with `|`.
 * @param boolean Set to true to return referrer, origin, and IP address of client. Set to false to only return the HTTP referer.
 * @return string The referring client information.
 */
function getHTTPReferrer($completeReport = true) {
    $referrer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
    if ($completeReport) {
        $referrer .= ' | ' . getHTTPOrigin() . ' | ' . getHTTPClient();
    }
    return $referrer;
}

/**
 * Determine the IP address of the client making the request. We look at
 * several possible request header attributes and choose the first one
 * we come across.
 * @return string|null Client IP address as it appears in the request header, of null if none found.
 */
function getHTTPClient() {
    $headerAttributes = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_SUCURI_CLIENTIP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($headerAttributes as $attribute) {
        if ( ! empty($_SERVER[$attribute])) {
            return $_SERVER[$attribute];
        }
    }
    return null;
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
 * If a search query was requested from the top page nav then redirect
 * to the search page to handle the request.
 */
function processSearchRequest() {
    global $search;
    $search = getPostOrRequestVar('q', null);
    if ($search != null) {
        header('location:/games/?q=' . $search);
        exit;
    }
}

/**
 * A function to look at a string and determine if it appears to be a URL, by
 * the patterns /, ./, http://, https://.
 * @param string $proposedURL A string to examine.
 * @return boolean true if it matches an expected URL pattern, false if it does not.
 */
function looksLikeURLPattern($proposedURL) {
    if ($proposedURL[0] == '/'
    || substr_compare($proposedURL, './', 0, 2) === 0
    || substr_compare($proposedURL, 'https://', 0, 8) === 0
    || substr_compare($proposedURL, 'http://', 0, 7) === 0) {
        return true;
    }
    return false;
}

/**
 * Convert a string to a "slug". The result string can be used as a DOM id, a path part, or a safe string.
 * Rules:
 *   * Only allow A-Z, a-z, 0-9, dash, space.
 *   * Trim any leading or trailing space.
 *   * Only lowercase characters.
 *   * Max length 50.
 * @param $string
 * @return {string}
 */
function stringToSlug($string) {
    $separator = '-';
    $sluggish = strtolower(preg_replace('/[^A-Za-z0-9-]+/', $separator, trim($string)));
    $sluggish = substr(trim(preg_replace('/-{2,}/', $separator, $sluggish)), 0, 50);
    return $sluggish;
}

/**
 * Decode a base64 encoded string into its binary representation.
 * @param string $data A string of translated base-64 characters to translate back to binary.
 * @return string A binary string.
 */
function base64Decode($data) {
    return base64_decode(base64URLDecode($data));
}

/**
 * Replace base-64 chars that are not URL safe. This will help transmit a base-64 string
 * over the internet by translating '-_~' into '+/='.
 * @param string $data A string of translated base-64 characters to translate back to true base-64.
 * @return string Translates '-_~' found in $data to '+/='.
 */
function base64URLDecode($data) {
    return strtr($data, ['-' => '+', '_' => '/', '~' => '=']);
}

/**
 * Translate a string of data (possibly binary) into its base-64 representation. In
 * additions, this also makes the string URL safe by translating '+/=' into '-_~'.
 * Use `base64URLDecode` to get it back to true base-64.
 * @param string $data A string to translate into base-64 representation.
 * @return string Translated string represented as base-64 with URL safe ('+/=' is '-_~').
 */
function base64Encode($data) {
    return base64URLEncode(base64_encode($data));
}

/**
 * Replace base-64 chars that are not URL safe. This will help transmit a base-64 string
 * over the internet by translating '+/=' into '-_~'.
 * @param string $data A string of base-64 characters to translate.
 * @return string Translates '+/=' found in $data to '-_~'.
 */
function base64URLEncode($data) {
    return strtr($data, ['+' => '-', '/' => '_', '=' => '~']);
}

/**
 * Encrypt a string of data with a hexadecimal key using AES 256 CBC mode.
 * @param string $data A clear string of data to encrypt.
 * @param string $key The encryption key, represented as a string of at least 32 hexadecimal digits.
 * @return string The encrypted data. An empty string if an error occurred.
 */
function encryptString($data, $key) {
    global $enginesisLogger;
    if (empty($data) || empty($key)) {
        return '';
    }
    $sslOptions = 0;
    $keyLength = strlen($key);
    if ($keyLength < 32) {
        $key = str_repeat($key, ceil(32 / $keyLength));
    }
    $iv = substr($key, 3, 16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, $sslOptions, $iv);
    if ($encrypted !== false) {
        return $encrypted;
    } else {
        $enginesisLogger->log("openssl_encrypt error $key ($data)", LogMessageLevel::Error, 'SYS', __FILE__, __LINE__);
        return '';
    }
}

/**
 * Decrypt a string of data that was encrypted with `encryptString()` using the same key.
 *
 * @param string $data An encrypted string of data to decrypt.
 * @param string $key The encryption key, represented as a hex string. Key should be 32 hex digits.
 * @return string The clear string that was originally encrypted.
 */
function decryptString($data, $key) {
    if (empty($data) || empty($key)) {
        return '';
    }
    $sslOptions = 0;
    $keyLength = strlen($key);
    if ($keyLength < 32) {
        $key = str_repeat($key, ceil(32 / $keyLength));
    }
    $iv = substr($key, 3, 16);
    return openssl_decrypt($data, 'AES-256-CBC', $key, $sslOptions, $iv);
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
 * @return string the hashed password.
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
 * @return bool true if the password is a match. false if password does not match.
 */
function verifyHashPassword ($password, $hashStoredInDatabase) {
    return ! empty($password) && ! empty($hashStoredInDatabase) && $hashStoredInDatabase == crypt($password, $hashStoredInDatabase);
}

/**
 * Get any web page on the WWW and return its contents as a string
 * @param string is the URL to contact without any query string (use $get_params)
 * @param array GET parameters are key => value arrays
 * @param array POST parameters as a key => value array.
 * @param array Array of additional HTTP headers to set.
 * @return string|null the web page content as a string. Returns null if the request failed.
 */
function getURLContents ($url, $get_params = null, $post_params = null, $headers = null) {
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
    if ($ch) {
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, getSiteName() . ' ' . getServiceVersion());
        curl_setopt($ch, CURLOPT_REFERER, getCurrentDomain());
        if ($post_string != '') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        }
        if ($headers && count($headers) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (startsWith(strtolower($url), 'https://')) {
            $sslCertificate = SERVER_PRIVATE_PATH . 'cacert.pem';
            if (file_exists($sslCertificate)) {
                curl_setopt($ch, CURLOPT_CAINFO, $sslCertificate);
            } else {
                reportError("Cant locate private cert $sslCertificate", __FILE__, __LINE__, 'getURLContents');
            }
        }
        $page = curl_exec($ch);
        $curlError = curl_errno($ch);
        curl_close($ch);
        if ($curlError != 0 || $page === false) {
            echo('Network error ' . $curlError . ': ' . curl_strerror($curlError) . ' requesting ' . $url);
            // the curl call itself failed, usually due to no network or SSL cert failure.
            reportError('Network error ' . $curlError . ': ' . curl_strerror($curlError) . ' requesting ' . $url, __FILE__, __LINE__, 'getURLContents');
            $page = null;
        }
    } else {
        $page = null;
    }
    return $page;
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
    $isValid = version_compare(phpversion(), '8.1.0', '>=');
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
 * Get the full HTTP referrer domain we are currently running on. It should return
 * a http protocol with service domain with its current stage.
 * @return string Server domain.
 */
function getCurrentDomain() {
    return getServiceProtocol() . '://' . domainForTargetStage(serverStage(), ENGINESIS_SITE_DOMAIN);
}

/**
 * Return the name of the current site.
 * @return string Site name.
 */
function getSiteName() {
    return ENGINESIS_SITE_NAME;
}

/**
 * Return the host name of the server we are running on. e.g. www.enginesis-q.com
 * @return string server host name only, e.g. www.enginesis.com.
 */
function serverName () {
    $serverName = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'enginesis-l.com');
    if (strpos($serverName, ':') !== false ) {
        $serverName = substr($serverName, 0, strpos($serverName, ':'));
    }
    return $serverName;
}

/**
 * Return the domain name and TLD only (remove server name, protocol, anything else) e.g. this function
 * converts http://www.games.com into games.com or http://www.games-q.com into games-q.com
 * @param string $serverName
 * @return null|string
 */
function serverTail ($serverName = '') {
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
 * www.enginesis-q.com and the $targetStage is -l, return www.enginesis-l.com.
 * @param string $targetStage one of -l, -d, -x, -q, or '' for live.
 * @param string|null $hostName A host name to check, or if not provided then the current host. This is a domain, not a URL.
 * @return string The qualified host name.
 */
function domainForTargetStage($targetStage, $hostName = null) {
    if (empty($hostName)) {
        $hostName = serverName();
    }
    // find the tld
    $lastDot = strrpos($hostName, '.');
    if ($lastDot === false) {
        // no .tld!
        $domain = $hostName;
    } else {
        $domain = substr($hostName, 0, $lastDot);
        $tld = substr($hostName, $lastDot + 1);
        $domain = preg_replace('/-[ldqx]$/', '', $domain) . $targetStage . '.' . $tld;
    }
    return $domain;
}

/**
 * Parse the given host name to determine which stage we are currently running on.
 * @param string $hostName Host name or domain name to parse. If null we try the current `serverName()`.
 * @return string the -l, -d, -q, -x part, or '' for live.
 */
function serverStage($hostName = '') {
    // assume live until we prove otherwise
    $targetPlatform = '';
    if (empty($hostName)) {
        $hostName = serverName();
    }
    if (preg_match('/-[dlqx]\./i', $hostName, $matchedStage)) {
        $targetPlatform = strtolower(substr($matchedStage[0], 0, 2));
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

/**
 * Make an object of key/value parameters to send to an Enginesis service from an array of
 * key/value parameters. This also sanitizes the parameters. Parameters prefixed with - are
 * considered optional and are only sent to the service if a value is provided.
 * @param string $fn The service to call.
 * @param integer $site_id The site-id.
 * @param Array An array of key/values to send to the service.
 * @return Array A complete and clean array of parameters to send to the service.
 */
function enginesisParameterObjectMake ($fn, $site_id, $parameters) {
    global $sync_id;
    $serverParams = [];
    $serverParams['fn'] = $fn;
    $serverParams['site_id'] = $site_id;
    $serverParams['state_seq'] = ++ $sync_id;
    $serverParams['response'] = 'json';
    foreach ($parameters as $key => $value) {
        if ($key[0] == '-') {
            if (isEmpty($value)) {
                // @todo: if the parameter is not provided then don't send it, but this means you cannot ever set a string to empty so this is probably not the desired behavior.
                continue;
            }
            $key = substr($key, 1);
        }
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
        mt_srand((double)microtime() * 9057254886133);
    }
    $i = 0;
    $string = '';
    if ($maxCodePoint < 10) {
        $maxCodePoint = 10;
    } elseif ($maxCodePoint > strlen($chars)) {
        $maxCodePoint = strlen($chars);
    }
    while ($i < $length) {
        $string = $string . substr($chars, mt_rand() % $maxCodePoint, 1);
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
    $expirationTime = 30; // 30 minutes. @todo: Is this a reasonable amount of time?
    $hackerToken = md5($enginesis->getServerName()) . '' . floor(time() / ($expirationTime * 60));
    return $hackerToken;
}

/**
 * Given a token from an input form check to verify it has not yet expired.
 * @param string $token generated with makeInputFormHackerToken.
 * @return boolean true when the token is good.
 */
function validateInputFormHackerToken ($token) {
    return makeInputFormHackerToken() == $token;
}

/**
 * Helper function to verify the form hack prevention are verified. There are two checks performed on a
 * page that has an input form that tends to get hacked by bots:
 *
 * 1. An input field that asks for an email address, but we expect it to be empty. A real user will not enter a value in this field (typically it is hidden.)
 * 2. A time-out token is placed in a hidden field in the form. If the form is submitted after this timer times out we reject the submission (took too long.)
 *
 * @param array $inputFormNames an array of field names used on the current page form to check for the inputs.
 *   Order dependent. By default we use 'emailaddress' and 'all-clear'.
 *   'emailaddress' is a form input that is a honeypot, we expect this to be empty, but a hacker would be compelled to fill in a value.
 *   'all-clear' is a form field that holds the timeout token generated from `makeInputFormHackerToken()`.
 * @return boolean a `true` value indicates the form passes the checks, and `false` indicates a possible hack attempt.
 */
function verifyFormHacks($inputFormNames) {
    if ($inputFormNames == null || $inputFormNames == []) {
        $inputFormNames = ['emailaddress', 'all-clear'];
    }
    $thisFieldMustBeEmpty = isset($_POST[$inputFormNames[0]]) ? $_POST[$inputFormNames[0]] : 'hacker';
    $hackerToken = isset($_POST[$inputFormNames[1]]) ? $_POST[$inputFormNames[1]] : '0';
    $isVerified = $thisFieldMustBeEmpty === '' && validateInputFormHackerToken($hackerToken);
    if ( ! $isVerified) {
        debugError("verifyFormHacks check failed from " . getHTTPClient() . " token=$hackerToken (try " . makeInputFormHackerToken() . ") honeypot='$thisFieldMustBeEmpty'");
    }
    return $isVerified;
}

/**
 * Helper function to determine if the current session is valid. What we are looking for:
 *   1. user id matches token
 *   2. token not expired
 * @param $userId
 * @param $authToken
 * @return boolean True if the session is valid.
 */
function verifySessionIsValid($userId, $authToken) {
    // @todo: We need to write the code for this!
    return true;
}

/**
 * Search $text for tokens in the form %#% and replace them with their respective function arguments.
 * Counting starts at 1 (because $text is item 0) and we expect to find at least as many function arguments
 * as there are references in $text. Example:
 *    $updatedText = tokenArgsReplace ( "This %1% is a %2% %1%.", "sandwich", "turkey" )
 * will return "This sandwich is a turkey sandwich."
 * @param string $text String of text to search and replace.
 * @return string Replaced text
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
 *    $updatedText = tokenReplace ( "This %food% is a %meat% %food%.", ["food" => "sandwich", "meat" => "turkey"] )
 * will return "This sandwich is a turkey sandwich."
 * @param string $text String of text to search and replace.
 * @param Array $parameters Array of key/value pairs to replace in $text.
 * @return string Replaced text
 */
function tokenReplace ($text, $parameters) {
    if ( ! empty($text) && is_array($parameters) && count($parameters) > 0) {
        foreach ($parameters as $token => $value) {
            $token = "%$token%";
            if ($value === null) {
                $value = '';
            }
            if (stripos($text, $token) !== false) {
                $text = str_replace($token, $value, $text);
            }
        }
    }
    return $text;
}

/**
 * Convert an array into a string.
 * @param Array $array
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
 * Determine if a variable is considered empty. This goes beyond PHP empty() function to support SQL and JavaScript
 * possibilities.
 *
 * @param any $value A variable to test for emptiness.
 * @return boolean True if considered empty, false if considered not empty.
 */
function isEmpty ($value) {
    if (isset($value)) {
        if (is_numeric($value)) {
            return $value == 0;
        } elseif (is_string($value)) {
            return (strlen($value) == 0 || $value == 'undefined' || strtolower($value) == 'null');
        } elseif (is_array($value)) {
            return count($value) == 0;
        } else {
            return is_null($value);
        }
    } else {
        return true;
    }
}

/**
 * Determine if a string begins with a specific string. This does exact match so it is case sensitive.
 *
 * @param string The string to search against.
 * @param string|array The string to search for in $haystack.
 * @return boolean true if $haystack starts with $needle.
 */
function startsWith($haystack, $needle) {
    if (is_array($needle)) {
        for ($i = 0; $i < count($needle); $i += 1) {
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
 * Determine if a string ends with a specific string.
 *
 * @param string String to consider.
 * @param string|array What to match in $haystack.
 * @return boolean true if $haystack ends with $needle.
 */
function endsWith($haystack, $needle) {
    if (is_array($needle)) {
        for ($i = 0; $i < count($needle); $i += 1) {
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
 * @param string A string to transform.
 * @return string The transformed string.
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
 * Determine if a string has any single character of a string of select characters.
 *
 * @param string $string string to check
 * @param string|Array $selectChars string of individual characters to check if contained in $string.
 *     If an array of strings, checks each string to determine if the entire string (case sensitive) is contained in $string.
 * @param int $start start position in $string to begin checking, default is the beginning.
 * @param int $length ending position in $string to stop checking, default is the end.
 * @return boolean true if at least one character in $selectChars is also in $string, otherwise
 *     false if none of $selectChars are in $string.
 */
function str_contains_char ($string, $selectChars, $start = 0, $length = 0) {
    if ($length == 0) {
        $length = strlen($string);
    }
    if ($start < 0) {
        $start = 0;
    }
    if (is_string($selectChars)) {
        for ($i = $start; $i < $length; $i ++) {
            if (strpos($selectChars, $string[$i]) !== false) {
                return true;
            }
        }
    } elseif (is_array($selectChars)) {
        // @todo: End is not considered
        for ($i = 0; $i < count($selectChars); $i ++) {
            if (strpos($string, $selectChars[$i], $start) !== false) {
                return true;
            }
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
 * @param any $variable - any type will be coerced to a boolean value.
 * @return boolean
 */
function valueToBoolean($variable) {
    if (is_string($variable)) {
        $variable = strtoupper($variable);
        $result =  $variable == '1' || $variable == 'Y' || $variable == 'T' || $variable == 'YES' || $variable == 'TRUE' || $variable == 'CHECKED' || $variable == 'ON';
    } elseif (is_numeric($variable)) {
        $result = ! ! $variable;
    } else {
        $result = $variable != null;
    }
    return $result;
}

/**
 * Convert an integer value to its boolean representation. A value is considered true
 * if it is not 0.
 *
 * @param integer $value A value to interpret as a boolean value.
 * @return boolean True if $value is interpreted to be a true value, otherwise false.
 */
function castIntToBool ($value) {
    return castValueToBool($value);
}

/**
 * Convert a value to its boolean representation. A value is considered true
 * if it is "true|t|yes|y|1" or evaluates to a true value, such as a non-0 integer.
 *
 * @param integer|string $value A value to interpret as a boolean value.
 * @return boolean True if $value is interpreted to be a true value, otherwise false.
 */
function castValueToBool ($value) {
    if (is_string($value)) {
        $value = strtolower($value);
        return ! ($value === 'f' || $value === 'n' || $value === 'false' || $value === 'no' || $value === 'off' || $value === '0');
    } else {
        return $value != 0;
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
 *
 * @param mixed $value Any value to test, it will be coerced to a boolean.
 * @param string $trueValue The value to return if $value is considered true. Default is 'true'.
 * @param string $falseValue The value to return if $value is considered false. Default is 'false'.
 * @return string
 */
function castBoolToString($value, $trueValue = 'true', $falseValue = 'false') {
    return $value ? $trueValue : $falseValue;
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
 * @param string The user name to check.
 * @return bool true if acceptable otherwise false.
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
 * Performs basic user password validation. The password can be any printable characters between 8 and 32 in length
 * with no leading or trailing spaces.
 * @param string $password The password to check.
 * @return bool true if acceptable otherwise false.
 */
function isValidPassword ($password) {
    if (empty($password)) {
        return false;
    }
    $minPasswordLength = 8;
    $maxPasswordLength = 32;
    $len = strlen(trim($password));
    return $len == strlen($password) && ctype_graph($password) && $len >= $minPasswordLength && $len <= $maxPasswordLength;
}

/**
 * Make sure a proposed gender value is valid. THis is intended to be used to validate forms and user input and make
 * certain we have a value our system can deal with.
 * @param $gender {string} a proposed value for gender, either a single character M, F, or N, or a word Male, Female, or Neutral.
 * @return string One of the gender setting we will accept.
 * @todo: This should be localized, so move the possible names table into a lookup table.
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
 * @param string $email an email address to check
 * @return bool true if we think the email address looks valid, otherwise false.
 */
function checkEmailAddress ($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Clean extended characters out of the string. This helps sanitize strings for general
 * display cases. For example, clean up a Microsoft Word copyied string for more general
 * usage. Extended characters converted to their common ascii equivalent.
 *
 * @param string $input A string to clean.
 * @return string The $input string with any extended characters converted to their common ascii equivalent.
 */
function cleanString ($input) {
    if (empty($input)) {
        return '';
    }
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

/**
 * Remove non-ASCII extended characters, remove new lines, strip HTML tags, convert HTML to entities,
 * and trim leading and trailing white space.
 *
 * @param string $source The string to clean.
 * @return string The source string cleaned of all bad characters.
 */
function fullyCleanString($source) {
    return htmlspecialchars(trim(str_replace("\n", '', strip_tags(cleanString($source)))));
}

/**
 * Clean a proposed file name of any undesired characters and return a nice file name.
 *
 * @param string $fileName A proposed file name.
 * @return string Proposed file name with undesired characters removed.
 */
function cleanFileName ($fileName) {
    if (empty($fileName)) {
        return '';
    }
    return str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|', '`', '\''], '', $fileName);
}

/**
 * Strip HTML tags and javascript handlers from the source string.
 *
 * @param string $source A source string to clean of any HTML tags.
 * @param array $allowedTags An array of strings indicating any HTML tags that are allowed and should not be stripped.
 *    Tags must be specified with the angle braces, such as "<div>". Close tags are not required.
 * @param array $disabledAttributes An array of tag attributes that are to be stripped.
 * @return string A version of $source with HTM tags and indicated attributes removed.
 */
function stripTagsAttributes ($source, $allowedTags = [], $disabledAttributes = ['onclick', 'ondblclick', 'onkeydown', 'onkeypress', 'onkeyup', 'onload', 'onmousedown', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onunload']) {
    if (empty($disabledAttributes)) {
        return strip_tags($source, implode('', $allowedTags));
    } else {
        return preg_replace('/<(.*?)>/i', "'<' . preg_replace(array('/javascript:[^\"\']*/i', '/(" . implode('|', $disabledAttributes) . ")=[\"\'][^\"\']*[\"\']/i', '/\s+/'), array('', '', ' '), stripslashes('\\1')) . '>'", strip_tags($source, implode('', $allowedTags)));
    }
}

/**
 * Filter bad or undesirable words from a proposed string.
 * @todo: This needs work it doesn't seem to be all that useful.
 *
 * @param string $strTest A reference to a string to filter. Recognized bad words are replaced with '*'. This string is replaced with the filtered string.
 * @return boolean True if a filter was performed, false if no filtering was required.
 */
function profanityFilter ( & $strTest) {
    $original = substr($strTest, 0);
    $filtered = substr($strTest, 0);
    $fullwordlistban = 'ass|asshole|pussy';
    $partialwordlistban = 'fuck|cunt|shit|dick|bitch|penis';
    $filtered = preg_replace("/\b($fullwordlistban)\b/i", '*', $filtered);
    $filtered = preg_replace("/($partialwordlistban)/i", '*', $filtered);
    if ($filtered == $original) {
        return false;
    } else {
        $strTest = $filtered;
        return true;
    }
}

/**
 * In order to provide some flexibility with dates, our API will accept a PHP date, a Unix timestamp,
 * a date string, or null. This function will try to figure our what date was provided and convert what ever
 * it is into a valid MySQL date string. If null it returns the current date-time.
 * @param mixed $phpDate mixed One of PHP Date, integer, a string, or null.
 * @param boolean $includeTime True to include the time in the return value.
 * @return string A valid MySQL date
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

/**
 * Convert MySQL date to Unix timestamp.
 * @param string $mysqlDate A MySQL date string in the form YYYY-MM-DD.
 * @return int A unix timestamp.
 */
function MySQLDateToDate ($mysqlDate) {
    return strtotime($mysqlDate);
}

/**
 * Given a MySQL date string return a human readable date string.
 * MySQL date is YYYY-mm-dd convert it to mm/dd/yyyy.
 * @todo: this needs to be locale aware.
 * @param string $mysqlDate A MySQL date string YYYY-mm-dd.
 * @return string Formatted date mm/dd/yyyy.
 */
function MySQLDateToHumanDate ($mysqlDate) {
    if (empty($mysqlDate) || $mysqlDate == 'NULL') {
        $mysqlDate = DateToMySQLDate(null, false);
    }
    return substr($mysqlDate, 5, 2) . '/' . substr($mysqlDate, 8, 2) . '/' . substr($mysqlDate, 0, 4);
}

/**
 * Convert a human readable date format mm/dd/yyyy to a MySQL date format of YYYY-mm-dd.
 * It also tries to deal with mm/dd/yy, I'm not sure how useful that is anymore (it was back in 1999 when we wrote this).
 * @todo: this needs to be locale aware.
 * @param string $humanDate A date string in the form mm/dd/yyy.
 * @return string A MySQL date in the form YYY-MM-DD.
 */
function HumanDateToMySQLDate ($humanDate) {
    $dateParts = explode('/', $humanDate, 3);
    if (strlen($dateParts[0]) < 2) {
        $dateParts[0] = '0' . $dateParts[0];
    }
    if (strlen($dateParts[1]) < 2) {
        $dateParts[1] = '0' . $dateParts[1];
    }
    if (strlen($dateParts[2]) < 3) {
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
 * @param string $htmlHexColorValue An HTML color value such as #445566 or just 445566.
 * @return boolean True if the color value is considered dark.
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
 * Generate a time stamp for the current time rounded to the nearest SESSION_DAYSTAMP_HOURS hour.
 * This is used for access tokens as they are short-lived.
 * @return int
 */
function sessionDayStamp () {
    return floor(time() / (SESSION_DAYSTAMP_HOURS * 60 * 60));
}

/**
 * Generate a (hopefully) unique site mark. This is a pseudo-user-id to accommodate anonymous users who
 * use the site and we need to generate a unique session id on their behalf and not have it clash with
 * any other anonymous user on the site in this day-stamp window of time.
 * @return int A mock user-id. Should be a minimum of 6 digits.
 */
function makeSiteMark() {
    return mt_rand(187902, mt_getrandmax());
}

/**
 * Return the HTTP authorization headers. This is where we expect to find our authentication token.
 * @return {string|null} The authorization header, or null if it was not sent in this request.
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
 * @return {string|null} the HTTP bearer token or null if it was not sent.
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
 * Return a local file path to a resource given its URL.
 * @param string $url any URL that should be valid on the current site.
 * @return string A file path to that resource.
 */
function urlToFilePath ($url) {
    if (empty($url)) {
        $url = '/';
    }
    $urlParts = parse_url($url);
    $urlPath = $urlParts['path'];
    if ($urlPath[0] == '/') {
        $urlPath = substr($urlPath, 1);
    }
    $filePath = SERVER_ROOT . $urlPath;
    return $filePath;
}

/**
 * Generate a random string of base64 characters of the requested length. I have no
 * idea where this algorithm came from or how effective it is.
 * @param int $length
 * @return string The requested string length of characters [/0-9A-Za-z\-\.]+/
 */
function makeRandomToken ($length = 12) {
    $token = '';
    for ($i = 0; $i < $length; ++ $i) {
        if ($i % 2 == 0) {
            mt_srand(time() % 2147 * 1000000 + (double) microtime() * 1000000);
        }
        $rand = 48 + mt_rand() % 64; // 48 is "0"
        if ($rand > 57) {
            $rand += 7; // move to "A"
        }
        if ($rand > 90) {
            $rand += 6; // move to "a"
        }
        if ($rand == 123) {
            $rand = 45; // "-"
        } elseif ($rand == 124) {
            $rand = 46; // "."
        }
        $token .= chr($rand);
    }
    return $token;
}

/**
 * Append a URL parameter if the value is not empty.
 *
 * @param string The URL string to update. This string updated if the value is not empty.
 * @param string A key.
 * @param string The value to assign to the key.
 * @return string the update URL string.
 */
function appendParamIfNotEmpty( & $params, $key, $value) {
    if ( ! empty($key) && ! empty($value)) {
        $params .= '&' . $key . '=' . $value;
    }
    return $params;
}

/**
 * If the flag parameter is determined to be true (implicit cast to bool) then return a checkbox string.
 * @param boolean True for checked, false for not checked.
 * @return string Checked or empty.
 */
function showBooleanChecked($flag) {
    return $flag ? 'checked' : '';
}

/**
 * Render a PHP key/value associative array into JavaScript code
 * that produces a similar object.
 * @param object The associated k/v array.
 * @param string The name of the javascript variable to use. if not provided will default to "parameters".
 */
function arrayToJavaScriptObject ($parameters, $varName) {
    if (empty($varName)) {
        $varName = 'parameters';
    }
    $javaScriptCode = "const $varName = {\n";
    foreach ($parameters as $property => $value) {
        $javaScriptCode .= "        $property: \"$value\",\n";
    }
    $javaScriptCode .= "    };\n";
    echo($javaScriptCode);
}

/**
 * Pack a unique object identifier site-id, content-type-id, and object-id.
 * The content id is a sequence of base 36 numbers, and we convert the base 36 characters to uppercase
 * to maintain compatibility between PHP and MySQL.
 * @param integer $site_id The object's site-id.
 * @param integer $content_type_id The object's content type.
 * @param integer $object_id The object identifier.
 * @return string A content identifier.
 */
function contentIdPack($site_id, $content_type_id, $object_id) {
    // @todo: should we verify $site_id, $content_type_id, $object_id are actually valid?
    $site_id_str = base_convert($site_id, 10, 36);
    $content_type_id_str = base_convert($content_type_id, 10, 36);
    $object_id_str = base_convert($object_id, 10, 36);
    $content_id = base_convert(strlen($site_id_str), 10, 36)
        . base_convert(strlen($content_type_id_str), 10, 36)
        . base_convert(strlen($object_id_str), 10, 36)
        . $site_id_str
        . $content_type_id_str
        . $object_id_str
        ;
    return strtoupper($content_id);
}

/**
 * Unpack a content identifier into it parts: site-id, content-type-id, and object-id.
 * @param string $content_id A content identifier to unpack.
 * @param integer $site_id The object's site-id.
 * @param integer $content_type_id The object's content type.
 * @param integer $object_id The object identifier.
 * @return boolean True if successful ($content_id is valid), or false if not able to unpack.
 */
function contentIdUnpack($content_id, & $site_id, & $content_type_id, & $object_id) {
    if (empty($content_id) || strlen($content_id) < 4) {
        return false;
    }
    $index = 0;
    $content_id = strtoupper($content_id);
    $site_id_length = intval(substr($content_id, $index, 1), 36);
    $index += 1;
    $content_type_length = intval(substr($content_id, $index, 1), 36);
    $index += 1;
    $object_id_length = intval(substr($content_id, $index, 1), 36);
    $index += 1;
    if ($site_id_length < 1
     || $content_type_length < 1
     || $object_id_length < 1
     || ($site_id_length + $content_type_length + $object_id_length != (strlen($content_id) - $index))) {
        return false;
    }
    $site_id = intval(substr($content_id, $index, $site_id_length), 36);
    $index += $site_id_length;
    $content_type_id = intval(substr($content_id, $index, $content_type_length), 36);
    $index += $content_type_length;
    $object_id = intval(substr($content_id, $index, $object_id_length), 36);
    return true;
}

/**
 * Log a message to the logging utility.
 * @param string Message to log.
 */
function debugLog($message) {
    global $enginesisLogger;
    $enginesisLogger->log($message, LogMessageLevel::Info, 'System');
}

/**
 * Log an informational message to the logging utility.
 * @param string Message to log.
 */
function debugInfo($message) {
    global $enginesisLogger;
    $enginesisLogger->log($message, LogMessageLevel::Info, 'System');
}


/**
 * Log an error message to the logging utility.
 * @param string Message to log.
 */
function debugError($message) {
    global $enginesisLogger;
    $enginesisLogger->log($message, LogMessageLevel::Error, 'System');
}

/**
 * Return a printable version of a variable, array, or object.
 * @param Any $value Any PHP variable to consider.
 * @return string A string representation of $value.
 */
function debugToString($value) {
    return json_encode($value);
}

/**
 * Return a string of parameter key/value pairs, but remove any sensitive information from the output.
 * @param Array An array or object of key/value pairs to log.
 * @return string A string representation of the parameters.
 */
function logSafeParameters($parameters) {
    $sensitiveParameters = ['authtok', 'authtoken', 'token', 'refresh_token', 'password', 'secondary_password', 'apikey', 'developer_key'];
    $logParams = '';
    if (is_array($parameters) && count($parameters) > 0) {
        foreach ($parameters as $key => $value) {
            if (in_array($key, $sensitiveParameters)) {
                $value = 'XXXXX';
            }
            $logParams .= (strlen($logParams) > 0 ? ', ' : 'parameters: ') . $key . '=' . $value;
        }
    }
    return $logParams;
}

// "Global" PHP variables available to all scripts. See also serverConfig.php.
$enginesisLogger = new LogMessage([
    'log_active' => true,
    'log_level' => LogMessageLevel::All,
    'log_to_output' => false,
    'log_to_file' => true,
    'log_file_path' => SERVER_DATA_PATH . 'logs' . DIRECTORY_SEPARATOR
]);
$page = '';
$webServer = '';
$enginesis = new Enginesis($siteId, null, ENGINESIS_DEVELOPER_API_KEY, 'reportError');
$enginesis->setCMSKey(ENGINESIS_CMS_API_KEY, $CMSUserLogins[0]['user_name'], $CMSUserLogins[0]['password']);
$serverName = $enginesis->getServerName();
$serverStage = $enginesis->getServerStage();
$enginesisServer = $enginesis->getServiceRoot();
// turn on errors for all stages except LIVE
setErrorReporting($serverStage != '');
$isLoggedIn = $enginesis->isLoggedInUser();
if ($isLoggedIn) {
    $userId = $enginesis->getUserId();
    $authToken = $enginesis->getAuthToken();
} else {
    $userId = 0;
    $authToken = '';
}
if (isset($_MAIL_HOSTS)) {
    ini_set('SMTP', $_MAIL_HOSTS[$serverStage]['host']);
}
require_once('common-site.php');
processTrackBack();
