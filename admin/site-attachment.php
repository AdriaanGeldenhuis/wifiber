<?php
/**
 * Authenticated streaming endpoint for site attachments. Files live in
 * data/site-attachments/ which is web-blocked by .htaccess; this is the
 * only way to fetch one. Mirrors the ticket-attachment endpoint —
 * admin-role + IP-allowlist guard, then hand off to the helper.
 */
require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../auth/sites.php';

require_admin_ip();
require_role('admin', '/admin/login.php');

$id = (int)($_GET['id'] ?? 0);
$a  = $id > 0 ? site_attachment_find($id) : null;

if (!$a) {
    http_response_code(404);
    die('Attachment not found.');
}

site_attachment_stream($a);
