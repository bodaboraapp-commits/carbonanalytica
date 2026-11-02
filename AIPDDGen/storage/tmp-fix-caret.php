<?php
/** Swap the tiny glyph caret for a crisp CSS triangle. */
$target = 'C:/xampp/htdocs/www/CarbonAnalytica/index.php';
function fail(string $m): void { fwrite(STDERR, "ABORT: $m\n"); exit(1); }

$html = file_get_contents($target);
if ($html === false) fail('cannot read target');

$pairs = [
    [
        'label' => 'caret markup',
        'old'   => 'Our Tech<span class="nav-caret" aria-hidden="true">▾</span>',
        'new'   => 'Our Tech<span class="nav-caret" aria-hidden="true"></span>',
    ],
    [
        'label' => 'caret css',
        'old'   => ".nav-caret{font-size:9px;line-height:1;color:#087A43;transition:transform .18s ease;}",
        'new'   => ".nav-caret{\n  display:inline-block;width:0;height:0;\n  border-left:4px solid transparent;border-right:4px solid transparent;\n  border-top:5px solid #087A43;transition:transform .18s ease;\n}",
    ],
];

foreach ($pairs as $p) {
    $old = str_replace("\n", "\r\n", $p['old']);
    $new = str_replace("\n", "\r\n", $p['new']);
    $n = substr_count($html, $old);
    if ($n !== 1) fail("{$p['label']}: expected 1 match, found $n");
    $html = str_replace($old, $new, $html);
    echo "ok: {$p['label']}\n";
}

if (preg_match('/(?<!\r)\n/', $html)) fail('bare LF produced');
if (file_put_contents($target, $html) === false) fail('cannot write (sandbox/permission?)');
printf("WRITTEN: %s (%d bytes)\n", $target, strlen($html));
