<?php
// Production database (xneelo). Pulled in by auth/helpers.php → pdo().
return [
    'host' => 'dedi321.cpt1.host-h.net',
    'port' => 3306,
    'db'   => 'wifibfjedj_wp0e0b',
    'user' => 'wifibfjedj_63',
    'pass' => 'AdrianusGeldenhuis12',

    // FCM HTTP v1 push channel — read by auth/channels/push.php via
    // notify_load_config(). The service-account JSON sits at
    // data/fcm-service-account.json (gitignored, uploaded to the
    // server out-of-band so the private key stays out of git).
    'notify_push' => [
        'enabled'         => true,
        'project_id'      => 'wifiber-portal',
        'service_account' => __DIR__ . '/fcm-service-account.json',
    ],
];
