# Setup adresář - Nastavení API Tokenu

## 🎯 Účel

Tento adresář obsahuje jednoduché webové rozhraní pro nastavení API tokenu bez nutnosti ručního upravování `config.php`.

## 📋 Použití

1. **Otevřete v prohlížeči:**
   ```
   https://vase-domena.cz/version/2.0/setup/
   ```

2. **Vygenerujte token:**
   - Klikněte na tlačítko "Vygenerovat nový token"
   - Token bude automaticky uložen do `config.php`

3. **Zkopírujte token (volitelně):**
   - Pokud potřebujete token zkopírovat, použijte tlačítko "Zkopírovat token"

## 🔒 Bezpečnost

### Po dokončení nastavení:

**DŮLEŽITÉ:** Po vygenerování a uložení tokenu **SMAŽTE tento adresář** z bezpečnostních důvodů!

```bash
# Přes FTP nebo SSH smažte adresář:
rm -rf setup/
```

### Nebo zablokujte přístup:

Upravte `setup/.htaccess` a odkomentujte:
```apache
Require all denied
```

### Nebo omezte přístup pouze z vaší IP:

Upravte `setup/.htaccess` a přidejte vaši IP adresu:
```apache
<RequireAny>
    Require ip VAŠE_IP_ADRESA
</RequireAny>
```

## ⚠️ Varování

- **NIKDY** nenechávejte tento adresář přístupný po dokončení setupu
- Token je citlivá informace - chraňte ho
- Pravidelně rotujte token (vygenerujte nový)

## 🔄 Obnovení tokenu

Pokud potřebujete změnit token později:

1. Obnovte tento adresář (pokud jste ho smazali)
2. Otevřete `setup/index.php`
3. Vygenerujte nový token
4. Znovu smažte adresář

---

**PO DOKONČENÍ NASTAVENÍ TENTO ADRESÁŘ SMAŽTE!**



