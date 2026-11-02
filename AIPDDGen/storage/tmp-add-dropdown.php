<?php
/**
 * Adds an "Our Tech" disclosure dropdown to the primary nav, keeps the #tech section,
 * and gives the section's three capability groups + lab callout anchor ids.
 * Every replacement is asserted to match exactly once before anything is written.
 */

$target = 'C:/xampp/htdocs/www/CarbonAnalytica/index.php';

function fail(string $m): void { fwrite(STDERR, "ABORT: $m\n"); exit(1); }

$html = file_get_contents($target);
if ($html === false) fail('cannot read target');
if (preg_match('/(?<!\r)\n/', $html)) fail('target has bare LF newlines');

function apply(string &$html, string $label, string $old, string $new): void {
    $old = str_replace("\n", "\r\n", $old);
    $new = str_replace("\n", "\r\n", $new);
    $n = substr_count($html, $old);
    if ($n !== 1) { fail("$label: expected exactly 1 match, found $n"); }
    $html = str_replace($old, $new, $html);
    echo "ok: $label\n";
}

/* ---------- 1. nav item -> dropdown ---------- */
$navOld = <<<'HTML'
      <a href="#tech">Our Tech</a>
HTML;

$navNew = <<<'HTML'
      <div class="nav-drop" id="techDrop">
        <button type="button" class="nav-drop-btn" id="techDropBtn" aria-expanded="false" aria-controls="techDropMenu">
          Our Tech<span class="nav-caret" aria-hidden="true">▾</span>
        </button>
        <div class="nav-drop-menu" id="techDropMenu">
          <a href="#tech">Our Tech overview</a>
          <a href="#tech-field">Field measurement kit</a>
          <a href="#tech-data">Data collection &amp; QA</a>
          <a href="#tech-report">Reporting &amp; dashboards</a>
          <a href="#tech-lab">Lab network &amp; protocols</a>
        </div>
      </div>
HTML;
apply($html, 'nav dropdown', $navOld, $navNew);

/* ---------- 2. anchor ids inside the tech section ---------- */
apply($html, 'id tech-field', <<<'HTML'
      <div class="service-card">
        <span class="service-num">01</span>
        <h3>Field measurement kit</h3>
HTML, <<<'HTML'
      <div class="service-card" id="tech-field">
        <span class="service-num">01</span>
        <h3>Field measurement kit</h3>
HTML);

apply($html, 'id tech-data', <<<'HTML'
      <div class="service-card">
        <span class="service-num">02</span>
        <h3>Data collection &amp; QA</h3>
HTML, <<<'HTML'
      <div class="service-card" id="tech-data">
        <span class="service-num">02</span>
        <h3>Data collection &amp; QA</h3>
HTML);

apply($html, 'id tech-report', <<<'HTML'
      <div class="service-card">
        <span class="service-num">03</span>
        <h3>Reporting &amp; dashboards</h3>
HTML, <<<'HTML'
      <div class="service-card" id="tech-report">
        <span class="service-num">03</span>
        <h3>Reporting &amp; dashboards</h3>
HTML);

apply($html, 'id tech-lab', <<<'HTML'
    <div class="callout">
      <strong>Protocols run with partner labs:</strong>
HTML, <<<'HTML'
    <div class="callout" id="tech-lab">
      <strong>Protocols run with partner labs:</strong>
HTML);

/* ---------- 3. dropdown CSS ---------- */
$css = <<<'CSS'
<style id="nav-dropdown">
/* OUR TECH DROPDOWN — disclosure nav: click/tap to open, Escape or click outside to close */
.navwrap{position:relative;}
.nav-drop{position:relative;flex:none;display:flex;align-items:center;}
.nav-drop-btn{
  display:inline-flex;align-items:center;gap:6px;
  background:none;border:0;padding:0;margin:0;cursor:pointer;
  font-family:'IBM Plex Sans',system-ui,sans-serif;font-size:14px;font-weight:400;
  color:#0B1711 !important;opacity:.78;transition:opacity .15s,color .15s;
}
.nav-drop-btn:hover,
.nav-drop-btn:focus-visible,
.nav-drop.is-open .nav-drop-btn{opacity:1;color:#087A43 !important;}
.nav-caret{font-size:9px;line-height:1;color:#087A43;transition:transform .18s ease;}
.nav-drop.is-open .nav-caret{transform:rotate(180deg);}
.nav-drop-menu{
  position:absolute;top:calc(100% + 14px);left:-14px;z-index:60;
  min-width:270px;display:none;
  background:#FFFFFF;border:1px solid #B8CDBE;border-radius:8px;
  box-shadow:0 18px 42px rgba(16,37,26,.15);padding:8px;
}
.nav-drop.is-open .nav-drop-menu{display:block;}
.nav-drop-menu::before{
  content:"";position:absolute;top:-7px;left:26px;width:12px;height:12px;
  background:#FFFFFF;border-left:1px solid #B8CDBE;border-top:1px solid #B8CDBE;
  transform:rotate(45deg);
}
.nav-drop-menu a{
  display:block;padding:10px 12px;border-radius:6px;
  font-family:'IBM Plex Sans',system-ui,sans-serif;font-size:13.5px;font-weight:500;
  color:#0B1711 !important;opacity:1 !important;text-decoration:none !important;white-space:nowrap;
}
.nav-drop-menu a:hover,
.nav-drop-menu a:focus-visible{background:#EEF7F1;color:#087A43 !important;}
/* Narrow screens: the panel spans the nav instead of hanging off one item */
@media(max-width:860px){
  .nav-drop{position:static;}
  .nav-drop-menu{
    position:absolute;top:calc(100% + 10px);left:20px;right:20px;min-width:0;
  }
  .nav-drop-menu::before{left:auto;right:56px;}
}
@media(prefers-reduced-motion:reduce){
  .nav-caret{transition:none;}
}
</style>
CSS;

apply($html, 'dropdown css', "</noscript>\n</head>", "</noscript>\n" . $css . "\n</head>");

/* ---------- 4. dropdown JS ---------- */
$js = <<<'JS'

  /* "Our Tech" dropdown: click/tap to open; click outside or press Escape to close. */
  (function(){
    var drop = document.getElementById('techDrop');
    if (!drop) return;
    var btn = document.getElementById('techDropBtn');
    var menu = document.getElementById('techDropMenu');

    function setOpen(open){
      if (open) { drop.classList.add('is-open'); } else { drop.classList.remove('is-open'); }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    btn.addEventListener('click', function(e){
      e.preventDefault();
      setOpen(!drop.classList.contains('is-open'));
    });

    document.addEventListener('click', function(e){
      if (!drop.contains(e.target)) setOpen(false);
    });

    document.addEventListener('keydown', function(e){
      if ((e.key === 'Escape' || e.key === 'Esc') && drop.classList.contains('is-open')) {
        setOpen(false);
        btn.focus();
      }
    });

    menu.addEventListener('click', function(e){
      if (e.target.closest('a')) setOpen(false);
    });
  })();
JS;

apply($html, 'dropdown js', "  })();\n\n</script>", "  })();\n" . $js . "\n</script>");

/* ---------- write ---------- */
if (preg_match('/(?<!\r)\n/', $html)) fail('bug: produced bare LF newlines');
if (file_put_contents($target, $html) === false) fail("cannot write $target (sandbox/permission?)");
printf("WRITTEN: %s\nlines: %d, bytes: %d\n", $target, substr_count($html, "\r\n") + 1, strlen($html));

/* ================= verify ================= */
$saved = file_get_contents($target);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $saved, LIBXML_NOWARNING | LIBXML_NOERROR);
$errs = libxml_get_errors();
libxml_clear_errors();
$allowed = '/Tag (header|nav|section|main|footer|svg|circle|text|path|rect) invalid/';
$real = [];
foreach ($errs as $e) {
    if (!preg_match($allowed, trim($e->message))) { $real[] = sprintf('line %d: %s', $e->line, trim($e->message)); }
}
echo "real parse issues: " . count($real) . "\n";
foreach ($real as $r) { echo "  $r\n"; }

$xp = new DOMXPath($dom);
$btn = $xp->query('//*[@id="techDropBtn"]')->item(0);
echo "dropdown button: " . ($btn ? 'yes, aria-expanded=' . ($btn->getAttribute('aria-expanded') ?: '(unset)') . ', controls=' . $btn->getAttribute('aria-controls') : 'NO') . "\n";
echo "dropdown items: " . $xp->query('//*[@id="techDropMenu"]/a')->length . "\n";

$ids = [];
foreach ($xp->query('//*[@id]') as $n) { $ids[] = $n->getAttribute('id'); }
foreach (['tech', 'tech-field', 'tech-data', 'tech-report', 'tech-lab'] as $need) {
    echo "  id $need: " . (in_array($need, $ids, true) ? 'present' : 'MISSING') . "\n";
}
$missing = [];
foreach ($xp->query('//a[starts-with(@href,"#")]') as $a) {
    $frag = substr($a->getAttribute('href'), 1);
    if ($frag !== '' && !in_array($frag, $ids, true)) { $missing[] = $frag; }
}
echo "broken internal anchors: " . count($missing) . ($missing ? ' (' . implode(', ', $missing) . ')' : '') . "\n";
echo "duplicate ids: " . (count($ids) - count(array_unique($ids))) . "\n";
echo "style blocks: " . $xp->query('//style')->length . ", script blocks: " . $xp->query('//script')->length . "\n";
