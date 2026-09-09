<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('POST');
start_app_session();
csrf_verify_request();
logout_user();
json_ok('Logged out');
