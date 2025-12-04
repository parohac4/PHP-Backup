# 🔒 Nastavení IP Whitelistu

## 📋 Nastavení IP Whitelistu

V `.htaccess` musíte **přidat své IP adresy** pro přístup k nástroji.

## ⚠️ DŮLEŽITÉ

**PŘED POUŽITÍM MUSÍTE:**
1. **Zjistit svou IP adresu**
2. **Přidat ji do `.htaccess`**
3. **Zkontrolovat, že whitelist funguje**

## 🔧 Jak zjistit svou IP adresu

### Metoda 1: Webová stránka
Navštivte: https://www.whatismyip.com/

### Metoda 2: Příkaz v terminálu
```bash
# IPv4
curl -4 ifconfig.me

# IPv6
curl -6 ifconfig.me
```

### Metoda 3: Z logů serveru
Zkontrolujte access logy vašeho webového serveru.

## 📝 Jak upravit .htaccess

1. **Otevřete `.htaccess`**
2. **Najděte sekci `<RequireAny>`:**
   ```apache
   <RequireAny>
       # ZDE PŘIDEJTE SVÉ IP ADRESY:
       # Require ip VAŠE_IP_ADRESA_IPv4
       # Require ip VAŠE_IP_ADRESA_IPv6
   </RequireAny>
   ```

3. **Odkomentujte a upravte řádky s IP adresami:**
   ```apache
   <RequireAny>
       # VAŠE IP ADRESY:
       Require ip 192.168.1.100
       Require ip 2001:0db8:85a3::8a2e:0370:7334
       
       # Pokud potřebujete povolit rozsah IP:
       # Require ip 192.168.1.0/24
   </RequireAny>
   ```

4. **Uložte soubor**

## 🌐 Podpora IPv4 a IPv6

Můžete přidat jak IPv4, tak IPv6 adresy:

```apache
<RequireAny>
    # IPv4 adresy
    Require ip 192.168.1.100
    Require ip 10.0.0.50
    
    # IPv6 adresy
    Require ip 2001:0db8:85a3:0000:0000:8a2e:0370:7334
    Require ip 2001:db8::1
    
    # Rozsahy IP (CIDR)
    Require ip 192.168.1.0/24
    Require ip 2001:db8::/32
</RequireAny>
```

## ⚠️ Problém s dynamickou IP

Pokud máte dynamickou IP adresu, zvažte:

1. **VPN** - Použijte VPN s fixní IP adresou
2. **DynDNS** - IP whitelist nefunguje s doménovými jmény
3. **Alternativní autentizace** - Použijte pouze API token (méně bezpečné)

## 🧪 Testování

Po nastavení IP whitelistu:

1. **Zkuste přistoupit z povolené IP** - mělo by fungovat
2. **Zkuste přistoupit z jiné IP** - mělo by zobrazit 403 Forbidden
3. **Zkontrolujte logy** - měly by obsahovat záznamy o zamítnutých přístupech

## 🔍 Řešení problémů

### Chyba 403 Forbidden i z povolené IP
- Zkontrolujte, že IP adresa je správně zapsaná
- Zkontrolujte, že používáte správný formát (IPv4 vs IPv6)
- Zkontrolujte, že Apache má povolen mod_authz_core

### Nevíte, jakou IP máte
- Použijte `whatismyip.com`
- Zkontrolujte logy serveru po pokusu o přístup

### Potřebujete dočasně povolit všechny IP
**NEDOPORUČUJEME!** Ale pokud je to nutné:
```apache
# ODKOMENTUJTE PRO DOČASNÉ POVOLENÍ VŠECH IP:
# Require all granted

# A ZAKOMENTUJTE RequireAny blok
```

---

**PO DOKONČENÍ NASTAVENÍ ODSTRANĚTE TESTOVACÍ IP ADRESY!**

