# Invoicyfy ko Hostinger par live karna

Example address: **https://billing.scoopiez.in**. Aap koi aur naam bhi rakh sakte ho, jaise `app.scoopiez.in`.
Web app aur API ek hi address par chalte hain: app `/` par aur API `/api/` par. Isliye CORS ka koi jhanjhat nahi.

```
public_html/billing/          <- subdomain ka folder
├─ index.html, assets/, .htaccess    (web app)
└─ api/                              (PHP API)
   └─ .env                           (password / secret — sirf server par)
```

Zaroorat: Hostinger Premium/Business plan (SSH ke saath), PHP 8.1+ (8.3 recommended), MySQL.

---

## Step 1 — Upload file banao (sirf pehli baar / update par)

Apne computer par, jahan dono repos hain:

```bash
cd Invoicyfy
npm install
scripts/package-hostinger.sh ../Invoicyfy-Backend
```

Isse `Invoicyfy/deploy/invoicyfy-hostinger.zip` ban jaati hai. Usme built app + API + vendor hota hai,
`.env` nahi hota. (Windows par yeh command Git Bash me chalao.)

## Step 2 — Subdomain banao

hPanel → **Websites** → scoopiez.in → **Manage** → **Domains → Subdomains**

* Subdomain: `billing` → Create.
* Folder: `public_html/billing` (default yahi hota hai).

## Step 3 — PHP version

Usi website me **Advanced → PHP Configuration** → **PHP 8.3** select karke save karo.
Extensions `pdo_mysql`, `openssl`, `curl`, `mbstring` Hostinger par default ON hote hain.

## Step 4 — Database

**Databases → Management** → Create a new MySQL database:

* Database name: `invoicyfy` (Hostinger aage prefix lagata hai, jaise `u123456789_invoicyfy`)
* Username: `invoicyfy` (ban jaata hai `u123456789_invoicyfy`)
* Password: ek strong password. Isko note kar lo.

## Step 5 — Files upload

**Files → File Manager** → `public_html/billing` kholo:

1. Andar ki default files (jaise `default.php`) delete kar do.
2. **Upload** → `invoicyfy-hostinger.zip`.
3. Zip par right-click → **Extract** → ek `invoicyfy` folder banega.
4. `invoicyfy` ke andar sab select karo (`.htaccess` bhi) → **Move** → `public_html/billing`.
   Phir khaali `invoicyfy` folder aur zip delete kar do.

Ya SSH se (Step 6 wala login) — zyada aasaan:

```bash
cd ~/domains/scoopiez.in/public_html/billing
unzip -o invoicyfy-hostinger.zip
cp -a invoicyfy/. . && rm -rf invoicyfy invoicyfy-hostinger.zip
```

## Step 6 — SSH login

**Advanced → SSH Access** → Enable karo. Wahan command dikhega jaise:

```
ssh -p 65002 u123456789@123.45.67.89
```

Windows me **PowerShell** kholo, yahi command chalao aur hPanel wala password daalo.

## Step 7 — `.env` (secrets) banao

```bash
cd ~/domains/scoopiez.in/public_html/billing/api
cp .env.example .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # 3 baar chalao: 3 alag secret milenge
```

Ab File Manager me `api/.env` kholkar edit karo:

```ini
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Kolkata

DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456789_invoicyfy
DB_USER=u123456789_invoicyfy
DB_PASS=step-4-wala-password

CORS_ALLOWED_ORIGINS=https://billing.scoopiez.in

JWT_ACCESS_SECRET=pehla-secret
JWT_REFRESH_SECRET=doosra-secret
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=604800

APP_KEY=teesra-secret
WHATSAPP_API_BASE=https://graph.facebook.com/v21.0
```

`APP_KEY` aur JWT secrets kabhi share mat karo. Inhe kahin safe (password manager) me bhi rakh lo.

## Step 8 — Database tables + admin user

```bash
cd ~/domains/scoopiez.in/public_html/billing/api
php -v                       # 8.1 ya upar hona chahiye
php database/migrate.php --admin-email=aapka@email.com --admin-password='StrongPass@123'
```

Agar `php -v` purana version dikhaye to poora path use karo:
`/opt/alt/php83/usr/bin/php database/migrate.php ...`

"Migration finished" dikhe to database ready hai.

## Step 9 — SSL (https)

**Security → SSL** → `billing.scoopiez.in` ke liye free SSL install karo (aksar apne aap lag jaata hai).
App ka `.htaccess` http ko apne aap https par bhej deta hai.

## Step 10 — Check karo

1. `https://billing.scoopiez.in` kholo → login page (printer wala animation) aana chahiye.
2. Step 8 wale email/password se login karo.
3. `https://billing.scoopiez.in/api/.env` kholo → **403 Forbidden** aana chahiye (secret safe hai).
4. Kisi page par refresh karo (jaise `/invoices`) → page wapas khulna chahiye, 404 nahi.

## Step 11 — Pehli setup (app ke andar)

1. **Settings** → shop details: naam, address, phone, GSTIN, FSSAI no., bank/UPI, bill prefix, logo.
2. **Invoice Design** → template, QR codes (Google review link) aur default design chuno.
3. **Roles & Permissions** + **Users** → biller/manager ke login banao.
4. **Categories** → category + uska unit (kg / L / pcs) banao.
5. **Products** → ice cream items, loose (per kg) items ke 100/250/500/750/1000 g presets.
6. **Offers & Messages → Setup** → WhatsApp / Email / SMS.
7. **Backup & Restore** → pehla backup le lo.

Agar purana data local computer par hai: local app me **Backup & Restore → Download full backup**,
phir live app me **Import backup**.

---

## Update kaise karein (naya version)

1. **Pehle backup lo:** app me **Backup & Restore → Download full backup** (aur hPanel → Databases →
   phpMyAdmin → Export bhi kar sakte ho).
2. Nayi zip `public_html/billing` me upload karke extract karo, purani files overwrite hone do.
   `api/.env` zip me nahi hota, isliye aapke secrets safe rehte hain.
3. Admin se login karo → upar pila (yellow) banner aayega **"Update database"** → button dabao.
   Naye tables/columns ban jaate hain, data safe rehta hai. (SSH ho to `php database/migrate.php` bhi chalega.)
4. Browser me ek baar hard refresh (Ctrl+Shift+R). Mobile par app band karke phir kholo.

### Offers + automatic messages ke liye cron job — ek baar set karo

Email / SMS / WhatsApp API offers page band karne ke baad bhi jaate rahein, aur V3 ke automatic messages
(raat ka summary, low stock alert, birthday/anniversary wish, udhaar reminder) time par jaayein, iske liye:
hPanel → **Advanced → Cron Jobs** → Custom → **har minute** (`* * * * *`) → command:

```
/usr/bin/php /home/<hostinger-user>/domains/scoopiez.in/public_html/billing/api/cron/process-campaigns.php
```

(`<hostinger-user>` = `u975462343` jaisa aapka user. File Manager me path upar dikhta hai.)

### Email / SMS setup (Offers & Messages → Setup)

* **Email:** hPanel → Emails me ek mailbox banao (jaise `offers@scoopiez.in`). Setup → Email tab →
  **Use Hostinger** dabao → mailbox ka email + password bharo → **Send test email**.
* **SMS:** Fast2SMS ki API key (ya apne SMS provider ka URL). India me promotional SMS ke liye DLT
  registration zaroori hai — provider se template approve karwao.
* **WhatsApp API:** Meta Business me approved template; offer me photo bhejni ho to template me
  IMAGE header rakho aur Setup me "Template has an image header" on karo.
* Offer photos `api/uploads/offers/` me save hoti hain — yeh folder writable hona chahiye (default hota hai).

## Version 3 — naye features ka setup (ek baar)

Update ke baad **Update database** button zaroor dabao (naye tables: day close, coupons, loyalty,
activity log, held bills...). Purana data aur purane screens waise hi chalte hain.

1. **Roles & Permissions** — har role me *Max discount without manager PIN (%)* bharo (jaise Staff = 5).
   Khaali = koi limit nahi (pehle jaisa).
2. **Counter & Printer → My approval PIN** — Manager/Admin apna 4-8 digit PIN set karein.
3. **Counter & Printer → Counter** — tables ki sankhya, UPI QR screen par, aur chahiye to
   *Billing only after the day is opened*.
4. **Counter & Printer → Printer** — har billing device par ek baar:
   * Thermal printer USB / Bluetooth / Serial se **Connect printer** → **Test print**. Iske baad bill
     aur KOT bina print-window ke seedhe chhapte hain (Chrome / Edge, https par).
   * Windows PC par koi bhi printer: Chrome *kiosk printing* shortcut (steps usi tab me) — Chrome
     default printer par bina pooche chhapta hai.
   * Printer na mile to normal print window apne aap khulti hai — billing kabhi nahi rukti.
5. **Coupons & Loyalty** — coupons banao, Loyalty tab me points ka rule on karo.
6. **Automatic Messages** — owner ka mobile/email, time aur channel chuno, *Send ... now* se test karo.
   Cron job (upar) laga ho to app band hone par bhi time par jaate hain; warna app khuli ho tab jaate hain.
7. **Customers** me birthday / anniversary bharo — us din coupon ke saath wish jaayega.

**Offline billing:** internet jaane par Quick Bill (retail) bante aur chhapte rehte hain, `OFF-` number ke
saath device me save hote hain, aur internet aate hi apne aap server par jaakar asli bill number paate hain
(upar ka cloud icon dikhata hai kitne baaki hain). Coupon / points / order se bane bill ke liye internet
chahiye. App ek baar online khulne ke baad internet ke bina bhi khul jaati hai (same device + browser).

**Demo & help:** upar **Demo & help** (topi wala icon) dabao — har screen ka step-by-step demo.

## Speed (API fast rakhne ke liye)

* Naya version upload karke **Update database** zaroor dabao: naye indexes + report summary tables bante hain.
  Data bahut bada ho (lakhs bills) to SSH se `cd public_html/billing/api && php database/migrate.php` chalao
  (browser timeout se bachne ke liye).
* **Cron job** (upar wala, har minute) report summaries ko bhi taaza rakhta hai — zaroor lagao.
* hPanel → **Advanced → PHP Configuration**: PHP **8.3**, **OPcache ON**, `memory_limit` 256M.
* `api/.env` me `DB_HOST=localhost` rakho (Hostinger ka MySQL usi server par hota hai — IP/remote host se slow hota hai).
* Speed check: browser me F12 → Network → koi API request → **Timing** tab me `Server-Timing` dikhega
  (`app` = server ka total time, `db` = MySQL time). Agar ye kam hai par request slow hai to network/hosting ki dikkat hai.
* Uptime check URL: `https://billing.scoopiez.in/api/health.php` (200 = sab theek).

## Backup ki aadat

* App: **Backup & Restore → Download full backup** hafte me kam se kam ek baar. File ko Google Drive
  ya pen drive me rakho.
* Hostinger: **Files → Backups** me daily/weekly server backups bhi milte hain.

## Problem aaye to

| Dikkat | Hal |
| --- | --- |
| Login par "Something went wrong" | `api/.env` me DB name/user/password check karo (Hostinger prefix `u123..._` ke saath). |
| "Server is not configured (JWT secrets missing)" | `.env` me `JWT_ACCESS_SECRET` / `JWT_REFRESH_SECRET` bharo. |
| Har request par logout / 401 | `.htaccess` upload hua hai ya nahi dekho (File Manager → Settings → show hidden files). |
| Refresh par 404 | `public_html/billing/.htaccess` missing hai. |
| Offers page band karne par sending ruk jaati hai | Cron job set karo (upar dekho). |
| Photo upload fail | `api/uploads/offers` folder ki permission 755 rakho. |
| Naye screen par "Unknown column" error | Admin login → **Update database** button dabao. |
| Bill save par "The day is not opened yet" | Day Close screen par **Open day** karo, ya Counter me wo rule band karo. |
| Direct print ka option grey hai | App https par kholo; Chrome / Edge use karo (Bluetooth ke liye Android/PC Chrome). |
| Naya version nahi dikh raha | App ek baar band karke kholo / Ctrl+Shift+R (offline copy agle visit par update hoti hai). |
| Error ki detail chahiye | Thodi der ke liye `APP_DEBUG=true` karo, check karke wapas `false` karo. |
