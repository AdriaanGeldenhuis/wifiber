<?php
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/tickets.php';

$user = require_role('client', '/account/login.php');

$msg_id = (int)($_GET['msg'] ?? 0);
$m = $msg_id > 0 ? ticket_message_find($msg_id) : null;

// Client can only download attachments on tickets they own.
if (!$m || (int)$m['ticket_user_id'] !== (int)$user['id'] || empty($m['attachment_path'])) {
    http_response_code(404);
    die('Attachment not found.');
}

ticket_attachment_stream($m);
