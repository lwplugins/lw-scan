<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0002 obfuscation - base64_decode piped into eval (infected)
// payload decodes to: phpinfo();  (harmless)
eval(base64_decode('cGhwaW5mbygpOw=='));
