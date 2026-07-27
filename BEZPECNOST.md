# Bezpečnost – jak je to uděláno

Zálohovací nástroj je zajímavý cíl: má přístup ke všem souborům webu i k databázi.
Tenhle dokument popisuje, co konkrétně nástroj dělá pro to, aby se toho nikdo
nezmocnil – a také **kde jsou hranice**.

---

## 1. Přístup k nástroji

### Heslo místo tokenu v souboru
Starší verze měly token natvrdo v `config.php` – tedy i v gitu a v záloze webu.
Verze 4.0 používá heslo, ze kterého se ukládá jen `password_hash()`
(bcrypt/Argon2 podle PHP). Z uloženého otisku heslo získat nelze.

### Okno mezi nahráním a nastavením hesla
Než si zvolíte heslo, je instalační obrazovka otevřená komukoli, kdo zná adresu –
stejně jako u instalace WordPressu. Jakmile heslo jednou nastavíte, konfigurace
se vytvoří atomicky (`fopen(..., 'x')`) a instalaci už nikdo znovu nespustí,
ani při souběžném požadavku. **Proto nastavení dokončete hned po nahrání na FTP.**
Kdo instalaci provedl a odkud, je vidět v záznamu činnosti.

### Session
- ukládá se do `data/sessions/` s právy `0700`, **ne** do sdíleného `/tmp`
  (na sdíleném hostingu do něj vidí i ostatní zákazníci serveru),
- cookie: `HttpOnly`, `SameSite=Strict`, `Secure` při HTTPS,
- `session.use_strict_mode=1` – útočník nemůže session ID předem podstrčit,
- po přihlášení se ID vždy přegeneruje (ochrana proti fixaci),
- platnost: 30 minut nečinnosti, nejvýše 12 hodin celkem,
- session je vázaná na otisk prohlížeče (User-Agent).

### Hádání hesla
Po 5 neúspěšných pokusech se IP zablokuje na 1 minutu, s každým dalším pokusem
se doba zdvojnásobuje až na hodinu. Každý pokus navíc trvá 150–350 ms.
Chybová hláška je vždy stejná.

### Volitelný IP whitelist
V nastavení lze omezit přístup na konkrétní adresy nebo rozsahy (IPv4 i IPv6,
včetně CIDR). Vaše aktuální adresa se do seznamu doplní automaticky, aby se
nedalo zamknout ven. Hlavičkám `X-Forwarded-For` se **nevěří**, dokud to
výslovně nezapnete – jinak by whitelist šlo obejít podvrženou hlavičkou.

---

## 2. Ochrana dat na disku

### Nic citlivého v „obyčejném“ souboru
Konfigurace, stav úlohy, log i seznam souborů jsou uloženy jako `.php` soubory,
které začínají řádkem:

```php
<?php http_response_code(404); exit; ?>
```

Když si je někdo vyžádá z webu, PHP je **vykoná** a vrátí 404 – žádný obsah
neunikne. Funguje to i na hostinzích, které `.htaccess` ignorují (nginx).

### Archivy
Název archivu obsahuje 12 náhodných hexadecimálních znaků
(`backup_20260727_101500_9f3ac1d20b74.zip`) – uhodnout ho nelze. Navíc leží
v adresáři chráněném `.htaccess` a stahují se jen přes přihlášený požadavek.

Nástroj sám kontroluje, zda je `data/` opravdu nedostupný: prohlížeč si po
přihlášení zkusí stáhnout testovací soubor a když uspěje, na obrazovce se
objeví výrazné varování.

### Šifrovaná tajemství
Pokud si necháte uložit přístupy k databázi nebo heslo pro šifrování archivu,
uloží se takto:

1. při instalaci vznikne náhodný **hlavní klíč** (32 B),
2. ten se uloží zašifrovaný klíčem odvozeným z vašeho hesla
   (PBKDF2‑SHA512, 210 000 iterací),
3. po přihlášení se hlavní klíč rozbalí a drží se jen v session,
4. tajemství jsou šifrována **AES‑256‑GCM** (šifra s kontrolou integrity).

Kdo získá soubory nástroje bez znalosti hesla, získá jen náhodná data.
Změna hesla klíč jen přebalí, uložená tajemství zůstanou čitelná.

Šifrování archivu navíc **selhává nahlas**: když je heslo archivu nastavené,
ale klíč nejde odemknout nebo server AES v ZIPu neumí, záloha se zastaví
s chybou. Tichý průchod bez šifrování by byl horší než neúspěch – správce by
dostal čitelný archiv a myslel si, že je chráněný.

### Práva souborů
`umask(0077)` – vše, co nástroj vytvoří, je čitelné jen pro účet webu.
Konfigurace a archivy se navíc explicitně nastavují na `0600`.

---

## 3. Vlastní běh zálohy

### Žádný shell
Export databáze běží čistě přes `mysqli`; nástroj **nikde nevolá** `exec`,
`shell_exec`, `system`, `passthru` ani `proc_open`. Tím zcela odpadá třída
zranitelností typu „injektáž do příkazové řádky“ – a nástroj funguje i tam,
kde hosting spouštění procesů zakazuje.

### Práce s cestami
- kořen zálohy se normalizuje přes `realpath()`,
- před přidáním do archivu se u **každého** souboru znovu ověří, že leží uvnitř
  kořene; porovnává se s oddělovačem na konci, takže `/data/web` neprojde jako
  rodič `/data/webhosting`,
- **symbolické odkazy se přeskakují** – jinak by odkaz uvnitř webu mohl do
  zálohy vtáhnout soubory odkudkoli ze serveru (a mohl by zacyklit procházení),
- datový adresář nástroje se do zálohy nikdy nepřidává (zálohy by rekurzivně
  bobtnaly a v archivu by skončila konfigurace).

### Názvy pro stahování a mazání
Název archivu musí přesně odpovídat vzoru
`backup_RRRRMMDD_HHMMSS_<12 hex>.zip`. Cokoli jiného (včetně `../`) se odmítne
dřív, než se sáhne na disk – a i potom se ještě ověřuje `realpath`.

### SQL
Názvy tabulek a sloupců se uvozují zpětnými apostrofy se zdvojením (`` ` `` →
`` `` ``), hodnoty jdou přes `real_escape_string`, binární sloupce se zapisují
hexadecimálně. Databázi vybírá `select_db()` – ne skládaný dotaz. Vybraná
databáze se navíc porovnává se seznamem, který vrátil server.

### Souběh
Krok úlohy drží exkluzivní zámek (`flock`). Dvě okna prohlížeče si tedy
nemohou rozbít jeden archiv.

---

## 4. Webové rozhraní

- **CSRF**: token v session, kontrola `hash_equals()` u každé měnící operace
  (včetně přihlášení a odhlášení), cookie `SameSite=Strict`.
- **XSS**: všechny proměnné se vypisují přes `View::e()`
  (`htmlspecialchars` s `ENT_QUOTES`), stránka běží pod
  `Content-Security-Policy: default-src 'none'` s nonce pro vlastní styl a skript.
  Žádné inline `onclick`, žádné externí zdroje.
- **Clickjacking**: `X-Frame-Options: DENY` + `frame-ancestors 'none'`.
- **Únik adresy**: `Referrer-Policy: no-referrer`.
- **Chybové hlášky**: `display_errors` je vypnuté, uživatel vidí obecnou hlášku,
  detail (včetně kódu chyby databáze) jde jen do logu.
- **Nepřihlášenému se nic o serveru neřekne**: když se rozbijí relace, stránka
  ukáže jen obecnou hlášku. Rozbor příčiny (typ úložiště relací, práva, volné
  místo) míří výhradně do `data/audit.php`, ke kterému se správce dostane přes
  FTP. Jinak by stačil jediný POST bez cookie a útočník by měl přehled
  o prostředí serveru.
- **PRG**: formuláře po odeslání přesměrovávají, takže se nedají znovu odeslat
  obnovením stránky.

---

## 5. Co nástroj *nedělá* (a je dobré o tom vědět)

- **Nechrání před správcem serveru ani před jinou dírou na stejném webu.**
  Kdo umí spustit PHP pod stejným účtem, dostane se k souborům i k session.
  Proto: aktualizujte web, nenechávejte na hostingu staré instalace.
- **Šifrování archivu chrání stažený soubor, ne proces zálohy.** Během běhu
  jsou data nutně v paměti a v dočasných souborech.
- **Přístupy k databázi jsou během úlohy v session.** Session soubor je jen pro
  účet webu (`0700`/`0600`) a po dokončení úlohy se údaje z něj smažou, ale po
  dobu běhu na disku jsou.
- **Bez HTTPS nedokáže nástroj zabránit odposlechu.** Doporučené nastavení je
  „Povolit přístup jen přes HTTPS“ zapnuté.
- **Neověřuje obsah zálohy proti změně.** Kontroluje se jen, že archiv jde
  otevřít a není poškozený.
- **Tabulky bez primárního klíče** se čtou stránkovaně bez zaručeného pořadí;
  u velmi vytížené databáze může řádek teoreticky vypadnout nebo se zopakovat.

---

## 6. Doporučené nastavení pro maximální bezpečnost

1. Nástroj nahrát do **podadresáře s nenápadným názvem** (ne `/backup/`).
2. Zapnout **HTTPS** a jeho vynucení v nastavení.
3. Vyplnit **whitelist IP** (pokud máte pevnou adresu).
4. Nastavit **heslo pro šifrování archivu** – stažené zálohy pak nejsou čitelné
   ani při úniku.
5. Zvolit **dlouhé heslo** (klidně větu), minimum je 10 znaků.
6. Zálohy pravidelně stahovat a nenechávat je na serveru donekonečna
   (retence je ve výchozím stavu 14 dní / 10 archivů).
7. Po dokončení práce se **odhlásit**.

---

## 7. Hlášení chyb

Našli jste bezpečnostní problém? Napište prosím do issues repozitáře – nebo
rovnou pošlete pull request.
