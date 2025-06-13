<?php
// config.php

// Determine SIP protocol based on the site's protocol
define('SS_PROTOCOL', is_ssl() ? 'https://' : 'http://');
define('SS_HOST', 'www.simpleso.io');
