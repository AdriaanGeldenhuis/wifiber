<?php
/**
 * Per-request analytics shim. Loaded by includes/footer.php as a
 * regular <script src> so the strict Content-Security-Policy
 * (script-src 'self') stays intact — the third-party loader is
 * injected from this same-origin script.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$an       = $site['analytics'] ?? [];
$provider = (string)($an['provider'] ?? 'none');

if ($provider === 'plausible') {
    $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($an['plausible_domain'] ?? ''));
    if ($domain !== '') {
        echo "(function(){\n";
        echo "  var s = document.createElement('script');\n";
        echo "  s.defer = true;\n";
        echo "  s.dataset.domain = " . json_encode($domain) . ";\n";
        echo "  s.src = 'https://plausible.io/js/script.js';\n";
        echo "  document.head.appendChild(s);\n";
        echo "})();\n";
    }
} elseif ($provider === 'google') {
    $id = preg_replace('/[^A-Za-z0-9-]/', '', (string)($an['google_id'] ?? ''));
    if ($id !== '') {
        echo "(function(){\n";
        echo "  var g = document.createElement('script');\n";
        echo "  g.async = true;\n";
        echo "  g.src = 'https://www.googletagmanager.com/gtag/js?id=" . $id . "';\n";
        echo "  document.head.appendChild(g);\n";
        echo "  window.dataLayer = window.dataLayer || [];\n";
        echo "  function gtag(){ dataLayer.push(arguments); }\n";
        echo "  gtag('js', new Date());\n";
        echo "  gtag('config', " . json_encode($id) . ");\n";
        echo "})();\n";
    }
}
