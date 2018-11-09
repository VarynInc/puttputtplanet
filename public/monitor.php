<?php
// Verify the server is operating correctly. This page is used from monitor check systems to verify
// the web server is running and all systems are performing as expected.
require_once('../services/common.php');
require_once('../services/classes/Database.php');

// $pageok is true until a check fails and set it to false
$pageok = true;
$subsystem = 'SysMon';
$showInfo = isset($_GET['info']) && $_GET['info'] == 1;

$errorInfo = 'Monitor.php requested';
if ($showInfo) {
    $errorInfo .= ' + INFO';
}
if ($showInfo) {
    $enginesisLogger->log($errorInfo, LogMessageLevel::Info, $subsystem, __FILE__, __LINE__);
}

if ($showInfo) {
    phpinfo();
    echo("<p><pre>");
    if (function_exists('gd_info')) {
        var_dump(gd_info());
    }
    echo("</pre></p>");
    $indicesServer = ['PHP_SELF', 
        'argv', 
        'argc', 
        'GATEWAY_INTERFACE', 
        'SERVER_ADDR', 
        'SERVER_NAME', 
        'SERVER_SOFTWARE', 
        'SERVER_PROTOCOL', 
        'REQUEST_METHOD', 
        'REQUEST_TIME', 
        'REQUEST_TIME_FLOAT', 
        'QUERY_STRING', 
        'DOCUMENT_ROOT', 
        'HTTP_ACCEPT', 
        'HTTP_ACCEPT_CHARSET', 
        'HTTP_ACCEPT_ENCODING', 
        'HTTP_ACCEPT_LANGUAGE', 
        'HTTP_CONNECTION', 
        'HTTP_HOST', 
        'HTTP_REFERER', 
        'HTTP_USER_AGENT', 
        'HTTPS', 
        'REMOTE_ADDR', 
        'REMOTE_HOST', 
        'REMOTE_PORT', 
        'REMOTE_USER', 
        'REDIRECT_REMOTE_USER', 
        'SCRIPT_FILENAME', 
        'SERVER_ADMIN', 
        'SERVER_PORT', 
        'SERVER_SIGNATURE', 
        'PATH_TRANSLATED', 
        'SCRIPT_NAME', 
        'REQUEST_URI', 
        'PHP_AUTH_DIGEST', 
        'PHP_AUTH_USER', 
        'PHP_AUTH_PW', 
        'AUTH_TYPE', 
        'PATH_INFO', 
        'ORIG_PATH_INFO'];

    echo '<table cellpadding="10" style="margin: 0 auto;">' ; 
    foreach ($indicesServer as $arg) { 
        echo '<tr><td>' . $arg . '</td><td>' . (isset($_SERVER[$arg]) ? $_SERVER[$arg] : '-') . '</td></tr>' ; 
    } 
    echo '</table>' ; 
    echo("<br/>\n");
}

$testStatus = verifyStage(true);
if (count($testStatus) > 0) {
    foreach ($testStatus as $key => $value) {
        if ($showInfo) {
            $pass = $value ? "OK" : "FAILED";
            echo("<p>$key $pass</p>");
        }
        if ( ! $value) {
            $pageok = false;
            $enginesisLogger->log("Server verification fails for test $key", LogMessageLevel::Error, $subsystem);
        }
    }
}

if ($pageok) {
	echo 'PAGEOK';
} else {
    $enginesisLogger->log($errorInfo, LogMessageLevel::Error, $subsystem);
    echo 'ERROR';
}