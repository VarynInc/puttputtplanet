<?php /* version.php - define the version of the code base
 * and lock the system if under maintenance.
 */
if ( ! defined('PUTTPUTTPLANET_VERSION')) {
    define('PUTTPUTTPLANET_VERSION', '0.1.1');
}
define('PUTTPUTTPLANET_ADMIN_LOCK', false);
define('ADMIN_LOCK_MESSAGE', '<h1>PUTTPUTTPLANET is OFFLINE</h1><p>The PUTTPUTTPLANET Platform is currently OFFLINE, most probably due to server maintenance.<br/>If you have an immediate need to change something please contact PUTTPUTTPLANET support <a href="mailto:support@PUTTPUTTPLANET.com">support@PUTTPUTTPLANET.com</a>.</p>' );
if (PUTTPUTTPLANET_ADMIN_LOCK) {
    header ("Location: /offline.html");
    exit(0);
}
