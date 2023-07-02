<?php
/**
 * Enginesis service object for PHP clients. Support for each Enginesis API and additional helper functions.
 * @author: varyn
 * @since: 13-Feb-2016
 */

if ( ! defined('ENGINESIS_VERSION')) {
    define('ENGINESIS_VERSION', '2.6.14');
}
require_once('EnginesisErrors.php');
if ( ! defined('SESSION_COOKIE')) {
    define('SESSION_COOKIE', 'engsession');
    define('REFRESH_COOKIE', 'engrefreshtoken');
    define('SESSION_USERINFO', 'engsession_user');
    define('SESSION_DAYSTAMP_HOURS', 48);
    define('SESSION_EXPIRE_SECONDS', 86400);   // Sessions expire in 1 day
    define('SESSION_USERID_CACHE', 'engsession_uid');
}

abstract class EnginesisNetworks {
    const Enginesis = 1;
    const Facebook = 2;
    const Google = 7;
    const Twitter = 11;
    const Apple = 14;
}

abstract class EnginesisRefreshStatus {
    const valid = 1;      // authentication token is valid.
    const refreshed = 2;  // user had a valid refresh token and we used it to get a new authentication token.
    const expired = 3;    // user had a valid token but it has expired.
    const invalid = 4;    // had a token but we were not able to validate it.
    const missing = 9;    // no token to validate, or possibly an invalid token.
}

class Enginesis {
    private $m_server;
    private $m_serviceHost;
    private $m_serviceProtocol;
    private $m_serviceEndPoint;
    private $m_avatarEndPoint;
    private $m_lastError;
    private $m_siteId;
    private $m_gameId;
    private $m_gameGroupId;
    private $m_isLoggedIn;
    private $m_userId;
    private $m_siteUserId;
    private $m_networkId;
    private $m_userName;
    private $m_userAccessLevel;
    private $m_favoriteGames;
    private $m_favoriteGamesNextCheck;
    private $m_stage;
    private $m_syncId;
    private $m_responseFormat;
    private $m_debug;
    private $m_debugFunction;
    private $m_developerKey;
    private $m_cmsCredentials;
    private $m_languageCode;
    private $m_authToken;
    private $m_authTokenWasValidated;
    private $m_refreshToken;
    private $m_refreshedUserInfo;
    private $m_tokenStatus;
    private $m_serverPaths = [
        'DATA'     => '',
        'PRIVATE'  => '',
        'PUBLIC'   => '',
        'SERVICES' => ''
    ];

    /**
     * Set up the Enginesis environment so it is able to easily make service requests with the server.
     *
     * @param int $siteId Your assigned site id.
     * @param string $enginesisServer which Enginesis server you want to connect with. Leave empty or '*' to match current stage.
     * @param string $developerKey Your developer key. Must be set before any API calls can be made, but can be set later with setDeveloperKey().
     * @param string $debugFunction A function to call for debug logging, defaults to NULL. What is the signature of this function?
     */
    public function __construct ($siteId, $enginesisServer, $developerKey, $debugFunction = null) {
        // @todo: Should initialize to false and only set when requested.
        $this->m_debug = true;
        $this->m_server = $this->serverName();
        $this->m_stage = $this->serverStage($this->m_server);
        $this->m_lastError = Enginesis::noError();
        $this->m_siteId = $siteId;
        $this->m_userId = 0;
        $this->m_userName = '';
        $this->m_siteUserId = null;
        $this->m_networkId = 1;
        $this->m_userAccessLevel = 0;
        $this->m_favoriteGames = null;
        $this->m_favoriteGamesNextCheck = null;
        $this->m_isLoggedIn = false;
        $this->m_refreshedUserInfo = null;
        $this->m_syncId = 0;
        $this->m_gameId = 0;
        $this->m_gameGroupId = 0;
        $this->m_responseFormat = 'json';
        $this->m_debugFunction = null;
        $this->m_developerKey = $developerKey;
        $this->m_cmsCredentials = [];
        $this->m_languageCode = 'en';
        $this->m_authToken = null;
        $this->m_authTokenWasValidated = false;
        $this->m_refreshToken = null;
        $this->m_tokenStatus = EnginesisRefreshStatus::missing;
        $this->setServerPaths();
        $this->setServiceHost($enginesisServer);
        $serviceRoot = $this->m_serviceProtocol . '://' . $this->m_serviceHost;
        $this->m_serviceEndPoint = $serviceRoot . '/index.php';
        $this->m_avatarEndPoint = $serviceRoot . '/avatar/';
        if ($debugFunction != null) {
            $this->setDebugFunction($debugFunction);
        }
        $this->restoreUserFromAuthToken(null);
    }

    /**
     * Free any references before destructing the object.
     * @method destructor
     */
    public function __destruct () {
        $this->reset();
    }

    /**
     * Reset the Enginesis object state to initial conditions.
     */
    private function reset () {
        $this->m_server = $this->serverName();
        $this->m_stage = $this->serverStage($this->m_server);
        $this->m_lastError = Enginesis::noError();
        $this->m_userId = 0;
        $this->m_isLoggedIn = false;
        $this->m_syncId = 0;
        $this->m_serviceProtocol = $this->getServiceProtocol();
    }

    /**
     * Setup the paths we expect to find on the server so we don't need code at
     * runtime to determine these things.
     */
    private function setServerPaths() {
        $guessRootPath = $_SERVER['DOCUMENT_ROOT'] . '/';
        $this->m_serverPaths = [
            'DATA'     => defined('SERVER_DATA_PATH') ? SERVER_DATA_PATH : $guessRootPath . '../data/',
            'PRIVATE'  => defined('SERVER_PRIVATE_PATH') ? SERVER_PRIVATE_PATH : $guessRootPath . '../private/',
            'PUBLIC'   => defined('ROOTPATH') ? ROOTPATH : $guessRootPath,
            'SERVICES' => defined('SERVICE_ROOT') ? SERVICE_ROOT : $guessRootPath . '../services/'
        ];
    }

    /**
     * Create a failed response for cases when we are going to fail locally without transaction
     * with the server.
     */
    private function makeErrorResponse($errorCode, $errorMessage, $parameters) {
        $service = isset($parameters['fn']) ? $parameters['fn'] : 'UNKNOWN';
        $stateSequence = isset($parameters['stateSeq']) ? $parameters['stateSeq'] : 0;
        $enginesisResponse = '{"results":{"status":{"success":"0","message":"' . $errorCode . '","extended_info":"' . $errorMessage . '"},"passthru":{"fn":"' . $service . '","state_seq":' . $stateSequence . '}}}';
        return $enginesisResponse;
    }

    /**
     * Return the version of this Enginesis library.
     * @return string version identifier
     */
    public function version() {
        return ENGINESIS_VERSION;
    }

    /**
     * Enginesis boolean values are 1 for true or 0 for false. Coerce any
     * boolean-like value to either 1 or 0. Accepted values for true are
     * 1, Y, Yes, T, True, Checked, a number that evaluates to non-zero,
     * or any non-null value. Anything else is considered false.
     *
     * @param any $variable Any type will be coerced to a boolean value.
     * @return integer Either 1 or 0.
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
        return $result ? 1 : 0;
    }

    /**
     * Determine if the $id is valid.
     * @param $id
     * @return bool
     */
    public function isValidId ($id) {
        return $id != null && $id > 0;
    }

    /**
     * Determine if the string is valid.
     *
     * @param string $string String to test, if not a string you should coerce it first.
     * @param integer $minLength The minimum length allowed. 0 will allow both null and empty string.
     * @param integer $maxLength The maximum length allowed.
     * @param boolean $allowEmpty If true then allow empty/non-existing string.
     * @param boolean $allowTags If true then make sure string does not contain HTML tags.
     * @return boolean
     */
    public function isValidString ($string, $minLength, $maxLength, $allowEmpty = false, $allowTags = true) {
        if ( ! is_string($string)) {
            return false;
        } else {
            $length = strlen($string);
            if ($allowEmpty && $length == 0) {
                return true;
            } else {
                if ( ! $allowTags && $length != strlen(strip_tags($string))) {
                    return false;
                } else {
                    return $length >= $minLength && $length <= $maxLength;
                }
            }
        }
    }

    /**
     * Determine if a user name passes basic validity checks.
     *
     * @param string $userName
     * @return boolean
     */
    public function isValidUserName ($userName) {
        $badNames = ['null', 'undefined', 'xxx', 'shit', 'fuck', 'dick'];
        return strlen(trim($userName)) > 2 && ! in_array($userName, $badNames);
    }

    /**
     * Determine if a password passes basic validity checks.
     *
     * @param $password
     * @return boolean
     */
    public function isValidPassword ($password) {
        return strlen(trim($password)) > 3;
    }

    /**
     * Determine if we have a valid gender setting.
     *
     * @param string $gender
     * @return boolean
     */
    public function isValidGender ($gender) {
        $acceptableGenders = ['M', 'Male', 'F', 'Female', 'U', 'Undefined'];
        return in_array($gender, $acceptableGenders);
    }

    /**
     * Determine if the date is acceptable.
     *
     * @param $date
     * @return bool
     */
    public function isValidDate ($date) {
        $dateParts = explode('-', $date);
        return count($dateParts) == 3 && checkdate($dateParts[1], $dateParts[2], $dateParts[0]);
    }

    /**
     * Determine if an email address appears to be valid.
     *
     * @param string $email An email address to check.
     * @return boolean True if we think the email address looks valid, otherwise false.
     */
    function isValidEmailAddress ($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * In order to provide some flexibility with dates, our API will accept a PHP date, a Unix timestamp,
     * or a date string. This function will try to figure our what date was provided and convert what ever
     * it is into a valid MySQL date string.
     * @param date|integer|string $date One of PHP Date, integer, a string, or null.
     * @return string a valid MySQL date
     */
    public function mySQLDate($date = null) {
        $mysqlFormat = 'Y-m-d H:i:s';
        if ($date === null) {
            $dateStr = null;
        } elseif (is_object($date)) {
            $dateStr = $date->format($mysqlFormat);
        } elseif (is_integer($date)) {
            $dateStr = date($mysqlFormat, $date);
        } elseif (strtolower($date) == 'now') {
            $dateStr = date($mysqlFormat);
        } else {
            $dateStr = date($mysqlFormat, strtotime($date));
        }
        return $dateStr;
    }

    /**
     * Determine the site-id.
     * @return int
     */
    public function getSiteId () {
        return $this->m_siteId;
    }

    /**
     * Determine the authentication token.
     * @return string
     */
    public function getAuthToken () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_authToken;
    }

    /**
     * Determine the user-id.
     * @return int
     */
    public function getUserId () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_userId;
    }

    /**
     * Determine the user-name.
     * @return string
     */
    public function getUserName () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_userName;
    }

    /**
     * Determine the user access level.
     * @return int
     */
    public function getUserAccessLevel () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_userAccessLevel;
    }

    /**
     * Determine the network used to validate this user.
     * @return int
     */
    public function getNetworkId () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_networkId;
    }

    /**
     * Determine the user-id on the SSO network.
     * @return string
     */
    public function getSiteUserId () {
        if ( ! $this->m_authTokenWasValidated) {
            $this->restoreUserFromAuthToken();
        }
        return $this->m_siteUserId;
    }

    /**
     * Set the SSO user information.
     * @param integer Network identifier from `EnginesisNetworks`
     * @param string site user id from SSO
     */
    public function setSiteUserId ($networkId, $siteUserId) {
        $this->m_siteUserId = $siteUserId;
        $this->m_networkId = $networkId;
    }

    /**
     * Return the language code.
     * @return string
     */
    public function getLanguageCode () {
        return $this->m_languageCode;
    }

    /**
     * Return the token status so we can know if the constructor succeeded or failed to validate a authentication token.
     * @return int EnginesisRefreshStatus
     */
    public function getTokenStatus() {
        return $this->m_tokenStatus;
    }

    /**
     * Return the user info object from login or when a session was refreshed.
     * @return object|null user info object if a user is logged in.
     */
    public function getLoggedInUserInfo() {
        if ($this->m_refreshedUserInfo != null) {
            $this->debugInfo("already have m_refreshedUserInfo " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
            return $this->m_refreshedUserInfo;
        } else {
            return $this->sessionUserInfoGet();
        }
    }

    /**
     * Set the CMS key required for secure transactions.
     * @param string $key
     */
    public function setCMSKey($cmsKey, $userName = '', $password = '') {
        if (is_array($cmsKey)) {
            $this->m_cmsCredentials['cms_key'] = $cmsKey['cms_key'];
            $this->m_cmsCredentials['user_name'] = $cmsKey['user_name'];
            $this->m_cmsCredentials['password'] = $cmsKey['password'];
        } else {
            $this->m_cmsCredentials['cms_key'] = $cmsKey;
            $this->m_cmsCredentials['user_name'] = $userName;
            $this->m_cmsCredentials['password'] = $password;
        }
    }

    /**
     * Return the private authentication token to be used for server-to-server secured transactions.
     * Any secured service should use this function to get the CMS user authentication token. The
     * setter function `setCMSKey()` must be called before this in order to set the required credentials.
     *
     * @return string Authentication token. An empty string is returned if there was an error.
     */
    private function getCMSAuthToken() {
        $errorCode = EnginesisErrors::NO_ERROR;
        $errorMessage = '';
        $auth = null;
        $updated = false;
        $timeNow = time();
        $secretFile = $this->m_serverPaths['PRIVATE'] . '.enginesis_auth.json';

        // load existing file
        if (file_exists($secretFile)) {
            $contents = file_get_contents($secretFile);
            $auth = json_decode($contents);
            // Verify the loaded file was not tampered/spoofed.
            $cr = md5($auth->date_saved . $auth->user_id . $auth->user_name . $auth->access_level . $this->m_siteId);
            if ($cr != $auth->cr) {
                $errorCode = EnginesisErrors::INVALID_DATA;
                $errorMessage = 'Saved hash does not agree with saved content.';
            }
            // @todo: Verify this information matches the set CMS user.
        }
        if ($auth == null) {
            $auth = json_decode(<<<EOD
            {
                "user_id":0,
                "user_name":"",
                "access_level":0,
                "auth_token":"",
                "auth_token_expires":"1999-12-31 21:21:21",
                "refresh_token":"",
                "refresh_token_expires":"1999-12-31 21:21:21",
                "date_saved":"1999-12-31 21:21:21",
                "cr":""
            }
            EOD);
        }
        // determine if existing token is still valid
        $authTokenExpireTime = strtotime($auth->auth_token_expires);
        if ($authTokenExpireTime === false || $timeNow > $authTokenExpireTime) {
            // auth token is expired, determine if refresh token is valid
            $refreshTokenExpireTime = strtotime($auth->refresh_token_expires);
            if ($refreshTokenExpireTime === false || $timeNow > $refreshTokenExpireTime) {
                // need to do a full login, we expect the parameters to be passed in
                $CMSAccountCredentials = $this->m_cmsCredentials;
                if (empty($CMSAccountCredentials) || empty($CMSAccountCredentials['user_name']) || empty($CMSAccountCredentials['password'])) {
                    $errorCode = EnginesisErrors::INVALID_LOGIN;
                    $errorMessage = 'CMS authentication failed, check user credentials.';
                } else {
                    $userInfo = $this->adminUserLogin($CMSAccountCredentials['user_name'], $CMSAccountCredentials['password']);
                    if ($userInfo == null) {
                        $errorCode = $this->getLastErrorCode();
                        if ($errorCode == EnginesisErrors::NO_ERROR) {
                            $errorCode = EnginesisErrors::INVALID_LOGIN;
                        }
                        $errorMessage = 'CMS authentication failed, check user credentials. ' . $this->getLastErrorDescription();
                    } else {
                        $updated = true;
                        $auth->user_id = intval($userInfo->user_id, 10);
                        $auth->user_name = $userInfo->user_name;
                        $auth->access_level = intval($userInfo->access_level, 10);
                        $auth->auth_token = $userInfo->authtok;
                        $auth->auth_token_expires = $userInfo->session_expires;
                        $auth->refresh_token = $userInfo->refresh_token;
                        $auth->refresh_token_expires = $userInfo->expires;
                    }
                }
            } else {
                // need to do a session refresh
                $userInfo = $this->adminSessionRefresh($auth->user_id, $auth->refresh_token);
                if ($userInfo == null) {
                    $errorCode = EnginesisErrors::INVALID_TOKEN;
                    $errorMessage = 'Authentication token invalid or expired and refresh token invalid or expired.';
                } else {
                    $updated = true;
                }
            }
        }
        // if token is new/refreshed, save $auth
        if ($updated) {
            $auth->date_saved = date('Y-m-d H:i:s', $timeNow);
            $auth->cr = md5($auth->date_saved . $auth->user_id . $auth->user_name . $auth->access_level . $this->m_siteId);
            $contents = json_encode($auth);
            try {
                $errorLevel = error_reporting();
                error_reporting($errorLevel & ~E_WARNING);
                $writeStatus = file_put_contents($secretFile, $contents);
                if ($writeStatus === false) {
                    $errorCode = EnginesisErrors::FILE_WRITE_FAILED;
                    $errorMessage = 'Unable to store authentication on the server, check file privs.';
                }
                error_reporting($errorLevel);
            } catch (Exception $exception) {
                $errorCode = EnginesisErrors::FILE_WRITE_FAILED;
                $errorMessage = 'Unable to store authentication on the server, ' . $exception->getMessage();
            }
        }
        if ($errorCode != EnginesisErrors::NO_ERROR) {
            $this->setLastError($errorCode, $errorMessage);
            $this->debugInfo("getCMSAuthToken error $errorCode:$errorMessage result " . json_encode($auth), __FILE__, __LINE__);
        }
        return $auth->auth_token;
    }

    /**
     * Determine the full domain name of the server we are currently running on.
     * @return string server host name only, e.g. www.enginesis.com.
     */
    private function serverName() {
        if (isset($_SERVER['HTTP_HOST'])) {
            if (strpos($_SERVER['HTTP_HOST'], ':') !== false) {
                $host_name = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];
                $server = substr($host_name, 0, strpos($host_name, ':'));
            } else {
                $server = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];
            }
        } else {
            $server = gethostname();
        }
        return $server;
    }

    /**
     * Return the server name of the instance we are running on. This should return a
     * host domain, not a URL. For example, www.varyn.com.
     *
     * @return string Server name.
     */
    public function getServerName() {
        return empty($this->m_server) ? $this->serverName() : $this->m_server;
    }

    /**
     * Return the Enginesis service host domain to the Enginesis server this instance is communicating with.
     * This is expected to be the host domain of the Enginesis services endpoint. For example enginesis.varyn-l.com when the
     *
     * @return string Enginesis services host domain.
     */
    public function getServiceHost() {
        return $this->m_serviceHost;
    }

    /**
     * Return the domain name and TLD only (remove server name, protocol, anything else) e.g. this function
     * converts http://www.games.com into games.com or http://services.games-q.com into games-q.com
     * @param string $proposedServerName a proposed domain. If empty, use the current domain.
     * @return string The last two components of the proposed domain.
     */
    public function serverTail($proposedServerName = '') {
        if (empty($proposedServerName)) {
            $proposedServerName = $this->getServerName();
        }
        if ($proposedServerName != 'localhost') {
            $urlParts = explode('.', $proposedServerName);
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
            $proposedServerName = $domain . $tld;
        }
        return $proposedServerName;
    }

    /**
     * Transform the host name into the matching stage-qualified host name requested. For example, if we are currently on
     * www.enginesis-q.com and the $targetStage is -l, return www.enginesis-l.com.
     * @param string $targetStage one of -l, -d, -x, -q or '' for live.
     * @param string|null $hostName A host name to check, or if not provided then the current host. This is a domain, not a URL.
     * @return string The requalified host name.
     */
    public function domainForTargetStage($targetStage, $hostName = null) {
        if (empty($hostName)) {
            $hostName = $this->getServerName();
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
     * @return string The -l, -d, -q, -x part, or '' for live.
     */
    public function serverStage($hostName = '') {
        // assume live until we prove otherwise
        $targetPlatform = '';
        if (strlen($hostName) == 0) {
            $hostName = $this->getServerName();
        }
        if (preg_match('/-[dlqx]\./i', $hostName, $matchedStage)) {
            $targetPlatform = substr($matchedStage[0], 0, 2);
        }
        return $targetPlatform;
    }

    /**
     * Determine if the proposed stage is valid.
     * @param string $stage The proposed stage to check (one of -l, -d, -q, -x, or '' for live.)
     * @return boolean true if valid otherwise false.
     */
    public function isValidStage($stage) {
        return $stage == '' || preg_match('/^-[dlqx]$/i', $stage);
    }

    /**
     * Return the server stage this instance is running on (-l, -d, -q, -x, '' for live.)
     * This is determined at object construction.
     * @return string
     */
    public function getServerStage() {
        return $this->m_stage;
    }

    /**
     * Return the root URL of the service: http protocol, hot name, and root / of the website.
     *
     * @return string The root URL of the service, such as "https://enginesis.com/"
     */
    public function getServiceRoot() {
        return $this->m_serviceProtocol . '://' . $this->m_serviceHost . '/';
    }

    /**
     * Determine if we are runing on http or https.
     * @return string: HTTP protocol, either http or https.
     */
    public function getServiceProtocol () {
        // return http or https. you should use the result of this and never hard-code http:// into any URLs.
        if ( ! empty($this->m_serviceProtocol)) {
            $protocol = $this->m_serviceProtocol;
        } elseif (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            $protocol = 'https';
        } else {
            $protocol = 'https';
        }
        return $protocol;
    }

    /**
     * Determine and cache the Enginesis service we want to converse with. This should do the hard work
     * of figuring out the matching domain to the hosting website and the matching stage. For example, if
     * this is running on https://www.varyn.com and the stage is -q, then the expected Enginesis service is
     * running at https://enginesis.varyn-q.com/.
     *
     * @param string|null $enginesisServer The intended stage, a full domain, or empty.
     *     - if empty or "*", match the current domain: www.varyn-q.com becomes enginesis.varyn-q.com. This should be the most common usage.
     *     - a stage designation, match to current domain but on that stage: specifying -q while currently on www.vary.com becomes enginesis.varyn-q.com.
     *     - anything else forces the service endpoint to exactly that specification.
     */
    public function setServiceHost($enginesisServer = null) {
        $defaultEnginesisService = 'enginesis';
        $protocol = $this->getServiceProtocol();
        $enginesisServiceHost = '';
        if ($enginesisServer === null || $enginesisServer === '*') {
            // Caller doesn't know which stage, converse with the one that matches the stage we are on
            $currentHost = $this->getServerName();
            // drop the host service, if one exists, so we can replace it with 'enginesis'.
            if ($this->m_siteId !== 100) {
                $enginesisServiceHost .= $defaultEnginesisService . '.';
            }
            $enginesisServiceHost .= $this->serverTail($currentHost);
        } elseif ($this->isValidStage($enginesisServer)) {
            // Caller may provide a stage we should converse with, e.g. -l
            if ($this->m_siteId !== 100) {
                $enginesisServiceHost .= $defaultEnginesisService . '.';
            }
            $enginesisServiceHost .= $this->serverTail($this->domainForTargetStage($enginesisServer));
        } else {
            // Caller can provide a specific server we should converse with
            $URLParts = parse_url($enginesisServer);
            if (isset($URLParts['path']) && ! isset($URLParts['host'])) {
                // if we only got path then assume it is a host domain
                $URLParts['host'] = $URLParts['path'];
                unset($URLParts['path']);
            }
            if (isset($URLParts['scheme'])) {
                $protocol = $URLParts['scheme'];
            }
            $enginesisServiceHost = $URLParts['host'];
        }
        $this->m_serviceHost = $enginesisServiceHost;
        $this->m_serviceProtocol = $protocol;
        $this->debugInfo("Using host $protocol $enginesisServiceHost", __FILE__, __LINE__);
        return $this->m_serviceHost;
    }

    public function setDeveloperKey($developerKey) {
        $this->m_developerKey = $developerKey;
    }

    public function setRefreshToken($refreshToken) {
        $this->m_refreshToken = $refreshToken;
    }

    /**
     * When looking at a key => value array return a default value if the given key is not found.
     * @param $kvArray
     * @param $key
     * @param $default
     * @return mixed
     */
    private function arrayValueOrDefault ($kvArray, $key, $default) {
        if ( ! is_array($kvArray)) {
            return $default;
        } else {
            return isset($kvArray[$key]) ? $kvArray[$key] : $default;
        }
    }

    /**
     * Help function to create  "no-error" error.
     * @return object Returns a successful error code.
     */
    private static function noError () {
        return ['success' => '1', 'message' => '', 'extended_info' => ''];
    }

    /**
     * If the API call returned an error this function sets the lastError object. If there was no error then
     * lastError is null. Call $enginesis->getLastError() to work with the error information.
     *
     * @param object $enginesisResponse A response object from an Enginesis API call.
     * @return null|Array Returns null if an error occurred, an array of result "rows" if succeeded. If no results, then it usually is an empty array.
     */
    private function setLastErrorFromResponse ($enginesisResponse) {
        $results = null;
        if ($enginesisResponse) {
            $success = 0;
            $statusMessage = '';
            $extendedInfo = '';
            $results = $this->getResponseStatus($enginesisResponse, $success, $statusMessage, $extendedInfo);
            if ($results === null) {
                $this->m_lastError = ['success' => $success, 'message' => $statusMessage, 'extended_info' => $extendedInfo];
            } else {
                $this->m_lastError = Enginesis::noError();
            }
        }
        return $results;
    }

    /**
     * Force-set the last error message for internal (non-API) errors.
     * @param $errorCode string A typical Enginesis error code.
     * @param $errorMessage string An optional error message.
     * @return null|object Returns the lastError object, null if the last error was successful.
     */
    private function setLastError ($errorCode, $errorMessage) {
        $success = $errorCode == '';
        if ($success) {
            $this->m_lastError = Enginesis::noError();
        } else {
            $this->m_lastError = ['success' => $success, 'message' => $errorCode, 'extended_info' => $errorMessage];
        }
        return $this->m_lastError;
    }

    /**
     * Figure out which domain we want to save the cookie under.
     * @param string $serverName
     * @return null|string
     */
    private function sessionCookieDomain ($serverName = '') {
        $newDomain = null;
        $domain = $this->serverTail($serverName);
        if (strlen($domain) > 0) {
            $newDomain = '.' . $domain;
        }
        return $newDomain;
    }

    /**
     * Get the authentication token, either it was provided in the http get/post, or it is in the cookie. POST overrides cookie.
     * This function does not determine if the authentication token is actually valid (use sessionValidateAuthenticationToken for that.)
     * @return string token, null if no token could be found.
     */
    public function sessionGetAuthenticationToken () {
        $authenticationToken = getPostOrRequestVar('authtok', '');
        if (empty($authenticationToken)) {
            if (isset($_COOKIE[SESSION_COOKIE])) {
                $authenticationToken = $_COOKIE[SESSION_COOKIE];
            } else {
                $authenticationToken = null;
            }
        }
        return $authenticationToken;
    }

    /**
     * Get the refresh token, either it was provided in the http get/post, or it was set by the client.
     * This function does not determine if the authentication token is actually valid (use sessionValidateAuthenticationToken for that.)
     * @return string token, null if no token could be found.
     */
    public function sessionGetRefreshToken () {
        if (empty($this->m_refreshToken)) {
            $refreshToken = getPostOrRequestVar('refreshToken', '');
            if (empty($refreshToken)) {
                if (isset($_COOKIE[REFRESH_COOKIE])) {
                    $refreshTokenJSON = json_decode($_COOKIE[REFRESH_COOKIE]);
                    if ($refreshTokenJSON != null) {
                        $refreshToken = $refreshTokenJSON->refresh_token;
                        $timestamp = $refreshTokenJSON->timestamp;
                    } else {
                        $refreshToken = null;
                        // @todo: the saved token isn't valid, some type of logging and recovery here?
                    }
                } else {
                    $refreshToken = null;
                }
            }
        } else {
            $refreshToken = $this->m_refreshToken;
        }
        return $refreshToken;
    }

    /**
     * Save the user's refresh token with this session. Then we can use it if we detect an
     * expired authentication token.
     *
     * @param string $refreshToken
     * @return string
     */
    public function saveRefreshToken($refreshToken) {
        $this->m_refreshToken = $refreshToken;
        $timestamp = time();
        // PHP timestamps are in seconds, JavaScript timestamps are in milliseconds. :(
        $timestampJS = $timestamp * 1000;
        $refreshTokenJSON = '{"refresh_token":"'. $refreshToken . '","timestamp":"' . $timestampJS . '"}';
        setcookie(REFRESH_COOKIE, $refreshTokenJSON, $timestamp + (365 * 24 * 60 * 60), '/', $this->sessionCookieDomain(), true, false);
        return $this->m_refreshToken;
    }

    /**
     * Restore the user's session from the provided authentication token.
     * @todo: This code is actually WRONG! We cannot do this on the (PHP) client. Instead, we should
     * send a SessionBegin request to the Enginesis server and it will tell us this authtoken is
     * acceptable or not.
     * @param string|null $authToken
     * @return EnginesisRefreshStatus a status code indicating the token situation
     */
    private function restoreUserFromAuthToken ($authToken = null) {
        $status = EnginesisRefreshStatus::missing;
        $this->m_authTokenWasValidated = false;
        if (empty($authToken)) {
            $authToken = $this->sessionGetAuthenticationToken();
        }
        if ( ! empty($authToken)) {
            $sessionSiteId = 0;
            $sessionUserId = 0;
            $sessionUserName = '';
            $sessionSiteUserId = '';
            $sessionNetworkId = 1;
            $sessionAccessLevel = 0;
            $errorCode = $this->sessionValidate($authToken, $sessionSiteId, $sessionUserId, $sessionUserName, $sessionSiteUserId, $sessionAccessLevel, $sessionNetworkId);
            if ($errorCode == '' && $sessionUserId != 0) {
                $this->m_siteId = $sessionSiteId;
                $this->m_userId = $sessionUserId;
                $this->m_userName = $sessionUserName;
                $this->m_siteUserId = $sessionSiteUserId;
                $this->m_networkId = $sessionNetworkId;
                $this->m_userAccessLevel = $sessionAccessLevel;
                $this->m_authToken = $authToken;
                $this->m_authTokenWasValidated = true;
                $this->m_isLoggedIn = true;
                $this->m_refreshedUserInfo = $this->sessionUserInfoGet();
                $this->debugInfo("Restored m_refreshedUserInfo from sessionUserInfoGet " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
                $status = EnginesisRefreshStatus::valid;
            } elseif ($errorCode == EnginesisErrors::TOKEN_EXPIRED) {
                // if the auth token is expired we need to ask the server for a new one IF we have the refresh token
                $refreshToken = $this->sessionGetRefreshToken();
                if ( ! empty($refreshToken)) {
                    $userInfo = $this->sessionRefresh($refreshToken);
                    if ($userInfo === null) {
                        $errorCode = $this->m_lastError['message'];
                        $status = EnginesisRefreshStatus::expired;
                    } else {
                        $status = EnginesisRefreshStatus::refreshed;
                        $this->m_refreshedUserInfo = $userInfo;
                        $this->debugInfo("Restored expired m_refreshedUserInfo from sessionRefresh " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
                    }
                } else {
                    $status = EnginesisRefreshStatus::missing;
                }
            } else {
                $status = EnginesisRefreshStatus::missing;
            }
        } else {
            $refreshToken = $this->sessionGetRefreshToken();
            if ( ! empty($refreshToken)) {
                $userInfo = $this->sessionRefresh($refreshToken);
                if ($userInfo === null) {
                    $errorCode = $this->m_lastError['message'];
                    $status = EnginesisRefreshStatus::invalid;
                } else {
                    $status = EnginesisRefreshStatus::refreshed;
                    $this->m_refreshedUserInfo = $userInfo;
                    $this->debugInfo("Restored missing m_refreshedUserInfo from sessionRefresh " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
                }
            }
        }
        $this->m_tokenStatus = $status;
        if ( ! $this->m_authTokenWasValidated) {
            $this->sessionClear();
        }
        $this->debugInfo("restoreUserFromAuthToken m_refreshedUserInfo done " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
        return $status;
    }

    /**
     * Create an authentication token using the given parameters, or defaults from settings in the current object.
     * @param $siteId
     * @param $userId
     * @param $siteUserId
     * @param $userName
     * @param $accessLevel
     * @param $networkId
     * @return string encrypted user authentication token
     */
    private function authTokenMake ($siteId, $userId, $userName, $siteUserId, $accessLevel, $networkId) {
        if ($this->m_authTokenWasValidated) {
            return $this->m_authToken;
        }
        if ($userId === null || $userId < 1) {
            $userId = $this->m_userId;
        }
        if ($siteUserId === null || $siteUserId == '') {
            $siteUserId = $this->m_siteUserId;
        }
        if ($userName === null || $userName == '') {
            $userName = $this->m_userName;
        }
        $decryptedData = 'siteid=' . $siteId . '&userid=' . $userId . '&siteuserid=' . $siteUserId . '&networkid=' . $networkId . '&username=' . $userName . '&accesslevel=' . $accessLevel . '&daystamp=' . $this->sessionDayStamp();
        return $this->encryptString($decryptedData, $this->m_developerKey);
    }

    /**
     * Decrypt an authentication token and return an array of items contained in it. This function is designed to undo
     * what authTokenMake did but returning an array of the input parameters.
     * @param string $authenticationToken the encrypted token.
     * @return array|null Returns null if the token could not be decrypted, when successful returns an array matching the
     *     input parameters of authTokenMake.
     */
    private function authTokenDecrypt ($authenticationToken) {
        $dataArray = null;
        if (strlen($this->m_developerKey) > 0 && strlen($authenticationToken) > 0) {
            $tokenData = $this->decryptString($authenticationToken, $this->m_developerKey);
            if ($tokenData != null && $tokenData != '') {
                $dataArray = $this->decodeURLParams($tokenData);
            }
        }
        return $dataArray;
    }

    /**
     * Generate a time stamp for the current time rounded to the nearest SESSION_DAYSTAMP_HOURS hour.
     * @return int
     */
    private function sessionDayStamp() {
        return floor(time() / (SESSION_DAYSTAMP_HOURS * 60 * 60)); // good for SESSION_DAYSTAMP_HOURS hours
    }

    /**
     * Determine if a day stamp is currently valid. Day stamps expire after SESSION_DAYSTAMP_HOURS. A day stamp is
     * valid if it is equal or ahead of (greater than) the current day stamp.
     * @param $dayStamp
     * @return bool
     */
    private function sessionIsValidDayStamp($dayStamp) {
        $day_stamp_current = $this->sessionDayStamp();
        $isValid = $day_stamp_current <= ($dayStamp + 1);
        return $isValid;
    }

    /**
     * Create a user-based and time-sensitive session identifier. Typically used to identify a unique game session
     * for a specific user so another user can't spoof that user.
     * @return string
     */
    private function sessionMakeId() {
        return md5($this->m_developerKey . '' . $this->sessionDayStamp() . '' . $this->m_userId . '' . $this->m_gameId);
    }

    /**
     * This function validates a token used to communicate to the client who is logged in user_id, user_name and site_user_id
     * @param $authToken
     * @param $site_id
     * @param $user_id
     * @param $user_name
     * @param $site_user_id
     * @param $access_level
     * @param $network_id
     * @return string
     */
    private function sessionValidate ($authToken, & $site_id, & $user_id, & $user_name, & $site_user_id, & $access_level, & $network_id) {
        $rc = '';
        if (empty($authToken)) {
            $authToken = $this->sessionGetAuthenticationToken();
        }
        if (strlen($authToken) > 0) {
            $dataArray = $this->authTokenDecrypt($authToken);
            if (isset($dataArray['daystamp'])) {
                if ($this->sessionIsValidDayStamp($dataArray['daystamp'])) {
                    $site_id = $dataArray['siteid'];
                    $user_id = $dataArray['userid'];
                    $user_name = $dataArray['username'];
                    $site_user_id = $dataArray['siteuserid'];
                    $access_level = $dataArray['accesslevel'];
                    $network_id = $dataArray['networkid'];
                } else {
                    $rc = 'TOKEN_EXPIRED';
                }
            } else {
                $rc = 'INVALID_TOKEN';
            }
        } else {
            $rc = 'INVALID_TOKEN';
        }
        return $rc;
    }

    /**
     * Save the authenticated session to cookie so we can retrieve it next time this user returns.
     * @param $authenticationToken string the encrypted authentication token generated by sessionMakeAuthenticationTokenEncrypted.
     * @param integer $user_id associated with $authenticationToken.
     * @param string $user_name associated with $authenticationToken.
     * @param string $site_user_id associated with $authenticationToken.
     * @param integer $access_level associated with $authenticationToken.
     * @param integer $network_id associated with $authenticationToken.
     * @return string An error code, '' if successful.
     */
    private function sessionSave ($authenticationToken, $user_id, $user_name, $site_user_id, $access_level, $network_id) {
        $rc = '';
        $errorLevel = error_reporting(); // turn off warnings so we don't generate crap in the output stream. If we don't do this fucking php writes an error and screws up the output stream. (I cant get the try/catch to work without it)
        error_reporting($errorLevel & ~E_WARNING);
        try {
            if ( ! setcookie(SESSION_COOKIE, $authenticationToken, time() + (SESSION_DAYSTAMP_HOURS * 60 * 60), '/', $this->sessionCookieDomain(), true, false)) {
                $rc = 'CANNOT_SET_SESSION';
                $this->setLastError($rc, 'sessionSave setcookie failed');
                $this->debugInfo("Failed to save the engsession cookie", __FILE__, __LINE__);
            }
        } catch (Exception $e) {
            $rc = 'CANNOT_SET_SESSION';
            $this->setLastError($rc, 'sessionSave could not set cookie: ' . $e->getMessage());
            $this->debugInfo("Exception when saving the engsession cookie", __FILE__, __LINE__);
        }
        error_reporting($errorLevel); // put error level back to where it was
        if ($rc == '') {
            $this->m_authTokenWasValidated = true;
            $this->m_authToken = $authenticationToken;
            $this->m_userName = $user_name;
            $this->m_userId = $user_id;
            $this->m_siteUserId = $site_user_id;
            $this->m_networkId = $network_id;
            $this->m_userAccessLevel = $access_level;
            $this->m_isLoggedIn = true;
            $_COOKIE[SESSION_COOKIE] = $authenticationToken;
            $GLOBALS[SESSION_USERID_CACHE] = $user_id;
        }
        return $rc;
    }

    /**
     * Save the authenticated user info to cookie so we can retrieve it next time this user returns. We do this
     * to allow clients to access the logged in user info easily after a login. Always verify the token is
     * valid before relying on this data.
     * @param $userInfo
     * @return string An error code, '' if successful.
     */
    private function sessionUserInfoSave ($userInfo) {
        $rc = '';
        // turn off warnings so we don't generate crap in the output stream. If we don't do this fucking php writes an error and screws up the output stream. (I cant get the try/catch to work without it)
        $errorLevel = error_reporting();
        error_reporting($errorLevel & ~E_WARNING);
        try {
            $userInfoJSON = json_encode($userInfo);
            if (setcookie(SESSION_USERINFO, $userInfoJSON, time() + (SESSION_DAYSTAMP_HOURS * 60 * 60), '/', $this->sessionCookieDomain(), true, false) === false) {
                $rc = 'CANNOT_SAVE_USERINFO';
                $this->setLastError($rc, 'sessionUserInfoSave setcookie failed');
            }
            if (isset($userInfo->refresh_token)) {
                $this->saveRefreshToken($userInfo->refresh_token);
            }
        } catch (Exception $e) {
            $rc = 'CANNOT_SAVE_USERINFO';
            $this->setLastError($rc, 'sessionUserInfoSave could not set cookie: ' . $e->getMessage());
        }
        // put error level back to where it was
        error_reporting($errorLevel);
        return $rc;
    }

    /**
     * Restore the user info data from cookie.
     *
     * @return null|object
     */
    public function sessionUserInfoGet() {
        $userInfo = null;
        if (isset($_COOKIE[SESSION_USERINFO])) {
            try {
                $userInfo = json_decode($_COOKIE[SESSION_USERINFO]);
                // @todo: Validate this user data make sure it was not tampered, check if expired.
                $this->debugInfo("decoded cookie for SESSION_USERINFO " . json_encode($userInfo), __FILE__, __LINE__);
            } catch (Exception $e) {
                $this->setLastError('CANNOT_GET_USERINFO', 'sessionUserInfoGet could not get cookie: ' . $e->getMessage());
                $this->debugInfo('sessionUserInfoGet fails: ' . json_encode($this->m_lastError), __FILE__, __LINE__);
            }
        } else {
            $this->debugInfo("no cookie for SESSION_USERINFO, not sure if you were expecting one", __FILE__, __LINE__);
        }
        return $userInfo;
    }

    /**
     * In cases when the server replies with a new or updated user session then restore our
     * internal variables so we can continue conversing with the server.
     * @param $serverResponse
     * @return object of user info.
     */
    private function sessionRestoreFromResponse($serverResponse) {
        $userInfo = null;
        if (is_array($serverResponse) && count($serverResponse) > 0) {
            $userInfo = $serverResponse[0];
            if ($userInfo && isset($userInfo->authtok)) {
                if (! isset($userInfo->user_id)) {
                    $userData = $this->authTokenDecrypt($userInfo->authtok);
                    if ($userData !== null) {
                        $userInfo->site_id = $userData['siteid'];
                        $userInfo->user_id = $userData['userid'];
                        $userInfo->user_name = $userData['username'];
                        $userInfo->site_user_id = $userData['siteuserid'];
                        $userInfo->access_level = $userData['accesslevel'];
                        $userInfo->network_id = $userData['networkid'];
                    }
                }
                $this->sessionSave($userInfo->authtok, $userInfo->user_id, $userInfo->user_name, $userInfo->site_user_id, $userInfo->access_level, $userInfo->network_id);
                $this->sessionUserInfoSave($userInfo);
                $this->m_refreshedUserInfo = $userInfo;
                $this->debugInfo("Restored m_refreshedUserInfo from sessionRestoreFromResponse " . json_encode($this->m_refreshedUserInfo), __FILE__, __LINE__);
            }
        }
        if ($userInfo === null) {
            $this->debugInfo("BAD UserInfo from sessionRestoreFromResponse " . json_encode($serverResponse), __FILE__, __LINE__);
        }
        return $userInfo;
    }

    /**
     * Server response callback from a adminUserLogin or adminSessionRefresh, validate the
     * server response and pass back the updated session information.
     */
    private function adminSessionResponse($serverResponse) {
        $userInfo = null;
        if (is_array($serverResponse) && count($serverResponse) > 0) {
            $userInfo = $serverResponse[0];
            if ($userInfo && isset($userInfo->authtok)) {
                if (! isset($userInfo->user_id)) {
                    $userData = $this->authTokenDecrypt($userInfo->authtok);
                    if ($userData !== null) {
                        $userInfo->site_id = $userData['siteid'];
                        $userInfo->user_id = $userData['userid'];
                        $userInfo->user_name = $userData['username'];
                        $userInfo->site_user_id = $userData['siteuserid'];
                        $userInfo->access_level = $userData['accesslevel'];
                        $userInfo->network_id = $userData['networkid'];
                    }
                }
            }
        }
        if ($userInfo === null) {
            $this->debugInfo("BAD UserInfo from adminSessionResponse " . json_encode($serverResponse), __FILE__, __LINE__);
        }
        return $userInfo;
    }

    /**
     * Clear any session data and forget any logged in user.
     * @return string An error code if the function fails to clear the cookies, or an empty string if successful.
     */
    private function sessionClear () {
        $rc = '';
        $sessionExpireTime = SESSION_EXPIRE_SECONDS;
        $this->m_authToken = null;
        $this->m_authTokenWasValidated = false;
        $this->m_userName = '';
        $this->m_userId = 0;
        $this->m_siteUserId = '';
        $this->m_networkId = 1;
        $this->m_userAccessLevel = 0;
        $this->m_isLoggedIn = false;
        $this->m_refreshedUserInfo = null;
        if ( ! headers_sent()) {
            if (setcookie(SESSION_COOKIE, '', time() - $sessionExpireTime, '/', $this->sessionCookieDomain(), true, false) === false) {
                $rc = 'CANNOT_SET_SESSION';
            }
            setcookie(SESSION_USERINFO, '', time() - $sessionExpireTime, '/', $this->sessionCookieDomain(), true, false);
        }
        $_COOKIE[SESSION_COOKIE] = null;
        $_COOKIE[SESSION_USERINFO] = null;
        $GLOBALS[SESSION_USERID_CACHE] = null;
        $_POST['authtok'] = null;
        return $rc;
    }

    /**
     * When using the Blowfish encryption algorithm, data must be padded to 8 byte blocks.
     * @param string A byte array to pad.
     * @return string The input with padding added to the end if necessary.
     */
    private function blowfishPad ($text) {
        $imod = 8 - (strlen($text) % 8);
        for ($i = 0; $i < $imod; $i ++) {
            $text .= chr($imod);
        }
        return $text;
    }

    /**
     * When using the Blowfish encryption algorithm, data must be padded to 8 byte blocks.
     * This function undoes any padding.
     * @param string A byte array that may have been padded.
     * @return string The input with padding removed if necessary.
     */
    private function blowfishUnpad ($text) {
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
     * Encrypt a string of data with a key.
     * @param $data {string} A clear string of data to encrypt.
     * @param $key {string} The encryption key.
     * @return {string} a base-64 representation of the encrypted data.
     */
    private function encryptString($data, $key) {
        $keyLength = strlen($key);
        if ($keyLength < 16) {
            $key = str_repeat($key, ceil(16/$keyLength));
        }
        return base64URLEncode(openssl_encrypt(blowfishPad($data), 'BF-ECB', pack('H*', $key), OPENSSL_RAW_DATA | OPENSSL_NO_PADDING));
    }

    /**
     * Decrypt a string of data that was encrypted with `encryptString()` using the same key.
     * @param $data {string} An encrypted string of data to decrypt.
     * @param $key {string} The encryption key.
     * @return {string} the clear string that was originally encrypted.
     */
    private function decryptString($data, $key) {
        $keyLength = strlen($key);
        if ($keyLength < 16) {
            $key = str_repeat($key, ceil(16/$keyLength));
        }
        return blowfishUnpad(openssl_decrypt(base64URLDecode($data), 'BF-ECB', pack('H*', $key), OPENSSL_RAW_DATA | OPENSSL_NO_PADDING));
    }

    /**
     * Decode a base-64 string. This function also replaces base64 chars that are not URL safe.
     * @param string A byte array that was base-64 encoded with `base64URLEncode($input)`.
     * @return string The input string decoded.
     */
    private function base64URLDecode($data) {
        return base64_decode(strtr($data, ['-' => '+', '_' => '/', '~' => '='])); // '-_~', '+/='));
    }

    /**
     * Encode a string|byte array into base-64. This function also replaces base64 chars that are not URL safe.
     * @param string A string or a byte array.
     * @return string The input string encoded and the unsafe characters are changed to `+` => `-` and `/` => `_`.
     */
    private function base64URLEncode($data) {
        return strtr(base64_encode($data), ['+' => '-', '/' => '_', '=' => '~']); // '+/=', '-_~');
    }

    /**
     * Set a debug callback function to call when its time to log a debug statement. This allows the application
     * to consolidate and handle logging. There is no default for this, so if this function is not set then
     * this Enginesis SDK will not perform any logging even if it is turned on.
     * The function signature is `function debugCallback($message)`.
     *
     * @param function $debugFunction A function reference.
     * @return function|null The prior function that was set is returned.
     */
    public function setDebugFunction ($debugFunction) {
        $priorFunction = $this->m_debugFunction;
        $this->m_debugFunction = $debugFunction;
        return $priorFunction;
    }

    /**
     * Turn debugging on or off. Debugging is logged to the function set with `setDebugFunction`,
     * otherwise this has no effect.
     *
     * @param boolean $flag A value to evaluate to either `true` to turn on or `false` to turn off.
     * @return boolean The current value of the debugging flag.
     */
    public function setDebugFlag($flag) {
        $this->m_debug = ! ! $flag;
        return $this->m_debug;
    }

    /**
     * Attempt to call the callback debug function if one was provided.
     * @param $message {string} A message to show in the log.
     * @param $file {string} The PHP file that requested the debug.
     * @param $line {integer} The line number in $file that requested the debug.
     */
    public function debugCallback($message, $file, $line) {
        if ($this->m_debugFunction != null) {
            call_user_func($this->m_debugFunction, $message, $file, $line);
        }
    }

    /**
     * Call the debug function only when debugging is turned on.
     * @param $message {string} A message to show in the log while debugging is on.
     */
    public function debugInfo($message, $file, $line) {
        if ($this->m_debug) {
            $this->debugCallback($message, $file, $line);
        }
    }

    /**
     * Function to help with debugging the object state.
     */
    public function debugDump () {
        echo("<h3>Enginesis object state</h3>");
        echo("<p>Version: " . $this->version() . "</p>");
        echo("<p>Server: $this->m_server</p>");
        echo("<p>End point: $this->m_serviceEndPoint</p>");
        echo("<p>Stage: $this->m_stage</p>");
        echo("<p>Site-id: $this->m_siteId</p>");
        echo("<p>Sync-id: $this->m_syncId</p>");
        echo("<p>Protocol: $this->m_serviceProtocol</p>");
        echo("<p>Format: $this->m_responseFormat</p>");
        echo("<p>User-id: $this->m_userId</p>");
        echo("<p>Site-user-id: $this->m_siteUserId</p>");
        echo("<p>Network-id: $this->m_networkId</p>");
        echo("<p>User logged in: " . ($this->m_isLoggedIn ? 'YES' : 'NO') . "</p>");
        echo("<p>Last error: " . ($this->m_lastError ? json_encode($this->m_lastError) : 'null') . "</p>");
        var_dump($this->m_refreshedUserInfo);
    }

    /**
     * Return a string of parameter key/value pairs, but remove any sensitive information from the output.
     * @param Array An array or object of key/value pairs to log.
     * @return string A string representation of the parameters.
     */
    public function logSafeParameters($parameters) {
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

    /**
     * Encode a key/value array into URL parameters. `key=value&key=value&...`.
     * @param array $data A key/value array.
     * @return string a URL parameter query string.
     */
    public function encodeURLParams ($data) {
        $encodedURLParams = '';
        foreach ($data as $key => $value) {
            if ($encodedURLParams != '') {
                $encodedURLParams .= '&';
            }
            $encodedURLParams .= urlencode($key) . '=' . urlencode($value);
        }
        return $encodedURLParams;
    }

    /**
     * Decode a URL parameter query string into a key/value array.
     * @param string A URL parameter query string.
     * @return array A key/value array.
     */
    public function decodeURLParams ($encodedURLParams) {
        $data = [];
        $arrayOfParameters = explode('&', $encodedURLParams);
        $i = 0;
        $numParameters = count($arrayOfParameters);
        while ($i < $numParameters)  {
            $parameter = explode('=', $arrayOfParameters[$i]);
            if (count($parameter) > 1) {
                $data[urldecode($parameter[0])] = urldecode($parameter[1]);
            }
            $i ++;
        }
        return $data;
    }

    /**
     * Check we have the Enginesis authtoken in engsession cookie and it is valid.
     * @return bool true if the cookie is valid and the user is logged in.
     */
    public function isLoggedInUser () {
        if ($this->m_authTokenWasValidated && $this->m_userId > 0) {
            return true;
        } else {
            $this->restoreUserFromAuthToken(null);
            return $this->m_authTokenWasValidated && $this->m_userId > 0;
        }
    }

    /**
     * Make sure we call the API with all the necessary parameters. We can assume some defaults from the
     * current session but they only are used when the caller does not provide a required parameter.
     * @param $serviceName string: The API function to call
     * @param $parameters array: The API parameters as a key-value array
     * @return array The cleansed parameter array ready to call the requested API.
     */
    public function serverParamObjectMake ($serviceName, $parameters) {
        $serverParams = [];
        if (is_array($parameters) && count($parameters) > 0) {
            $serverParams['fn'] = $serviceName;
            if ( ! isset($parameters['site_id'])) {
                $serverParams['site_id'] = $this->m_siteId;
            }
            if ( ! isset($parameters['user_id'])) {
                $serverParams['user_id'] = $this->m_userId;
            }
            if ( ! isset($parameters['response'])) {
                $serverParams['response'] = $this->m_responseFormat;
            }
            foreach ($parameters as $key => $value) {
                $serverParams[$key] = $value; // urlencode($value); // @todo: I'm not sure we should urlencode the data as it is going into the database encoded.
            }
            if ( ! isset($parameters['language_code'])) {
                $serverParams['language_code'] = $this->m_languageCode;
            }
        } else {
            $serverParams['fn'] = $serviceName;
            $serverParams['site_id'] = $this->m_siteId;
            $serverParams['user_id'] = $this->m_userId;
            $serverParams['response'] = $this->m_responseFormat;
        }
        $serverParams['state_seq'] = ++ $this->m_syncId;
        return $serverParams;
    }

    /**
     * Return the name of the page we are currently on.
     * @return string
     */
    private function currentPageName() {
        if (empty($_SERVER['PHP_SELF'])) {
            return '';
        } else {
            return basename($_SERVER['PHP_SELF'], '.php');
        }
    }

    /**
     * Return the full path of the page we are currently on.
     * @return string
     */
    private function currentPagePath() {
        return $_SERVER['PHP_SELF'];
    }

    /**
     * Make an Enginesis API request over the WWW.
     * @param string $serviceName is the API service to call.
     * @param Array|null $parameters Key => value array of parameters e.g. array('site_id' => 100);
     * @param boolean $isSecure Set to true if this is a secured request. Secured requests require additional set up.
     * @return object Response from server based on requested response format.
     */
    private function callServerAPI ($serviceName, $parameters, $isSecure = false) {
        $headers = [];
        $parameters = $this->serverParamObjectMake($serviceName, $parameters);
        $response = $parameters['response'];
        $setSSLCertificate = false;
        $developerKey = $this->m_developerKey;
        if ($developerKey != null) {
            // Add developer key in header so it is not sent in body
            $headers[] = 'X-DeveloperKey: ' . $developerKey;
            // weed it out of passed in parameters if it is there so it doesnt appear in logs
            unset($parameters['developer_key']);
            unset($parameters['apikey']);
        }
        if ($isSecure) {
            if ($serviceName === 'CmsUserLogin') {
                $authenticationToken = $this->m_cmsCredentials['cms_key'];
            } else {
                $authenticationToken = $this->getCMSAuthToken();
            }
            if ( ! empty($this->m_cmsCredentials) && ! empty($authenticationToken)) {
                $headers[] = 'X-CMSKey: ' . $this->m_cmsCredentials['cms_key'];
                $headers[] = 'Authentication: Bearer ' . $authenticationToken;
            } else {
                // invalid request to a secured service
                $errorCode = $this->getLastErrorCode();
                $errorInfo = $this->getLastErrorDescription();
                $this->debugCallback('callServerAPI error ' . $errorInfo, __FILE__, __LINE__);
                $contents = $this->makeErrorResponse($errorCode, $errorInfo, $parameters);
                return json_decode($contents);
            }
        } else {
            $authenticationToken = $this->m_authToken;
            if ($authenticationToken != null && $this->m_authTokenWasValidated) {
                // Move authentication token to header so it is not sent in body
                $headers[] = 'Authentication: Bearer ' . $authenticationToken;
                // weed it out of passed in parameters if it is there so it doesn't appear in logs
                unset($parameters['authtok']);
                unset($parameters['token']);
            }
        }
        $isLocalhost = serverStage() == '-l';
        $url = $this->m_serviceEndPoint;
        $setSSLCertificate = startsWith(strtolower($url), 'https://');
        $this->debugInfo("Calling $serviceName on $url with " . $this->logSafeParameters($parameters), __FILE__, __LINE__);
        $ch = curl_init($url);
        if ($ch) {
            $referrer = $this->m_serviceProtocol . '://' . $this->getServerName() . $this->currentPagePath();
            curl_setopt($ch, CURLOPT_USERAGENT, 'Enginesis PHP SDK ' . ENGINESIS_VERSION);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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
                $sslCertificate = $this->m_serverPaths['PRIVATE'] . 'cacert.pem';
                if (file_exists($sslCertificate)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $sslCertificate);
                } else {
                    $this->debugCallback("callServerAPI Cant locate private cert $sslCertificate", __FILE__, __LINE__);
                }
            }
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeURLParams($parameters));
            $contents = curl_exec($ch);
            $curlError = curl_errno($ch);
            $succeeded = $curlError == 0 && ($contents !== false && strlen($contents) > 0);
            if ( ! $succeeded) {
                $errorInfo = 'System error: ' . $this->m_serviceEndPoint . ' replied with no data. cURL error ' .$curlError . ': ' . curl_error($ch);
                $this->debugCallback($errorInfo, __FILE__, __LINE__);
                $contents = $this->makeErrorResponse(EnginesisErrors::SYSTEM_ERROR, $errorInfo, $parameters);
            }
            curl_close($ch);
        } else {
            $errorInfo = 'System error: unable to contact ' . $this->m_serviceEndPoint . ' or the server did not respond.';
            $contents = $this->makeErrorResponse(EnginesisErrors::SYSTEM_ERROR, $errorInfo, $parameters);
        }
        if ($response == 'json') {
            $contentsObject = json_decode($contents);
            if ($contentsObject === null) {
                $this->debugCallback("callServerAPI could not parse server response as JSON: $contents", __FILE__, __LINE__);
            }
            return $contentsObject;
        } else {
            return $contents;
        }
    }

    /**
     * Return the last error that occurred. Usually helpful after a service call.
     * @return object: the last error, null if the most recent operation succeeded.
     */
    public function getLastError () {
        return $this->m_lastError;
    }

    /**
     * Determine if the error code is an error or a non-error state. We need this because it holds a
     * variety of different states, either null or '' to indicate no error.
     * @param object $lastErrorCode Either an error object or null to use the last recorded error.
     * @return boolean: true if the error provided is an error, false if it is not.
     */
    public function isError ($lastErrorCode = null) {
        if (! isset($lastErrorCode) || $lastErrorCode === null) {
            $lastErrorCode = $this->m_lastError;
        }
        return $lastErrorCode != null && $this->m_lastError['message'] != EnginesisErrors::NO_ERROR;
    }

    /**
     * Return the last error code that occurred. Usually helpful after a service call.
     * @return object: the last error, null if the most recent operation succeeded.
     */
    public function getLastErrorCode () {
        return ($this->m_lastError != null) ? $this->m_lastError['message'] : EnginesisErrors::NO_ERROR;
    }

    /**
     * Return the last error information as a string.
     * @param boolean $returnFullDescription Include the error code in the description.
     * @return string
     */
    public function getLastErrorDescription ($returnFullDescription = false) {
        $errorDescription = '';
        if ($this->m_lastError != null) {
            if ($returnFullDescription) {
                $errorDescription = $this->m_lastError['message'] . ': ';
            }
            $errorDescription .= $this->m_lastError['extended_info'];
        }
        return $errorDescription;
    }

    /**
     * Given an Enginesis response object, pull out the status message information.
     * This is a helper function because doing this for every API call is a bit tedious.
     *
     * @param object $enginesisResponse The enginesis response object returned from callServerAPI.
     * @param boolean $success True/1 if API succeeded, false/0 when failed.
     * @param string $statusMessage Error code, empty if success is true/1.
     * @param string $extendedInfo Optional additional information about the error.
     * @return Array Array of rows if succeeded, null when failed.
     */
    public function getResponseStatus ($enginesisResponse, & $success, & $statusMessage, & $extendedInfo) {
        $success = false;
        $statusMessage = EnginesisErrors::INVALID_PARAMETER;
        $extendedInfo = '';
        $resultSet = null;
        if ($enginesisResponse != null) {
            if (isset($enginesisResponse->results)) {
                $results = $enginesisResponse->results;
                if (isset($results->status)) {
                    $status = $results->status;
                    $success = $status->success;
                    $statusMessage = $status->message;
                    if (isset($status->extended_info)) {
                        $extendedInfo = $status->extended_info;
                    }
                    // if the service failed but returned a result we still want it
                    if (isset($results->result)) {
                        // usually result is an array of rows.
                        $resultSet = $results->result;
                        if (isset($resultSet->row)) {
                            $resultSet = $resultSet->row;
                        }
                    } elseif (isset($results->row)) {
                        // "row" was a legacy from old server implementation but some APIs still return it, particularly in edge cases and errors.
                        $resultSet = $results->row;
                    }
                } else {
                    $statusMessage = EnginesisErrors::SERVER_RESPONSE_NOT_VALID;
                    $extendedInfo = 'Received a response but could not parse it.';
                }
            } else {
                $statusMessage = EnginesisErrors::SERVICE_ERROR;
                $extendedInfo = 'The server responded but there were no results to this request.';
            }
        } else {
            $statusMessage = EnginesisErrors::SERVER_DID_NOT_REPLY;
            $extendedInfo = 'The server did not respond to the request.';
        }
        return $resultSet;
    }

    /**
     * Return the URL of the request game image. This takes the parameters supplied and requests
     * from the back end the best fit image.
     * @todo: this really needs to call a server-side service to perform this resolution as we need to use PHP to determine which files are available and the closest match.
     *
     * @param string gameName game folder on server where the game assets are stored. Most of the game queries
     *    (GameGet, GameList, etc) return game_name and this is used as the game folder.
     * @param integer width optional width, use null to ignore. Server will choose common width.
     * @param integer height optional height, use null to ignore. Server will choose common height.
     * @param string format optional image format, use null and server will choose. Otherwise {jpg|png|svg}
     * @return string A URL you can use to load the image.
     */
    public function getGameImageURL ($gameName, $width, $height, $format) {
        if (empty($width) || $width == '*') {
            $width = 600;
        }
        if (empty($height) || $height == '*') {
            $height = 450;
        }
        if (substr($format, 0, 1) != '.') {
            $format = '.' . $format;
        }
        $found = preg_match('/\.(jpg|png|svg)/i', $format);
        if ($found === false || $found === 0) {
            $format = '.jpg';
        }
        $path = $this->m_serviceProtocol . '://' . $this->m_serviceHost . '/games/' . $gameName . '/images/' . $width . 'x' . $height . $format;
        return $path;
    }

    /**
     * The general public site get - returns a minimum set of public attributes about a site.
     *
     * @param integer $siteId an integer indicating a site_id.
     * @return object A site info object containing only the public attributes.
     */
    public function siteGet ($siteId) {
        $serviceName = 'SiteGet';
        $site = null;
        if ( ! is_numeric($siteId) || $siteId < 100) {
            $siteId = $this->m_siteId;
        }
        $parameters = ['site_id' => $siteId];
        $enginesisResponse = $this->callServerAPI($serviceName, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $site = $results[0];
        }
        return $site;
    }

    /**
     * If you know what you are doing you can call a service directly providing the service endpoint
     * and the required parameter. No client-side checking is done.
     *
     * @param string Service endpoint to call.
     * @param Array Key/value parameters required by the service.
     * @return Array Array of rows if succeeded, null when failed. If failed, call `getLastError()`.
     */
    public function callService ($service, $parameters) {
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * If you know what you are doing you can call a secured service directly. Secured service calls
     * require a CMS user to be logged in and set the CMS transaction key (setCMSKey()).
     *
     * @param string Service endpoint to call.
     * @param Array Key/value parameters required by the service.
     * @return Array Array of rows if succeeded, null when failed. If failed, call `getLastError()`.
     */
    public function callSecureService ($service, $parameters) {
        $isSecure = true;
        $enginesisResponse = $this->callServerAPI($service, $parameters, $isSecure);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Call Enginesis SessionBegin which is used to start any conversation with the server. Must call before beginning a game.
     * @param integer gameId The requested game id to start a session for.
     * @param string gameKey The game key matching the game id.
     * @return Object null if failed, a user info object if successful.
     */
    public function sessionBegin ($gameId, $gameKey) {
        $service = 'SessionBegin';
        $userInfo = null;
        if ( ! isValidId($gameId)) {
            $gameId = $this->m_gameId;
            if (isValidId($gameId)) {
                $gameKey = gameKeyMake($this->m_siteId, $gameId);
            }
        }
        if (isEmpty($gameKey) && isValidId($gameId)) {
            $gameKey = gameKeyMake($this->m_siteId, $gameId);
        } else {
            $gameKey = '';
        }
        $parameters = [
            'game_id' => $gameId,
            'game_key' => $gameKey
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->sessionRestoreFromResponse($results);
        } else {
            $errorCode = $this->getLastErrorCode();
            if ($errorCode == EnginesisErrors::INVALID_PARAMETER || $errorCode == EnginesisErrors::INVALID_TOKEN) {
                // if the refresh token is invalid then log this user out or else
                // we will keep trying this bad token on every request.
                $this->userLogout();
            }
        }
        return $userInfo;
    }

    /**
     * Call Enginesis SessionRefresh to exchange the long-lived refresh token for a new authentication token. Usually you
     * call this when you attempt to call a service and it replied with TOKEN_EXPIRED.
     * @param refreshToken {string} optional, if not provided (empty/null) then we try to pull the one we have in the local store.
     * @returns {object} The user object if successful, null if failed.
     */
    public function sessionRefresh ($refreshToken) {
        $service = 'SessionRefresh';
        $userInfo = null;
        $this->m_lastError = Enginesis::noError();
        if (empty($refreshToken)) {
            $refreshToken = $this->sessionGetRefreshToken();
            if (empty($refreshToken)) {
                $errorCode = EnginesisErrors::INVALID_TOKEN;
                $this->setLastError($errorCode, errorToLocalString($errorCode));
                return $userInfo;
            }
        }
        // When refreshing the token we need to remind the server who the user is
        $parameters = [
            'refresh_token' => $refreshToken,
            'logged_in_user_id' => $this->m_userId,
            'game_id' => $this->m_gameId,
            'gamekey' => gameKeyMake($this->m_siteId, $this->m_gameId)
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->sessionRestoreFromResponse($results);
        } else {
            $errorCode = $this->getLastErrorCode();
            if ($errorCode == EnginesisErrors::INVALID_PARAMETER || $errorCode == EnginesisErrors::INVALID_TOKEN) {
                // if the refresh token is invalid then log this user out or else
                // we will keep trying this bad token on every request.
                $this->userLogout();
            }
        }
        return $userInfo;
    }

    /**
     * Call Enginesis SessionRefresh to exchange the long-lived refresh token for a new authentication token. Usually you
     * call this when you attempt to call a service and it replied with TOKEN_EXPIRED.
     * @param integer $user_id The CMS user id.
     * @param string refreshToken The CMS user's refresh token.
     * @returns Object The CMS user object if successful, null if failed.
     */
    private function adminSessionRefresh ($user_id, $refreshToken) {
        $service = 'SessionRefresh';
        $isSecure = true;
        $userInfo = null;
        $this->m_lastError = Enginesis::noError();
        // When refreshing the token we need to remind the server who the user is
        $parameters = [
            'refresh_token' => $refreshToken,
            'logged_in_user_id' => $user_id,
            'game_id' => 0,
            'gamekey' => ''
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters, $isSecure);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->adminSessionResponse($results);
        } else {
            $errorCode = $this->getLastErrorCode();
        }
        return $userInfo;
    }

    /**
     * Log a user in by calling the Enginesis function and wait for the response. If the user is successfully
     * logged in then save the session cookie that allows us to converse with the server without logging in each time.
     * @param $userName: string the user's name or email address
     * @param $password: string the user's password
     * @return object: null if login fails, otherwise returns the user info object.
     */
    public function userLogin ($userName, $password) {
        $userInfo = null;
        $service = 'UserLogin';
        $parameters = [
            'user_name' => $userName,
            'password' => $password
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->sessionRestoreFromResponse($results);
        }
        return $userInfo;
    }

    /**
     * Log in a CMS admin user. Admin users sessions are used for secure transactions with the server, are not intended
     * to be used by users but rather the system administrator who is running the server. This is not to be called by
     * client-side code. Successful login will persist the CMS user admin session data for subsequent secured calls
     * and refresh the token when required.
     *
     * @param $userName: string the user's name or email address
     * @param $password: string the user's password
     * @return object: null if login fails, otherwise returns the user info object.
     */
    private function adminUserLogin ($userName, $password) {
        $userInfo = null;
        $isSecure = true;
        $service = 'CmsUserLogin';
        $parameters = [
            'user_name' => $userName,
            'password' => $password
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters, $isSecure);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->adminSessionResponse($results);
        }
        return $userInfo;
    }

    /**
     * For Co-registration/SSO, we take information provided by the hosting network and either setup a new user or update
     * an existing user. The unique key is $site_user_id and that plus one of $real_name or $user_name are required.
     * @param $parameters {object} array of key values of user information. Keys site_user_id, network_id, and one
     *   of real_name or user_name are required. email_address, dob, gender, scope, avatar_url, id_token are optional.
     * @param $saveSession {boolean} true to save this session for next page refresh.
     * @return {object} an $userInfo object. Same result as UserLogin.
     */
    public function userLoginCoreg ($coregParameters, $saveSession) {
        $service = 'UserLoginCoreg';
        $userInfo = null;
        // Convert parameters or use logical defaults
        $parameters = [
            'site_user_id' => $coregParameters['site_user_id'],
            'user_name' => isset($coregParameters['user_name']) ? $coregParameters['user_name'] : '',
            'real_name' => isset($coregParameters['real_name']) ? $coregParameters['real_name'] : '',
            'email_address' => isset($coregParameters['email_address']) ? $coregParameters['email_address'] : '',
            'gender' => isset($coregParameters['gender']) ? $coregParameters['gender'] : 'U',
            'dob' => isset($coregParameters['dob']) ? $coregParameters['dob'] : '',
            'network_id' => $coregParameters['network_id'],
            'scope' => isset($coregParameters['scope']) ? $coregParameters['scope'] : '',
            'agreement' => isset($coregParameters['agreement']) ? $coregParameters['agreement'] : '0',
            'avatar_url' => isset($coregParameters['avatar_url']) ? $coregParameters['avatar_url'] : '',
            'id_token' => isset($coregParameters['id_token']) ? $coregParameters['id_token'] : ''
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if ($results != null) {
            $userInfo = $this->sessionRestoreFromResponse($results);
        }
        return $userInfo;
    }

    /**
     * A keep-alive for the current logged in user. Will only refresh the user logged in table and user
     * last-login if there is a user currently logged in.
     * @return object
     */
    public function userLoginRefresh() {
        $service = 'UserLoginRefresh';
        $parameters = [];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        return $results;
    }

    /**
     * Logout the user clearing all internal cookies and data structures.
     * @return boolan true if successful. If false there was an internal error (logout should never really fail.)
     */
    public function userLogout () {
        $service = 'UserLogout';
        $parameters = [];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        $this->m_refreshToken = null;
        setcookie(REFRESH_COOKIE, '', time() - SESSION_EXPIRE_SECONDS, '/', $this->sessionCookieDomain(), true, false);
        $this->sessionClear();
        return $results != null;
    }

    /**
     * Determine if user registration parameters are valid. If not, indicate each
     * parameter that is invalid.
     *
     * @param integer $user_id Id of existing user to validate, or 0/null if a new registration.
     * @param Array $parameters Key/value object of registration data.
     * @return Array Keys that we think are unacceptable. null if everything is acceptable.
     */
    public function userRegistrationValidation ($user_id, $parameters) {
        $errors = [];

        $key = 'agreement';
        if ( ! isset($parameters[$key]) || ! $this->valueToBoolean($parameters[$key])) {
            array_push($errors, $key);
        }
        $key = 'user_name';
        if ( ! isset($parameters[$key]) || ! $this->isValidUserName($parameters[$key])) {
            array_push($errors, $key);
        }
        $key = 'email_address';
        if ( ! isset($parameters[$key]) || ! $this->isValidEmailAddress($parameters[$key])) {
            array_push($errors, $key);
        }
        if ($user_id == 0) {
            // This is a new registration
            $key = 'password';
            if ( ! isset($parameters[$key]) || ! $this->isValidPassword($parameters[$key])) {
                array_push($errors, $key);
            }
        }
        $key = 'dob';
        if (isset($parameters[$key]) && ! $this->isValidDate($parameters[$key])) {
            array_push($errors, $key);
        }
        $key = 'real_name';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 50, true, false)) {
            array_push($errors, $key);
        }
        $key = 'city';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 80, true, false)) {
            array_push($errors, $key);
        }
        $key = 'state';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 2, 2, true, false)) {
            array_push($errors, $key);
        }
        $key = 'country_code';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 2, 2, true, false)) {
            array_push($errors, $key);
        }
        $key = 'zipcode';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 5, 10, true, false)) {
            array_push($errors, $key);
        }
        $key = 'gender';
        if (isset($parameters[$key]) && ! $this->isValidGender($parameters[$key])) {
            array_push($errors, $key);
        }
        $key = 'tagline';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 255, true, true)) {
            array_push($errors, $key);
        }
        $key = 'about_me';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 500, true, true)) {
            array_push($errors, $key);
        }
        $key = 'additional_info';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 1000, true, true)) {
            array_push($errors, $key);
        }
        $key = 'mobile_number';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 20, true, false)) {
            array_push($errors, $key);
        }
        $key = 'im_id';
        if (isset($parameters[$key]) && ! $this->isValidString($parameters[$key], 0, 50, true, false)) {
            array_push($errors, $key);
        }
        return count($errors) > 0 ? $errors : null;
    }

    /**
     * Register a new user by calling the Enginesis function and wait for the response. We must convert and field data
     * from our version to the Enginesis version since we have multiple different ways to collect it.
     *
     * @param $userInfo {array} key/value object of registration data.
     * @return object: null if registration fails, otherwise returns the user info object and logs the user in.
     */
    public function userRegistration ($userInfo) {
        $service = 'RegisteredUserCreate';
        // @todo: user_name, email_address, password are required. We can send it up to the server and wait for a response but we could save time and do it here.
        $parameters = [
            'user_name' => $userInfo['user_name'],
            'password' => $userInfo['password'],
            'security_question_id' => $this->arrayValueOrDefault($userInfo, 'security_question_id', '3'),
            'security_answer' => $this->arrayValueOrDefault($userInfo, 'security_answer', ''),
            'email_address' => $userInfo['email_address'],
            'dob' => $userInfo['dob'],
            'real_name' => $this->arrayValueOrDefault($userInfo, 'real_name', $userInfo['user_name']),
            'city' => $this->arrayValueOrDefault($userInfo, 'city', ''),
            'state' => $this->arrayValueOrDefault($userInfo, 'state', ''),
            'zipcode' => $this->arrayValueOrDefault($userInfo, 'zipcode', ''),
            'country_code' => $this->arrayValueOrDefault($userInfo, 'country_code', 'US'),
            'tagline' => $this->arrayValueOrDefault($userInfo, 'tagline', ''),
            'gender' => $this->arrayValueOrDefault($userInfo, 'gender', 'U'),
            'mobile_number' => $this->arrayValueOrDefault($userInfo, 'mobile_number', ''),
            'im_id' => $this->arrayValueOrDefault($userInfo, 'im_id', ''),
            'img_url' => $this->arrayValueOrDefault($userInfo, 'img_url', ''),
            'about_me' => $this->arrayValueOrDefault($userInfo, 'about_me', ''),
            'additional_info' => $this->arrayValueOrDefault($userInfo, 'additional_info', ''),
            'agreement' => '1',
            'captcha_id' => '99999',
            'captcha_response' => 'DEADMAN',
            'site_user_id' => '',
            'network_id' => '1',
            'source_site_id' => $this->m_siteId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $userInfoResult = $results[0];
            $cr = $userInfoResult->cr;
            // @todo: Verify hash to make sure payload was not tampered
            $user_id = $userInfoResult->user_id;
            $secondary_password = $userInfoResult->secondary_password;
            // @todo: If this site auto-confirms user registration then we should log the user in automatically now.
            // We know this because the server gives us the token when we are to do this.
            if (isset($userInfoResult->authtok)) {
                $this->sessionSave($userInfoResult->authtok, $userInfoResult->user_id, $userInfoResult->user_name, $userInfoResult->site_user_id, $userInfoResult->access_level, EnginesisNetworks::Enginesis);
                $this->sessionUserInfoSave($userInfoResult);
            }
        } else {
            $userInfoResult = null;
        }
        return $userInfoResult;
    }

    /**
     * Update and existing user's registration information.
     * @param $parameters: key/value object of registration data. Only changed keys may be provided.
     * @return object: null if registration fails, otherwise returns the user info object.
     */
    public function registeredUserUpdate ($userInfo) {
        $service = 'RegisteredUserUpdate';
        $parameters = [
            'user_name' => $userInfo['user_name'],
            'email_address' => $userInfo['email_address'],
            'dob' => $userInfo['dob'],
            'real_name' => $this->arrayValueOrDefault($userInfo, 'real_name', $userInfo['user_name']),
            'city' => $this->arrayValueOrDefault($userInfo, 'city', ''),
            'state' => $this->arrayValueOrDefault($userInfo, 'state', ''),
            'zipcode' => $this->arrayValueOrDefault($userInfo, 'zipcode', ''),
            'country_code' => $this->arrayValueOrDefault($userInfo, 'country_code', 'US'),
            'tagline' => $this->arrayValueOrDefault($userInfo, 'tagline', ''),
            'gender' => $this->arrayValueOrDefault($userInfo, 'gender', 'U'),
            'mobile_number' => $this->arrayValueOrDefault($userInfo, 'mobile_number', ''),
            'im_id' => $this->arrayValueOrDefault($userInfo, 'im_id', ''),
            'img_url' => $this->arrayValueOrDefault($userInfo, 'img_url', ''),
            'about_me' => $this->arrayValueOrDefault($userInfo, 'about_me', ''),
            'additional_info' => $this->arrayValueOrDefault($userInfo, 'additional_info', ''),
            'captcha_id' => '99999',
            'captcha_response' => 'DEADMAN'
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        }
        return $results;
    }

    /**
     * Confirm a new user registration given the user-id and the token. These are supplied in the email sent when
     * a new registration is created with RegisteredUserCreate. If successful the user is logged in and a login
     * token (authtok) is sent back from the server.
     * @param $userId
     * @param $secondaryPassword
     * @return null|object
     */
    public function registeredUserConfirm ($userId, $secondaryPassword) {
        $service = 'RegisteredUserConfirm';
        $parameters = [
            'user_id' => $userId,
            'secondary_password' => $secondaryPassword
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $userInfoResult = $results[0];
            // @todo: Verify hash to make sure payload was not tampered
            // @todo: If this site auto-confirms user registration then we should log the user in automatically now.
            // We know this because the server gives us the token when we are to do this.
            if (isset($userInfoResult->authtok)) {
                $this->sessionSave($userInfoResult->authtok, $userInfoResult->user_id, $userInfoResult->user_name, $userInfoResult->site_user_id, $userInfoResult->access_level, EnginesisNetworks::Enginesis);
                $this->sessionUserInfoSave($userInfoResult);
                $results = $userInfoResult;
            }
        }
        return $results;
    }

    /**
     * Get the security info for the current logged in user.
     * Returns {mobile_number, security_question_id, security_question, security_answer}
     * @return null|object
     */
    public function registeredUserSecurityGet () {
        $service = 'RegisteredUserSecurityGet';
        $parameters = [
            'site_user_id' => '',
            'network_id' => 1,
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        } else {
            $results = null;
        }
        return $results;
    }

    /**
     * Determine if user security parameters are valid. If not, indicate the first parameter that is invalid.
     * @param $user_id int
     * @param $mobile_number string
     * @param $security_question_id int
     * @param $security_question string
     * @param $security_answer string
     * @return array|null Null if all acceptable, otherwise an array of key/value pairs that we think are unacceptable. key is the field in error, value is the error message.
     */
    public function registeredUserSecurityValidation ($user_id, $mobile_number, $security_question_id, $security_question, $security_answer) {
        $errors = [];
        $key = 'mobile_number';
        $questionOK = true;
        if ( ! $this->isValidString($mobile_number, 7, 20, true, false)) {
            array_push($errors, $key);
        }
        $key = 'security_question_id';
        if ( ! $this->isValidId($security_question_id)) {
            array_push($errors, $key);
        }
        $key = 'security_answer';
        if ( ! $this->isValidString($security_answer, 3, 50, true, false)) {
            array_push($errors, $key);
            $questionOK = false;
        }
        $key = 'security_question';
        if ( ! $this->isValidString($security_question, 4, 80, true, false)) {
            array_push($errors, $key);
            $questionOK = false;
        }
        $q = strlen($security_question);
        $a = strlen($security_answer);
        if ($questionOK && ($q xor $a)) {
            // both question and answer must be empty or not empty
            array_push($errors, $key);
        }
        return count($errors) > 0 ? $errors : null;
    }

    /**
     * Set the security info for the current logged in user.
     * @param $mobile_number - this is optional, pass either null or empty string to skip.
     * @param $security_question_id
     * @param $security_question
     * @param $security_answer
     * @return null|object - if null then check getLastError(), otherwise the current user-id.
     */
    public function registeredUserSecurityUpdate ($mobile_number, $security_question_id, $security_question, $security_answer) {
        $service = 'RegisteredUserSecurityUpdate';
        $parameters = [
            'mobile_number' => $mobile_number,
            'security_question_id' => $security_question_id,
            'security_question' => $security_question,
            'security_answer' => $security_answer,
            'captcha_id' => '99999',
            'captcha_response' => 'DEADMAN'
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        } else {
            $results = null;
        }
        return $results;
    }

    /**
     * User requests to change the password. Requires a secondary password token that is returned from this service.
     * You need to supply that token when calling registeredUserPasswordChange.
     * @return null|object
     */
    public function registeredUserRequestPasswordChange () {
        $service = 'RegisteredUserRequestPasswordChange';
        $parameters = [];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        } else {
            $results = null;
        }
        return $results;
    }

    /**
     * Complete the setting of a new password for the user who is logged in. Requires a authenticated user, a
     * new password and the secondary password token that was given with RegisteredUserRequestPasswordChange.
     * The token expires in 24 hours.
     * @param $newPassword
     * @param $secondaryPassword
     * @return null|object
     */
    public function registeredUserPasswordChange ($newPassword, $secondaryPassword) {
        $service = 'RegisteredUserPasswordChange';
        $parameters = [
            'password' => $newPassword,
            'secondary_password' => $secondaryPassword,
            'captcha_id' => '99999',
            'captcha_response' => 'DEADMAN'
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        } else {
            $results = null;
        }
        return $results;
    }

    /**
     * Complete the setting of a new password for a user who is not currently logged in. Requires the user-id,
     * a new password and the secondary password token that was given with RegisteredUserRequestPasswordChange.
     * The token expires in 24 hours.
     * @param $userId
     * @param $newPassword
     * @param $secondaryPassword
     * @return null|object
     */
    public function registeredUserPasswordChangeUnauth ($userId, $newPassword, $secondaryPassword) {
        $service = 'RegisteredUserPasswordChangeUnauth';
        $parameters = [
            'user_id' => $userId,
            'password' => $newPassword,
            'secondary_password' => $secondaryPassword,
            'captcha_id' => '99999',
            'captcha_response' => 'DEADMAN'
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        } else {
            $results = null;
        }
        return $results;
    }

    /**
     * Trigger the forgot password procedure. The server will reset the user's password and
     * send an email to the email address on record to follow a link to reset the password.
     * Either user name or email address are required, not both. The backend will use the
     * value supplied to look up the account since both email address and user name are
     * required to be unique.
     * @param string $userName the user's name
     * @param string $email_address the user's email address
     * @return null|object: Enginesis response object with the user's temporary password if
     * the request succeeded, or an Enginesis error if the process failed.
     */
    public function userForgotPassword ($userName, $email_address) {
        $service = 'RegisteredUserForgotPassword';
        $parameters = ['user_name' => $userName, 'email_address' => $email_address];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $result = $results[0];
        } else {
            $result = $results;
            $this->debugCallback('userForgotPassword failed: ' . $this->m_lastError['message'] . ' / ' . $this->m_lastError['extended_info'], __FILE__, __LINE__);
        }
        return $result;
    }

    /**
     * Trigger the reset password procedure. The server will reset the user's password and
     *   send an email to the email address on record to follow a link to reset the password.
     * @return bool: true if the process was started, false if there was an error.
     */
    public function userResetPassword () {
        $service = 'RegisteredUserResetPassword';
        $parameters = [];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Start a user delete request. Either provide and Enginesis user id
     * as a parameter or call `$enginesis->setSiteUserId($network_id, $site_user_id)`
     * to set the network user to delete. Must be logged in as a CMS user to do this.
     * @param integer|null A user id to delete. If null, use the registered network site user id.
     * @return Array Server response if succeeded, Enginesis error when failed.
     */
    public function registeredUserDelete ($userId = null) {
        $service = 'RegisteredUserDelete';
        $isSecure = true;
        $parameters = null;
        if ( ! empty($this->m_siteUserId) && $this->m_networkId != 1) {
            $parameters = [
                'user_id_to_delete' => 0,
                'site_user_id' => $this->m_siteUserId,
                'network_id' => $this->m_networkId
            ];
        } elseif ($this->isValidId($userId)) {
            $parameters = [
                'user_id_to_delete' => $this->m_userId,
                'site_user_id' => '',
                'network_id' => 0
            ];
        }
        if ($parameters != null) {
            $enginesisResponse = $this->callServerAPI($service, $parameters, $isSecure);
            return $this->setLastErrorFromResponse($enginesisResponse);
        } else {
            return $this->makeErrorResponse(EnginesisErrors::INVALID_USER_ID, 'User id is not valid.', null);
        }
    }

    /**
     * Get information about a user deletion request.
     * @param string confirmation code.
     * @return Array Array of rows if succeeded, null when failed.
     */
    public function registeredUserDeleteGet ($confirmationCode) {
        $service = 'RegisteredUserDeleteGet';
        $parameters = [
            'ccode' => $confirmationCode
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * When the user comes back to reset the password.
     * @param $userId: int the user's internal user id.
     * @param $newPassword: string the user's replacement password.
     * @param $token: string the user's granted token allowing the reset from an authorized source.
     * @return object: null if reset fails, otherwise returns the user info object and logs the user in.
     */
    public function userVerifyForgotPassword ($userId, $newPassword, $token) {
        $service = 'RegisteredUserVerifyForgotPassword';
        $parameters = ['user_id' => $userId, 'password' => $newPassword, 'token' => $token];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * If the secondary password expires or the user lost it, we come here to generate a new one and send a new email.
     * site-id, one of [userId, userName, email] to identify the user, and previous token must match otherwise
     * returns INVALID_USER_ID error.
     *
     * @param integer $userId
     * @param string $userName
     * @param string $email
     * @param string $secondaryPassword
     * @return boolean
     */
    public function registeredUserResetSecondaryPassword ($userId, $userName, $email, $secondaryPassword) {
        $service = 'RegisteredUserResetSecondaryPassword';
        $parameters = ['user_id' => $userId, 'user_name' => $userName, 'email' => $email, 'secondary_password' => $secondaryPassword];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * The general public user get - returns a minimum set of public attributes about a user.
     * @param $userId - may be either an int indicating a user_id or a string indicating a user_name.
     * @return object A user info object containing only the public attributes.
     */
    public function userGet ($userId) {
        $user = null;
        if (is_numeric ($userId)) {
            $service = 'UserGet';
            if ($userId < 9999) {
                $userId = $this->m_userId;
            }
            $enginesisResponse = $this->callServerAPI($service, ['get_user_id' => $userId]);
        } elseif ($this->isValidUserName($userId)) {
            $service = 'UserGetByName';
            $enginesisResponse = $this->callServerAPI($service, ['user_name' => $userId]);
        }
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $user = $results[0];
        }
        return $user;
    }

    /**
     * The general public user get by a user name - returns a minimum set of public attributes about a user.
     * @param $userName - may be either an int indicating a user_id or a string indicating a user_name.
     * @return object A user info object containing only the public attributes.
     */
    public function userGetByName ($userName) {
        $service = 'UserGetByName';
        $user = null;
        if ($this->isValidUserName($userName)) {
            $parameters = ['user_name' => $userName];
            $enginesisResponse = $this->callServerAPI($service, $parameters);
            $results = $this->setLastErrorFromResponse($enginesisResponse);
            if (is_array($results) && count($results) > 0) {
                $user = $results[0];
            }
        }
        return $user;
    }

    /**
     * Get information about a given user. If no user is provided and there is a logged in user, then
     * returns the info about the logged in user. Note if getting info on the current logged in user
     * then there could be more attributes that are intended to be visible only by that user (not public info).
     * @param int $userId optional user id to get info on.
     * @param string $siteUserId optional site user id to get info on.
     * @param int $networkId network owning site user id.
     * @return object the attributes of the requested user, null if no such user or error.
     */
    public function registeredUserGet ($userId = 0, $siteUserId = '', $networkId = 1) {
        $service = 'RegisteredUserGet';
        $user = null;
        $parameters = [];
        if ($userId != 0) {
            if ($userId < 9999) {
                $userId = $this->m_userId;
            }
            $parameters['get_user_id'] = $userId;
        }
        if ($siteUserId != '') {
            $parameters['site_user_id'] = $siteUserId;
            $parameters['network_id'] = $networkId;
        }
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        }
        return $results;
    }

    /**
     * Get extended information about a given user. If no user is provided and there is a logged in user, then
     * returns the info about the logged in user. Note if getting info on the current logged in user
     * then there could be more attributes that are intended to be visible only by that user (not public info).
     * @param int $userId optional user id to get info on.
     * @param string $siteUserId optional site user id to get info on.
     * @param int $networkId network owning site user id.
     * @return object the attributes of the requested user, null if no such user or error.
     */
    public function registeredUserGetEx ($userId = 0, $siteUserId = '', $networkId = 1) {
        $service = 'RegisteredUserGetEx';
        $user = null;
        $parameters = [];
        if ($userId != 0) {
            if ($userId < 9999) {
                $userId = $this->m_userId;
            }
            $parameters['get_user_id'] = $userId;
        }
        if ($siteUserId != '') {
            $parameters['site_user_id'] = $siteUserId;
            $parameters['network_id'] = $networkId;
        }
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $results = $results[0];
        }
        return $results;
    }

    /**
     * Find users that match a given search criteria. The search is performed against certain publis attributes,
     * such as user-name, tag-line, additional-info, about-me.
     * @param $searchString
     * @return array a list of matching users an a subset of user attributes - {user_id, user_name, date_created, site_currency_value, site_experience_points}
     */
    public function registeredUserFind ($searchString) {
        $service = 'RegisteredUserGetEx';
        $parameters = ['search_str' => $searchString];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Track a hit on the newsletter.
     * @param $userId
     * @param $newsletterId
     * @param $event
     * @param $eventDetails
     * @param $referrer
     * @return bool
     */
    public function newsletterTrackingRecord ($userId, $newsletterId, $event, $eventDetails, $referrer) {
        $service = 'NewsletterTrackingRecord';
        $parameters = [
            'u_id' => $userId,
            'newsletter_id' => $newsletterId,
            'event_id' => $event,
            'event_details' => $eventDetails,
            'referrer' => $referrer
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Subscribe an email address to the site newsletter or newsletter based by category selected.
     */
    public function newsletterAddressAssign ($emailAddress, $userName = '', $companyName = '', $categories = '') {
        $service = 'NewsletterAddressAssign';
        $parameters = [
            'email_address' => $emailAddress,
            'user_name' => $userName,
            'company_name' => $companyName,
            'categories' => $categories
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Unsubscribe the email address from all newsletters.
     */
    public function newsletterAddressDelete ($emailAddress, $newsletterAddressId = 0) {
        $service = 'NewsletterAddressDelete';
        $parameters = [
            'email_address' => $emailAddress,
            'newsletter_address_id' => $newsletterAddressId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Return the proper URL to use to show an avatar for a given user. The default is the default size and the current user.
     * This URL should always return an image.
     * @param int $size Enginesis avatar size. 0, 1, 2.
     * @param int $userId Enginesis user id.
     * @return string URL to image.
     */
    public function avatarURL ($size = 0, $userId = 0) {
        if ( ! $this->isValidId($userId)) {
            $userId = $this->m_userId;
        }
        return $this->m_avatarEndPoint . '?site_id=' . $this->m_siteId . '&user_id=' . $userId . '&size=' . $size;
    }

    /**
     * Return a list of all games for a given site id.
     * @param integer $start Query starting item index number.
     * @param integer $numberOfItems Number of items to return.
     * @param integer $statusId Status of games to return (2=active)
     * @return Array An array of objects.
     */
    public function siteListGames ($start = 1, $numItems = 50, $statusId = 2) {
        $service = 'SiteListGames';
        $parameters = [
            'start_item' => $start,
            'num_items' => $numItems,
            'game_status_id' => $statusId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Get meta data details about a specific game given its unique game identifier.
     * @param int $game_id the id of the game to get information about.
     * @return object
     */
    public function gameGet ($gameId) {
        $service = 'GameGet';
        $parameters = ['game_id' => $this->isValidId($gameId) ? $gameId : $this->m_gameId];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $gameInfo = $results[0];
        } else {
            $gameInfo = null;
        }
        return $gameInfo;
    }

    /**
     * Get meta data details about a specific game given its unique game name.
     * @param int $game_id the id of the game to get information about.
     * @return object
     */
    public function gameGetByName ($gameName) {
        $service = 'GameGetByName';
        $parameters = ['game_name' => $gameName];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $gameInfo = $results[0];
        } else {
            $gameInfo = null;
        }
        return $gameInfo;
    }

    /**
     * Set or update a game rating. Game ratings are for example 1 to 5 stars (but can be
     * any arbitrary range 0 to 100.)
     */
    public function gameRatingUpdate ($rating, $gameId) {
        $service = 'GameRatingUpdate';
        $parameters = [
            'game_id' => $this->isValidId($gameId) ? $gameId : $this->m_gameId,
            'rating' => ($rating < 0 || $rating > 100) ? 5 : $rating];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $gameInfo = $results[0];
        } else {
            $gameInfo = null;
        }
        return $gameInfo;
    }

    /**
     * Return the game rating for a specific game id.
     */
    public function gameRatingGet ($gameId) {
        $service = 'GameRatingGet';
        $parameters = ['game_id' => $this->isValidId($gameId) ? $gameId : $this->m_gameId];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $gameInfo = $results[0];
        } else {
            $gameInfo = null;
        }
        return $gameInfo;
    }

    /**
     * Return a list of rated games by their rating, highest to lowest.
     */
    public function gameRatingList ($numberOfGames) {
        $service = 'GameRatingList';
        $parameters = ['num_items' => ($numberOfGames < 1 || $numberOfGames > 100) ? 5 : $numberOfGames];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Return a list of games given a game list id.
     * @param integer $gameListId A predefined game list identifier.
     * @return Array An array of objects.
     */
    public function gameListListGames ($gameListId) {
        $service = 'GameListListGames';
        $parameters = ['game_list_id' => $gameListId];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Return a list of games given their game ids.
     * @param string $listOfGameIds A list of game ids to look up, each separated by $delimiter.
     * @param string $delimiter A single character used to separate the game ids, such as ,.
     * @return Array An array of objects.
     */
    public function gameListByIdList ($listOfGameIds, $delimiter) {
        $service = 'GameListByIdList';
        if (empty($listOfGameIds)) {
            $listOfGameIds = '' . $this->m_gameId . '';
        }
        if (empty($delimiter)) {
            $delimiter = ',';
        }
        $parameters = ['game_id_list' => $listOfGameIds, 'delimiter' => $delimiter];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Return a list of games given a search string.
     * @param string $search A string to search for.
     * @return Array An array of objects.
     */
    public function gameFind ($search) {
        $service = 'GameFind';
        $parameters = ['game_name_part' => $search];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Set or update a vote for a URI.
     */
    public function voteForURIUnauth($uri, $voteGroupURI, $voteValue, $securityKey) {
        $service = 'VoteForURIUnauth';
        $parameters = [
            'uri' => $uri,
            'vote_group_uri' => $voteGroupURI,
            'vote_value' => $voteValue,
            'security_key' => $securityKey
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Get the vote totals for a URI group.
     */
    public function voteCountPerURIGroup($voteGroupURI) {
        $service = 'VoteCountPerURIGroup';
        $parameters = [
            'vote_group_uri' => $voteGroupURI
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Get meta data information about a developer.
     */
    public function developerGet($developerId) {
        $service = 'DeveloperGet';
        $parameters = [
            'developer_id' => $developerId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Get the game data for a specific game id.
     */
    public function gameDataGet($gameId) {
        $service = 'GameDataGet';
        $parameters = ['game_id' => $this->isValidId($gameId) ? $gameId : $this->m_gameId];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Helper function to manage the local user favorite games cache.
     * @param integer $gameId A game id to add to the users favorite games cache.
     */
    private function addToUserFavoriteGamesCache($gameId) {
        if ($this->m_favoriteGames != null && ! in_array($gameId, $this->m_favoriteGames)) {
            array_push($this->m_favoriteGames, $gameId);
        }
    }

    /**
     * Helper function to manage the local user favorite games cache.
     * @param integer $gameId A game id to remove from the users favorite games cache.
     */
    private function removeFromUserFavoriteGamesCache($gameId) {
        if ($this->m_favoriteGames != null && in_array($gameId, $this->m_favoriteGames)) {
            $index = array_search($gameId, $this->m_favoriteGames);
            array_splice($this->m_favoriteGames, $index, 1);
        }
    }

    /**
     * Helper function to manage the local user favorite games cache.
     * @param EnginesisResponse $serverResponse A list of the current favorite games delivered
     *   from the server as a response to a favorite games query.
     */
    private function updateUserFavoriteGamesCache($serverResponse) {
        $this->m_favoriteGames = [];
        $this->m_favoriteGamesNextCheck = time() + 300; // check again in 5 minutes
        if (count($serverResponse) > 0) {
            for ($i = 0; $i < count($serverResponse); $i += 1) {
                array_push($this->m_favoriteGames, $serverResponse[$i]->game_id);
            }
        }
    }

    /**
     * Determine if the given gameId is a favorite game. We check the local favorite
     * games cache and if it is still valid (within ~5 minutes since last update). If
     * the cache is no longer valid then we query the server for the user's favorite games.
     * @param integer $gameId Which game to check, the default is the current set game if there is one.
     * @return bool true if it is a favorite of the current logged in user.
     */
    public function isUserFavoriteGame($gameId) {
        $verifiedGameId = $this->isValidId($gameId) ? $gameId : $this->m_gameId;
        if ( ! ($this->m_favoriteGames != null
             && $this->m_favoriteGamesNextCheck != null
             && $this->m_favoriteGamesNextCheck < (time() + 300))) {
            $response = $this->userFavoriteGamesList();
        }
        return in_array($verifiedGameId, $this->m_favoriteGames);
    }

    /**
     * Return the favorite game list for the current logged in user.
     */
    public function userFavoriteGamesList() {
        $service = 'UserFavoriteGamesList';
        $parameters = [];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $response = $this->setLastErrorFromResponse($enginesisResponse);
        if ( ! $this->isError(null)) {
            $this->updateUserFavoriteGamesCache($response);
        }
        return $response;
    }

    /**
     * Add or update a game to the user's favorite game list.
     */
    public function userFavoriteGamesAssign($gameId) {
        $service = 'UserFavoriteGamesAssign';
        $verifiedGameId = $this->isValidId($gameId) ? $gameId : $this->m_gameId;
        if ($this->isValidId($verifiedGameId)) {
            $parameters = ['game_id' => $verifiedGameId];
            $enginesisResponse = $this->callServerAPI($service, $parameters);
            $response = $this->setLastErrorFromResponse($enginesisResponse);
            if ( ! $this->isError()) {
                $this->addToUserFavoriteGamesCache($verifiedGameId);
            }
            return $response;
        } else {
            return $this->makeErrorResponse(EnginesisErrors::INVALID_GAME_ID, 'Game id is not valid.', null);
        }
    }

    /**
     * Add or update a list of games to the user's favorite game list.
     */
    public function userFavoriteGamesAssignList($gameIdList) {
        $service = 'UserFavoriteGamesAssignList';
        if (is_array($gameIdList)) {
            $gameIdList = implode(',', $gameIdList);
        }
        $parameters = [
            'game_id_list' => $gameIdList,
            'delimiter' => ','
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $response = $this->setLastErrorFromResponse($enginesisResponse);
        if ( ! $this->isError(null)) {
            $this->updateUserFavoriteGamesCache($response);
        }
        return $response;
    }

    /**
     * Remove a game from the user's favorite game list.
     */
    public function userFavoriteGamesUnassign($gameId) {
        $service = 'UserFavoriteGamesUnassign';
        $verifiedGameId = $this->isValidId($gameId) ? $gameId : $this->m_gameId;
        if ($this->isValidId($verifiedGameId)) {
            $parameters = ['game_id' => $verifiedGameId];
            $enginesisResponse = $this->callServerAPI($service, $parameters);
            $response = $this->setLastErrorFromResponse($enginesisResponse);
            if ( ! $this->isError(null)) {
                $this->removeFromUserFavoriteGamesCache($verifiedGameId);
            }
            return $response;
        } else {
            return $this->makeErrorResponse(EnginesisErrors::INVALID_GAME_ID, 'Game id is not valid.', null);
        }
    }

    /**
     * Remove a list of games from the user's favorite game list.
     */
    public function userFavoriteGamesUnassignList($gameIdList) {
        $service = 'UserFavoriteGamesUnassignList';
        if (is_array($gameIdList)) {
            $gameIdList = implode(',', $gameIdList);
        }
        $parameters = [
            'game_id_list' => $gameIdList,
            'delimiter' => ','
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $response = $this->setLastErrorFromResponse($enginesisResponse);
        if ( ! $this->isError(null)) {
            $this->updateUserFavoriteGamesCache($response);
        }
        return $response;
    }

    /**
     * Change the position of a game in the user's favorite game list.
     */
    public function userFavoriteGamesMove($gameId, $sortOrder) {
        $service = 'UserFavoriteGamesMove';
        $parameters = [
            'game_id' => $this->isValidId($gameId) ? $gameId : $this->m_gameId,
            'sort_order' => $sortOrder
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    // =============================================================================================================
    // Promotion API
    // =============================================================================================================

    /**
     * Return a list of promotions given a promotion id.
     */
    public function promotionList($promotionId, $queryDate = null, $showItems = false) {
        $service = 'PromotionList';
        if ($queryDate != null) {
            $queryDate = $this->mySQLDate($queryDate);
        }
        $parameters = [
            'promotion_id' => $promotionId,
            'query_date' => $queryDate,
            'show_items' => $showItems ? '1' : '0'
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }

    /**
     * Return a list of promoted items given a promotion id.
     */
    public function promotionItemList($promotionId, $queryDate = null) {
        $service = 'PromotionItemList';
        if ($queryDate != null) {
            $queryDate = $this->mySQLDate($queryDate);
        }
        $parameters = [
            'promotion_id' => $promotionId,
            'query_date' => $queryDate
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        return $results;
    }

    // =============================================================================================================
    // Conference API
    // =============================================================================================================

    /**
     * Return the path to the conference assets.
     */
    public function conferenceAssetRootPath($conferenceId) {
        return $this->getServiceRoot() . 'sites/' . $this->m_siteId . '/conf/' . $conferenceId . '/';
    }

    /**
     * Return meta information about a specific conference.
     */
    public function conferenceGet($conferenceId) {
        $service = 'ConferenceGet';
        if ( ! is_integer($conferenceId)) {
            $visibleId = $conferenceId;
            $conferenceId = 0;
        } else {
            $visibleId = '';
        }
        $parameters = [
            'conference_id' => $conferenceId,
            'visible_id' => $visibleId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $result = $results[0];
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Return meta information about a specific topic in a conference.
     */
    public function conferenceTopicGet($conferenceId, $topicId) {
        $service = 'ConferenceTopicGet';
        if ( ! is_integer($conferenceId)) {
            $visibleId = $conferenceId;
            $conferenceId = 0;
        } else {
            $visibleId = '';
        }
        $parameters = [
            'conference_id' => $conferenceId,
            'visible_id' => $visibleId,
            'conference_topic_id' => $topicId
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        $results = $this->setLastErrorFromResponse($enginesisResponse);
        if (is_array($results) && count($results) > 0) {
            $result = $results[0];
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Return a list of topics in a given conference.
     */
    public function conferenceTopicList($conferenceId, $tags, $startDate, $endDate, $startItem, $numItems) {
        $service = 'ConferenceTopicList';
        if ( ! is_integer($conferenceId)) {
            $visibleId = $conferenceId;
            $conferenceId = 0;
        } else {
            $visibleId = '';
        }
        $parameters = [
            'conference_id' => $conferenceId,
            'visible_id' => $visibleId,
            'tags' => $tags,
            'start_date' => $this->mySQLDate($startDate),
            'end_date' => $this->mySQLDate($endDate),
            'start_item' => $startItem,
            'num_items' => $numItems
        ];
        $enginesisResponse = $this->callServerAPI($service, $parameters);
        return $this->setLastErrorFromResponse($enginesisResponse);
    }
}
