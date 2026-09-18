<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0004 backdoor - preg_replace /e modifier (infected)
preg_replace('/(.*)/e', 'phpinfo()', 'x');
