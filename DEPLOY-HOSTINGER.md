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
4. **Products** → ice cream items, loose (per kg) items ke 100/250/500/750/1000 g presets.
5. **Offers & Messages → WhatsApp setup** (jab Meta account ready ho jaaye).
6. **Backup & Restore** → pehla backup le lo.

Agar purana data local computer par hai: local app me **Backup & Restore → Download full backup**,
phir live app me **Import backup**.

---

## Update kaise karein (naya version)

1. Step 1 se nayi zip banao.
2. Upload karke extract karo, purani files overwrite hone do. `api/.env` zip me nahi hota, isliye
   aapke secrets safe rehte hain.
3. SSH se `php database/migrate.php` chalao (naye tables/columns; data safe rehta hai).
4. Browser me ek baar hard refresh (Ctrl+Shift+R).

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
| Error ki detail chahiye | Thodi der ke liye `APP_DEBUG=true` karo, check karke wapas `false` karo. |
