<?php
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/tickets.php';

require_admin_ip();
$user = require_role('admin', '/admin/login.php');

$msg_id = (int)($_GET['msg'] ?? 0);
$m = $msg_id > 0 ? ticket_message_find($msg_id) : null;

if (!$m || empty($m['attachment_path'])) {
    http_response_code(404);
    die('Attachment not found.');
}

ticket_attachment_stream($m);
