<?php

/**
 * One-off favicon.ico generator (run: php scripts/make-favicon.php).
 * Rasterizes a simplified EHC flame-star (5 petals + medallion, brand blue->teal) at 16/32/48 px
 * with GD and packs the PNGs into a single .ico (PNG-in-ICO — supported by all modern browsers).
 */
$sizes = [16, 32, 48];
$pngs = [];

foreach ($sizes as $size) {
    $scale = 8;                       // supersample for smooth edges, then downscale
    $big = $size * $scale;
    $im = imagecreatetruecolor($big, $big);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));

    $cx = $big / 2;
    $cy = $big / 2;
    $unit = $big / 100;               // the SVG viewBox is 100x100

    // 5 petals: approximate each "M50,50 C40,40 36,22 50,7 C64,22 60,40 50,50" with a polygon
    // sampled from the two cubic Béziers, rotated by 72° steps around the center.
    $bezier = function (array $p0, array $p1, array $p2, array $p3, int $n = 24): array {
        $pts = [];
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n;
            $mt = 1 - $t;
            $pts[] = [
                $mt ** 3 * $p0[0] + 3 * $mt ** 2 * $t * $p1[0] + 3 * $mt * $t ** 2 * $p2[0] + $t ** 3 * $p3[0],
                $mt ** 3 * $p0[1] + 3 * $mt ** 2 * $t * $p1[1] + 3 * $mt * $t ** 2 * $p2[1] + $t ** 3 * $p3[1],
            ];
        }

        return $pts;
    };
    $petal = array_merge(
        $bezier([50, 50], [40, 40], [36, 22], [50, 7]),
        $bezier([50, 7], [64, 22], [60, 40], [50, 50])
    );

    $petalColor = imagecolorallocate($im, 0x1F, 0x86, 0xBF);   // mid of the brand gradient
    for ($k = 0; $k < 5; $k++) {
        $a = deg2rad(72 * $k);
        $poly = [];
        foreach ($petal as [$x, $y]) {
            // rotate around (50,50), then scale to pixels
            $dx = $x - 50;
            $dy = $y - 50;
            $poly[] = $cx + ($dx * cos($a) - $dy * sin($a)) * $unit;
            $poly[] = $cy + ($dx * sin($a) + $dy * cos($a)) * $unit;
        }
        imagefilledpolygon($im, array_map('intval', $poly), $petalColor);
    }

    // central medallion: light disc + ring + dot
    $d = (int) round(21 * $unit);
    imagefilledellipse($im, (int) $cx, (int) $cy, $d, $d, imagecolorallocate($im, 0x2F, 0x97, 0xC4));
    $d2 = (int) round(18 * $unit);
    imagefilledellipse($im, (int) $cx, (int) $cy, $d2, $d2, imagecolorallocate($im, 0xEA, 0xF4, 0xF4));
    $d3 = (int) round(7 * $unit);
    imagefilledellipse($im, (int) $cx, (int) $cy, $d3, $d3, imagecolorallocate($im, 0x7F, 0xA8, 0xBD));

    // downscale to the target size
    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $big, $big);

    ob_start();
    imagepng($out);
    $pngs[$size] = ob_get_clean();
    imagedestroy($im);
    imagedestroy($out);
}

// pack as ICO: ICONDIR + ICONDIRENTRY per image + raw PNG payloads
$count = count($pngs);
$ico = pack('vvv', 0, 1, $count);
$offset = 6 + 16 * $count;
$body = '';
foreach ($pngs as $size => $png) {
    $ico .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
    $body .= $png;
    $offset += strlen($png);
}
file_put_contents(__DIR__.'/../public/favicon.ico', $ico.$body);
echo 'favicon.ico written ('.strlen($ico.$body).' bytes, sizes: '.implode('/', array_keys($pngs)).")\n";
