<?php
// must NOT match 0013 - wp_mail with a constant recipient
wp_mail('admin@example.com', $subject, $body);
mail('admin@example.com', $subject, $body);
