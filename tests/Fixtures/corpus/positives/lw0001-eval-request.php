<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0001 backdoor - eval() on request input (suspicious)
eval($_POST['c']);
