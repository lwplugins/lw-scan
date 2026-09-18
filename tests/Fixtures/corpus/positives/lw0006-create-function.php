<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0006 backdoor - create_function body from request input (suspicious)
$f = create_function('$x', $_REQUEST['code']);
