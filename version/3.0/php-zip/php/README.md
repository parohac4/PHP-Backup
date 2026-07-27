# PHP ZIP Backup 

Tento mini‑projekt umožňuje **bezpečně zazipovat web** na běžném hostingu (bez SSH a bez Composeru) a stáhnout ZIP:
- buď přes **webové UI** (`runner.html`),
- nebo přes **jedinou URL** (`backup.php?token=…`) – běží **po dávkách**, takže nespadne na `max_execution_time`,
- případně pohodlně jedním příkazem přes **bash/cURL**.
Součástí je i **generátor tokenu**.



## 1) Soubory a struktura

```
public/
├─ backup.php            # jediná URL – inkrementální záloha (303 redirect mezi dávkami, finálně pošle ZIP)
├─ runner.html           # UI: tlačítko Spustit → postupné krokování → stáhnout ZIP
├─ start-backup.php      # API (runner): založí job a seznam souborů
├─ step-backup.php       # API (runner): přidá dávku souborů do ZIPu
├─ download-backup.php   # API (runner): pošle ZIP a uklidí job
├─ common.php            # společné pomocné funkce (auth, cesty, atd.)
├─ config.php            # KONFIGURACE – ZDE ZADEJTE TOKEN a cesty
├─ generate-token.php    # generátor náhodného tokenu (64 hex znaků)
└─ data/                 # pracovní adresář (musí být zapisovatelný a chráněný .htaccess)
```

Všechny soubory najdete ve složece PHP.

---

## 2) Požadavky

- **PHP 7.4+** (doporučeno 8.x) a rozšíření **ZipArchive**.
- `public/data/` je **zapisovatelný** (typicky 0755/0775).
- Web přes **HTTPS** (doporučeno).

---

## 3) Instalace

1. Nahrajte obsah složky `public/` na svůj hosting (do veřejného webrootu nebo podadresáře).
2. Ujistěte se, že `public/data/` existuje a je zapisovatelná.
3. (Důrazně) ochraňte `public/data/` pomocí `.htaccess`:
   ```apache
   <IfModule mod_authz_core.c>
     Require all denied
   </IfModule>
   <IfModule !mod_authz_core.c>
     Deny from all
   </IfModule>
   ```
4. Upravte **`public/config.php`** (viz níže) – zejména **`backup_root`** a **`token`**.
5. Ověřte funkčnost na `runner.html` nebo `backup.php`.

---

## 4) Konfigurace (kde zadat token)

Otevřete `public/config.php` a nastavte:

```php
<?php
return [
  // KOŘEN adresáře, který chcete zálohovat (absolutní cesta na serveru!)
  'backup_root' => '/ABSOLUTNI/CESTA/K/WEBU/', // u Vedos webhostingu je to například data/web/virtuals/XXX/virtual/www/ kdy XXX je ID vašeho webhostingu z administrace Vedos

  // pracovní adresář pro ZIP a dočasná data
  'backup_dir'  => __DIR__ . '/data',

  // === BEZPEČNOST ===
  // TAJNÝ TOKEN – dlouhý náhodný řetězec (např. 64 hex znaků)
  'token' => 'SEM_VLOŽTE_VÁŠ_TOKEN',

  // volitelně
  'require_https' => true,
  'min_seconds_between_runs' => 60,
  'allow_ips' => [],

  // vynechané cesty (nebudou v ZIP)
  'exclude_paths' => [
    '/.git/', '/node_modules/', '/vendor/bin/', '/cache/', '/logs/', '/tmp/',
    '/.idea/', '/.vscode/', '/.DS_Store', '/Thumbs.db',
    '/php-zip/public/data/',
  ],

  // dávkování (počet souborů na 1 request pro inkrementální běh)
  'batch_size' => 300,
];
```
**Kde zadat token:** do klíče `'token'`. Stejný token pak použijete v URL/POSTu při spouštění.

> Máte‑li i „jednosouborový“ `backup.php` s tokenem přímo v souboru, ujistěte se, že čte token z GET/POST a **porovnává jej** proti hodnotě z `config.php` (nebo lokální konstantě). Nedávejte token přímo do žádného HTML jako `value="..."`.

---

## 5) Použití – záloha do ZIP

### a) Webové UI (runner)
1. Otevřete `https://VAŠE-DOMÉNA/…/runner.html`
2. Do pole **Token** vložte hodnotu z `config.php` → `token`.
3. Klikněte **Spustit zálohu**. Průběh uvidíte v progress baru a logu.
4. Po dokončení klikněte na **Stáhnout ZIP** (UI může stáhnout i automaticky).

### b) Jediná URL (inkrementálně po dávkách) – `backup.php`
`backup.php` je navržen tak, aby každý požadavek zpracoval **malou dávku souborů** a vrátil **303 See Other** na stejnou URL, dokud není hotovo. Poslední odpověď je `200` a obsahuje ZIP.

- **Nejjednodušší bash/cURL (1 řádek)**:
  ```bash
  URL="https://VAŠE-DOMÉNA/…/backup.php"
  TOKEN="VÁŠ_64_HEX_TOKEN"
  curl -L --fail --show-error --compressed -o "backup-$(date +%F-%H%M).zip" "${URL}?token=${TOKEN}"
  ```
  - `-L` je nutné (sleduje 303 mezi dávkami).

- **Malý skript `backup.sh` (parametry z CLI)**:

(skript je umístěn ve složce **bash**)

  ```bash
  #!/usr/bin/env bash
  # Použití: ./backup.sh -u URL -t TOKEN [-o backup.zip]
  set -euo pipefail
  URL=""; TOKEN=""; OUT="backup-$(date +%F-%H%M).zip"
  while getopts ":u:t:o:h" opt; do
    case "$opt" in
      u) URL="$OPTARG";;
      t) TOKEN="$OPTARG";;
      o) OUT="$OPTARG";;
      h|*) echo "Použití: $0 -u URL -t TOKEN [-o SOUBOR]"; exit 1;;
    esac
  done
  [[ -z "$URL" || -z "$TOKEN" ]] && { echo "Chybí -u nebo -t"; exit 1; }
  curl -L --fail --show-error --compressed -o "$OUT" "${URL}?token=${TOKEN}"
  echo "Hotovo: $OUT"
  ```

---

## 6) Generátor tokenu

Soubor `public/generate-token.php`:

```php
<?php
header('Content-Type: text/plain; charset=UTF-8');
$token = bin2hex(random_bytes(32)); // 64 hex znaků
echo "Vygenerovaný token:\n\n$token\n";
```

**Použití:**
- Spusťte v prohlížeči nebo na CLI (`php generate-token.php`).
- Zkopírujte výstup do `config.php` → `'token' => '…'`.

> Doporučení: token **neukládejte do HTML** formulářů (nevyplňujte `value="..."`), posílejte ho **POSTem** nebo jako query pouze v cURL. Token občas **rotujte** (změňte).

---

## 7) Bezpečnost & .htaccess (doporučení)

- Mějte zapnuté **HTTPS** a v `config.php` `require_https => true`.
- Chraňte `public/data/`: viz `.htaccess` výše.
- Omezte přístup k `backup.php` a `runner.html` (např. jen z konkrétních IP) pomocí `public/.htaccess`:
  ```apache
  <FilesMatch "^(backup\.php|runner\.html)$">
    Require ip 1.2.3.4
    # Require ip 2001:db8::/32  # příklad IPv6/prefix
  </FilesMatch>
  ```
- Token neposílejte do URL v běžném prohlížeči (kvůli logům a historii). Pro UI používejte **POST**.

---

## 8) Řešení potíží

- **401/403**: špatný token, IP omezení, nebo je vyžadováno HTTPS.
- **404**: špatná cesta/URL (některé hostingy mají prefix `/domains/domena.tld/`).
- **„Cannot open/reopen zip“**: zkontrolujte práva zápisu do `public/data/`; u sdíleného hostingu snižte `batch_size` (např. 200).
- **UI nevyvolá stažení**: povolte GET token pro `download-backup.php` nebo stáhněte přes POST z cURL (viz výše).

