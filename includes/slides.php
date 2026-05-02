<?php
/**
 * --------------------------------------------------------------------
 *  HOW TO EDIT THE HOMEPAGE SLIDER
 * --------------------------------------------------------------------
 *  Each slide is an entry in the array below.
 *
 *  - image        Filename inside /assets/images/slider/
 *                 (just the filename, e.g. "slider-1.webp")
 *  - eyebrow      Small uppercase label above the heading (optional)
 *  - heading      The main headline (HTML allowed for line breaks etc.)
 *  - heading_accent
 *                 Second part of the heading shown in the cyan accent
 *                 colour. Optional.
 *  - subtext      Paragraph under the heading
 *  - cta_label    Button text
 *  - cta_link     Where the button goes (e.g. "/pricing", "/coverage",
 *                 "/#contact", or any full URL)
 *  - position     "left" (default) or "center" — where the text sits
 *
 *  To add a new slide: copy a block, paste it in, change the values.
 *  To remove a slide: delete its block.
 *  To re-order: cut and paste the blocks into the order you want.
 *
 *  Image tips: 1920x1080 (or larger) JPG/WEBP works best. Save the file
 *  into /assets/images/slider/ and reference its filename here.
 * --------------------------------------------------------------------
 */

return [

    [
        'image'          => 'slider-1.webp',
        'eyebrow'        => 'Fiber-grade speed',
        'heading'        => 'Speed That Connects.',
        'heading_accent' => 'Reliability That Lasts.',
        'subtext'        => 'Wireless internet for home and business across the Vaal Triangle &mdash; built on top-tier networking equipment with multiple backup systems.',
        'cta_label'      => 'View Coverage Map',
        'cta_link'       => '/coverage',
        'position'       => 'left',
    ],

    [
        'image'          => 'slider-2.webp',
        'eyebrow'        => 'Always connected',
        'heading'        => 'Every device.',
        'heading_accent' => 'Every corner of your home.',
        'subtext'        => 'Stream, work, game, video-call &mdash; all at the same time. Uncapped, unshaped, and unshared on our premium tiers.',
        'cta_label'      => 'See Pricing',
        'cta_link'       => '/pricing',
        'position'       => 'left',
    ],

    [
        'image'          => 'slider-3.webp',
        'eyebrow'        => 'Business-ready',
        'heading'        => 'Built for business.',
        'heading_accent' => 'VoIP, cloud, the lot.',
        'subtext'        => 'Low-latency 1:1 contention links, redundant uplinks and 24/7 support. The internet your business needs to keep running.',
        'cta_label'      => 'Talk to us',
        'cta_link'       => '/#contact',
        'position'       => 'left',
    ],

];
