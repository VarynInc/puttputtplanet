<?php
// Verify the server is operating correctly. This page is used from monitor check systems to verify
// the web server is running and all systems are performing as expected.
require_once('../services/common.php');

// Show additional verification information
$showInfo = isset($_GET['info']) && $_GET['info'] == 990;

// Verify PHP is properly loaded and we have common.php properly loaded
$pageok = true;
if ( ! isset($enginesisLogger) || $enginesisLogger == null) {
    $pageok = false;
    $enginesisLogger->log("Server verification fails with no logging system", LogMessageLevel::Error, 'SYSMON');
}
$version = defined('PUTTPUTTPLANET_VERSION') ? PUTTPUTTPLANET_VERSION : null;
$pageok = strlen($version) > 0;

// Verify we're on a known server stage
if ($pageok) {
    $serverStage = serverStage();
    $pageok = strlen($serverStage) == 0 || preg_match('/^-[dlqx]$/', $serverStage) === 1;
}
if ( ! $pageok) {
    $enginesisLogger->log("Server verification fails at stage/version check", LogMessageLevel::Error, 'SYSMON');
}

if ($showInfo) {
    echo("<h1>" . ENGINESIS_SITE_NAME . ' ' . $serverStage . ' (' . ENGINESIS_SITE_ID . ') version ' . PUTTPUTTPLANET_VERSION . "</h1>");

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

    echo('<table cellpadding="10" style="margin: 0 auto;">');
    foreach ($indicesServer as $arg) { 
        echo('<tr><td>' . $arg . '</td><td>' . (isset($_SERVER[$arg]) ? $_SERVER[$arg] : '-') . '</td></tr>');
    } 
    echo('</table>');
    echo("<br/>\n");

    // Show off a bunch of configuration data to help verify the server is in working order.
    echo('<table cellpadding="10" style="margin: 0 auto;">');
    echo('<tr><td>SERVER_ROOT</td><td>' . SERVER_ROOT . '</td></tr>');
    echo('<tr><td>SERVER_DATA_PATH</td><td>' . SERVER_DATA_PATH . '</td></tr>');
    echo('<tr><td>SERVER_PRIVATE_PATH</td><td>' . SERVER_PRIVATE_PATH . '</td></tr>');
    echo('<tr><td>SERVICE_ROOT</td><td>' . SERVICE_ROOT . '</td></tr>');
    echo('<tr><td>VIEWS_ROOT</td><td>' . VIEWS_ROOT . '</td></tr>');
    echo('</table>');
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
            $enginesisLogger->log("Server verification fails for test $key", LogMessageLevel::Error, 'SYSMON');
        }
    }
}

// Verify we can contact Enginesis and run a public service
if ($pageok) {
    $response = $enginesis->siteGet($siteId);
    if ($response != null) {
        $pageok = $response->site_id == $siteId;
        if ($showInfo) {
            echo("<h4>siteGet $siteId</h4>");
            echo("<pre><code>");
            var_dump($response);
            echo("</code></pre>");
        }
    } else {
        $pageok = false;
        $enginesisLogger->log("Server verification fails for siteGet $siteId", LogMessageLevel::Error, 'SYSMON');
        if ($showInfo) {
            echo("<h4>siteGet $siteId</h4><p>FAILED</p>");
        }
    }
}

if ($pageok) {
    echo 'PAGEOK';
} else {
    echo 'ERROR';
}
