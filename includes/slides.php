<?php
/**
 * Slider loader.
 *
 * Slides are stored in /data/slides.json and are editable from
 * /admin/slides.php. This file just loads them.
 *
 * Returns an array of slides, each shaped like:
 *   image, eyebrow, heading, heading_accent, subtext,
 *   cta_label, cta_link, position
 */

$slides_file = __DIR__ . '/../data/slides.json';
$slides = [];
if (is_file($slides_file)) {
    $d = json_decode((string)@file_get_contents($slides_file), true);
    if (is_array($d) && !empty($d['slides']) && is_array($d['slides'])) {
        $slides = $d['slides'];
    }
}

return $slides;
