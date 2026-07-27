# PHP Backup Tool 4.0

Jednoduchý a bezpečný nástroj pro zálohování webu na sdíleném hostingu – **bez SSH,
bez Composeru, bez editace konfiguračních souborů**.

- 📦 **Zabalí obsah adresáře na FTP** do ZIP archivu
- 🗄️ **Vyexportuje databázi** (MySQL/MariaDB) a přidá ji do stejného archivu
- 🔐 **Postaveno kolem bezpečnosti** – heslo, šifrovaná tajemství, ochrana proti
  CSRF, průchodu cestou i hádání hesla
- 🖱️ **Nastavení na dvě políčka** – heslo a adresář, nic víc

---

## Instalace (3 kroky, cca minuta)

1. **Nahrajte** obsah tohoto adresáře na FTP – klidně do podadresáře webu,
   např. `www/zaloha/`.
2. **Otevřete** v prohlížeči `https://vasedomena.cz/zaloha/` (ideálně přes HTTPS).
3. **Vyplňte** heslo a vyberte adresář, který se má zálohovat. Hotovo.

Nástroj si sám vytvoří adresář `data/`, ochranné soubory i vše ostatní.
Není potřeba nic upravovat v žádném PHP souboru a **žádný instalační adresář
se po instalaci nemusí mazat** – instalace se sama zamkne, jakmile jednou proběhne.

> ⚠️ **Nastavení dokončete hned po nahrání.** Než si zvolíte heslo, může to
> za vás udělat kdokoli, kdo adresu zná – stejně jako u instalace redakčního
> systému. Po zvolení hesla už se instalace znovu spustit nedá.

### Co server potřebuje

| Požadavek | Nutné | K čemu |
|---|---|---|
| PHP 7.4+ (ideálně 8.x) | ano | běh nástroje |
| rozšíření `zip` | ano | tvorba archivů |
| rozšíření `mysqli` | jen pro DB | export databáze |
| rozšíření `openssl` | doporučeno | šifrování uložených hesel a archivů |
| zapisovatelný adresář nástroje | ano | ukládání záloh |

Kontrolu prostředí uvidíte přímo na instalační obrazovce.

---

## Použití

Po přihlášení vyberete, co zálohovat:

- **Jen soubory** – zabalí zvolený adresář
- **Jen databáze** – vyexportuje vybrané databáze do SQL
- **Soubory + databáze** – obojí v jednom archivu (databáze v podadresáři `databaze/`)

Záloha běží **po krocích**, takže nespadne na limitu `max_execution_time`
běžném na sdílených hostinzích. Průběh vidíte na obrazovce; když se stránka
zavře, dá se záloha později dokončit tlačítkem *Pokračovat*.

Hotové archivy jsou v seznamu ke stažení. Staré se mažou samy podle nastavení
(výchozí: 14 dní / posledních 10 záloh).

Velké zálohy se automaticky dělí na části po 150 MB – jinak by se jeden obří
soubor nedal přes web stáhnout. Tlačítko **Stáhnout vše** je pak stáhne
postupně jednu po druhé (ne naráz – paralelní přenosy si dělí linku a hosting
je ukončí). Části jsou plnohodnotné ZIPy, při obnově je rozbalíte všechny
do téhož adresáře.

### Přístupy k databázi

Zadávají se až ve chvíli zálohy a **nikam se neukládají**. Pokud si je necháte
zapamatovat, uloží se zašifrované vaším přihlašovacím heslem – bez přihlášení
je z nich nikdo nedostane.

---

## Bezpečnost

Podrobný popis je v [BEZPECNOST.md](BEZPECNOST.md). Ve zkratce:

| Riziko | Opatření |
|---|---|
| Kdokoli spustí zálohu | přihlášení heslem (`password_hash`), session s krátkou platností |
| Hádání hesla | rostoucí prodleva a zablokování IP po 5 pokusech |
| Podvržený požadavek (CSRF) | token u každé měnící operace, cookie `SameSite=Strict` |
| Stažení cizí zálohy | nezhádatelný název archivu + kontrola přihlášení |
| Únik konfigurace a hesel | data v `.php` souborech s ochrannou hlavičkou, tajemství šifrovaná AES‑256‑GCM |
| Vytažení souborů mimo web | kontrola `realpath`, přeskakování symlinků, přísná validace názvů |
| Injektáž do shellu | nástroj **nespouští žádné externí příkazy** |
| XSS | escapování všech výstupů + Content‑Security‑Policy s nonce |
| Odposlech | volitelné vynucení HTTPS, HSTS |

Navíc: volitelný **whitelist IP adres**, volitelné **šifrování archivu heslem
(AES‑256)** a **záznam činnosti** (přihlášení, zálohy, mazání).

---

## Časté otázky

**Kde mám zálohy uložené?**
V `data/backups/` uvnitř adresáře nástroje. Adresář je chráněný a názvy archivů
obsahují náhodnou část, takže je nikdo neuhodne. Chcete-li je mít mimo web,
nastavte v `data/config.php` položku `backup_store` na jinou absolutní cestu.

**Zapomněl jsem heslo.**
Heslo nelze obnovit (chrání šifrovaná tajemství). Smažte soubor
`data/config.php` a nástroj vás provede nastavením znovu; hotové zálohy zůstanou.

**Záloha skončí chybou „Zápis do archivu selhal“.**
Došlo místo na disku hostingu, nebo je adresář nástroje jen pro čtení.

**Web po nahrání hlásí chybu 500.**
Hosting nepovoluje direktivu `Options` v `.htaccess`. Zakomentujte v souboru
`.htaccess` řádek `Options -Indexes`.

**Stahování archivu skončí „chybou sítě“.**
Hosting ukončí požadavek, který trvá příliš dlouho – u velkého archivu se
tedy stahování nemusí vejít do limitu. Proto se záloha **dělí na části**
(výchozí 150 MB, v *Nastavení* lze změnit). Každá část je samostatný platný
ZIP; při obnově rozbalte všechny do stejného adresáře. Když stahování přesto
padá, snižte velikost části třeba na 50 MB.

Přenos navíc podporuje hlavičku `Range`, takže přerušené stahování jde
navázat (*Pokračovat* ve správci stahování). Nejspolehlivější cesta u velkých
záloh je stáhnout je z `data/backups/` **přes FTP** – tam žádné limity
webserveru neplatí.

**Databáze se nepřipojí, nebo stránka vrátí 502.**
Skoro vždy je to **název databázového serveru**. Na sdíleném hostingu databáze
zpravidla neběží na `localhost`, ale na zvláštním stroji – správný název najdete
v administraci hostingu u přístupů k databázi. Nástroj čeká na odpověď nejvýše
8 sekund a pak to rovnou napíše.

**Hosting nepovolí výpis databází.**
Nevadí – po připojení zůstane seznam prázdný a název databáze zadáte do pole
*Nebo název databáze ručně*.

**Velké databáze / velký web.**
V *Nastavení* zvyšte *Délku jednoho kroku* (např. 25 s) – záloha bude rychlejší.
Tabulky bez primárního klíče se exportují bez zaručeného pořadí řádků;
u běžných aplikací to nevadí.

**Co se nezálohuje?**
Vzory ze seznamu *Co vynechat* (výchozí `.git`, `node_modules`, `*.log`, …),
symbolické odkazy a vlastní datový adresář nástroje.

---

## Struktura projektu

```
index.php          jediný vstupní bod (nastavení, přihlášení, zálohy, stahování)
lib/               logika nástroje – z webu nespustitelná
  bootstrap.php      inicializace
  Storage.php        datový adresář, konfigurace, log
  Security.php       hlavičky, session, přihlášení, CSRF, IP, cesty
  Crypto.php         šifrování uložených tajemství
  Job.php            řízení úlohy po krocích
  FilesBackup.php    procházení adresáře a balení do ZIP
  SqlDump.php        export databáze přes mysqli
  View.php           vykreslování a escapování
views/             šablony obrazovek
data/              vzniká automaticky: konfigurace, session, zálohy (do gitu nepatří)
version/           starší verze nástroje (1.0, 2.0, 3.0)
```

---

## Starší verze

Předchozí generace nástroje zůstávají v adresáři [`version/`](version/):

- [`version/3.0`](version/3.0) – oddělené nástroje `php-zip` a `php-sqldump`
- [`version/2.0`](version/2.0) – sjednocený nástroj s tokenem a IP whitelistem
- [`version/1.0`](version/1.0) – první skripty

Verze 4.0 s nimi nesdílí konfiguraci ani data – je to samostatný nástroj.

---

## Licence

MIT – viz [LICENSE](LICENSE).
