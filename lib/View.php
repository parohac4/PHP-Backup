<?php
/**
 * Vykreslování stránek – hlavička, patička a drobní pomocníci.
 * Veškerý výstup proměnných jde přes View::e() (ochrana proti XSS).
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class View
{
    /** Escapování pro HTML. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)max(0, $bytes);
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$value : number_format($value, 1, ',', ' ')) . ' ' . $units[$i];
    }

    public static function dateTime(int $timestamp): string
    {
        return date('j. n. Y H:i', $timestamp);
    }

    /** Odkaz na vlastní skript (bez závislosti na doméně). */
    public static function self(): string
    {
        return self::e(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
    }

    public static function header(string $title): void
    {
        $nonce = self::e(Security::nonce());
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html>\n<html lang=\"cs\">\n<head>\n";
        echo "<meta charset=\"utf-8\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        echo "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
        echo '<title>' . self::e($title) . " – PHP Backup Tool</title>\n";
        echo "<style nonce=\"$nonce\">\n" . self::css() . "</style>\n";
        echo "</head>\n<body>\n<div class=\"wrap\">\n";
        echo '<header class="head"><h1>Záloha webu</h1>'
            . '<span class="ver">v' . self::e(PB_VERSION) . '</span></header>' . "\n";
    }

    public static function footer(): void
    {
        echo "</div>\n</body>\n</html>\n";
    }

    /** Hláška pro uživatele. */
    public static function flash(string $type, string $message): void
    {
        echo '<p class="msg ' . self::e($type) . '">' . self::e($message) . "</p>\n";
    }

    private static function css(): string
    {
        return <<<CSS
:root{--bg:#f4f6f9;--card:#fff;--ink:#1c2530;--muted:#67707c;--line:#dde3ea;
--brand:#1f6feb;--ok:#1a7f4b;--warn:#9a6200;--err:#c02b2b;--radius:10px}
@media (prefers-color-scheme:dark){:root{--bg:#161a20;--card:#1e242c;--ink:#e8edf3;
--muted:#9aa5b1;--line:#2e3742;--brand:#4c8dff;--ok:#3fb37a;--warn:#d79b28;--err:#f36b6b}}
*{box-sizing:border-box}
body{margin:0;padding:24px 16px;background:var(--bg);color:var(--ink);
font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:860px;margin:0 auto}
.head{display:flex;align-items:baseline;gap:10px;margin-bottom:18px}
.head h1{font-size:22px;margin:0}
.ver{color:var(--muted);font-size:13px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
padding:20px;margin-bottom:16px}
.card h2{font-size:17px;margin:0 0 12px}
.card h3{font-size:15px;margin:18px 0 8px}
p{margin:0 0 12px}
.hint{color:var(--muted);font-size:14px}
label{display:block;font-weight:600;font-size:14px;margin:14px 0 5px}
label.inline{display:flex;align-items:center;gap:9px;font-weight:400;margin:7px 0}
input[type=text],input[type=password],input[type=number],select,textarea{
width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;
background:var(--bg);color:var(--ink);font:inherit;font-size:15px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--brand);outline-offset:1px}
textarea{min-height:110px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
select[multiple]{min-height:130px}
button{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:11px 18px;
font:inherit;font-weight:600;cursor:pointer}
button:hover{filter:brightness(1.08)}
button:disabled{opacity:.55;cursor:not-allowed}
button.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
button.danger{background:transparent;color:var(--err);border:1px solid var(--err);
padding:6px 12px;font-size:14px;font-weight:500}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.msg{padding:11px 14px;border-radius:8px;font-size:15px;border:1px solid}
.msg.ok{background:rgba(26,127,75,.1);border-color:var(--ok);color:var(--ok)}
.msg.err{background:rgba(192,43,43,.1);border-color:var(--err);color:var(--err)}
.msg.warn{background:rgba(154,98,0,.1);border-color:var(--warn);color:var(--warn)}
.tablewrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:15px}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
td.num{text-align:right;white-space:nowrap}
td.date{white-space:nowrap;color:var(--muted);font-size:14px}
/* Řádek s poznámkou patří k řádku nad ním – nedělit je linkou. */
tr.hasnote>td{border-bottom:0;padding-bottom:2px}
tr.noterow>td{padding-top:0}
tr.noterow .msg{margin:8px 0 0}
tr.noterow .msg:first-child{margin-top:4px}
td form{margin:0}
/* Dlouhé názvy archivů nesmí roztáhnout tabulku do šířky. */
table a{overflow-wrap:anywhere}
a{color:var(--brand)}
.bar{height:9px;background:var(--line);border-radius:99px;overflow:hidden;margin:12px 0 8px}
.bar>i{display:block;height:100%;width:0;background:var(--brand);transition:width .3s}
.logbox{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:11px;
max-height:210px;overflow:auto;font:13px/1.6 ui-monospace,Menlo,Consolas,monospace;
white-space:pre-wrap;word-break:break-word}
.mode{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:6px 0}
.mode label{border:1px solid var(--line);border-radius:8px;padding:12px;margin:0;
display:flex;gap:9px;align-items:center;font-weight:500;cursor:pointer}
.hidden{display:none}
details{margin-top:8px}
summary{cursor:pointer;font-weight:600;font-size:15px;padding:6px 0}
footer.foot{color:var(--muted);font-size:13px;text-align:center;margin:22px 0 6px}
code{background:var(--bg);border:1px solid var(--line);border-radius:5px;padding:1px 5px;
font-size:13px;overflow-wrap:anywhere;word-break:break-word}
/* Dlouhá absolutní cesta na vlastním řádku, ať nerozbíjí odstavec. */
code.path{display:block;margin-top:6px;padding:6px 8px;line-height:1.45}
.msg code{background:rgba(127,127,127,.14);border-color:transparent}
CSS;
    }
}
