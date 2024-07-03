<?php /* version.php - define the version of the code base
 * and lock the system if under maintenance.
 */
if ( ! defined('PUTTPUTTPLANET_VERSION')) {
    define('PUTTPUTTPLANET_VERSION', "1.0.5");
}
define('PUTTPUTTPLANET_ADMIN_LOCK', false);
define('ADMIN_LOCK_MESSAGE', '<h1>Putt Putt Planet is OFFLINE</h1><p>The Putt Putt Planet service is currently OFFLINE, most probably due to server maintenance.<br/>If you have an immediate need to change something please contact Putt Putt Planet support <a href="mailto:support@puttputtplanet.com">support@puttputtplanet.com</a>.</p>');
if (PUTTPUTTPLANET_ADMIN_LOCK) {
    header ("Location: /offline.html");
    exit(0);
}

function getServiceVersion() {
    return PUTTPUTTPLANET_VERSION;
}
