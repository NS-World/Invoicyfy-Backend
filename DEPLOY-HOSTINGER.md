# Khanak (pehle Invoicyfy) ko Hostinger par live karna

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

## Version 4 — naye features ka setup (ek baar)

Update ke baad **Update database** button zaroor dabao (naye tables: suppliers, purchases, recipes,
batches, combo offers; aur bills me online platform / offer columns). Purana data aur screens waise hi chalte hain.
Manager role ko *Suppliers & Purchases* apne aap mil jaata hai; Staff ko chahiye to Roles me tick karo.

1. **Suppliers → New supplier** — naam, mobile, GSTIN, purana baaki (opening balance).
2. **Purchases → New purchase** — supplier ka bill: items, rate, GST, batch / expiry. Save karte hi stock
   badhta hai, cost price update hota hai, GST *input credit* me judta hai (GST summary report), aur baaki
   rakam supplier ke khaate me. Supplier ko payment: Suppliers → ₹ icon.
3. **Products** — doodh, cone, topping jaise kachcha maal product banao aur *Raw material* on karo
   (Quick Bill me nahi dikhega). **Recipes & Cost** me sundae / shake ki recipe (1 piece me kitna lagta hai).
   Bill hote hi ingredients stock se kam, aur report me asli cost / profit.
4. **Expiry & Wastage** — batch ki expiry, jaldi expire hone wala maal, aur pighla / kharab maal ki entry
   (loss report). Counter settings me *Expiry alert (days before)*; low-stock automatic message me bhi aata hai.
5. **Combo & Happy Hour** — "2 Scoop + 1 Shake = ₹199" ya "3–5 baje 10% off". Quick Bill me apne aap lagta
   hai (cashier ki discount limit me nahi ginta), ek bill ke liye *Apply offers* off kar sakte ho.
6. **Counter & Printer → Counter**:
   * *Online platforms* — Swiggy / Zomato ka commission %. Quick Bill me *Sold through* → platform + order no.
     Saare orders **Online orders** screen par: commission, payout aaya ya baaki (*Payout received*).
   * *Send the bill to the customer automatically* — bill save hote hi customer ko bill ka link
     (WhatsApp API / SMS / Email — Offers & Messages → Setup se). Link phone par khulta hai, PDF save ho sakta hai.
     API na ho to bill screen par **Send bill link (WhatsApp)** button se apne phone ka WhatsApp khulta hai.
7. **Owner's view (phone)** — aaj ki sale, cash, top items, udhaar, expiry, supplier baaki — phone par ek nazar me.
8. **Staff report** — kis cashier ne kitni sale, discount, cancel; shift-wise cash difference.
9. **GSTR-1 file** — mahina chuno → *Prepare* → JSON (GST portal / offline tool) aur Excel. Upload se pehle
   offline tool me khol ke check karo; credit note aur Swiggy/Zomato 9(5) wali sale CA se confirm karo.
10. **Backup & Restore → Automatic backup every night** (sirf Admin):
    * Google Cloud Console → naya project → *Google Drive API* enable → *OAuth consent screen* (External,
      apna email test user) → *Credentials → Create OAuth client ID → Web application*.
    * *Authorized redirect URI* me wahi URL daalo jo screen par dikhta hai
      (`https://billing.scoopiez.in/api/master/backup/drive-callback.php`).
    * Client ID + Client secret app me save karo → **Connect Google Drive** → Allow.
    * *Backup every night* on, time (jaise 02:00). Cron job (upar wala, har minute) laga ho to raat ko apne aap;
      warna jab app pehli baar khule tab us din ka backup ho jaata hai.
    * Backup file server par bhi rehti hai (`api/storage/backups`, web se band) aur wahi se download / restore hoti hai.

11. **Inventory → Add inventory** — saare products ki list; jo maal aaya uski quantity (aur cost) bharo, note likho
    (supplier / bill no), *Save stock*. Save ke baad har product ka naya stock aur status (In stock / Low / Out) dikhta hai.
12. **Delete (Product / Customer)** — delete dabane par laal box me dikhta hai ki saath me kya-kya delete hoga;
    `DELETE` likh ke hi delete hota hai.
    * Product: stock history, batches, recipe lines delete; us item wale combo offers band. Purane bills me item ka
      naam aur amount rehta hai (bill / GST record nahi badalta).
    * Customer: uske saare bills, payments, orders, loyalty points, personal coupons aur messages bhi delete ho jaate hain.

## Data & Archive (saal-wise Google Drive archive + purane saal hatana) — sirf Admin

Menu → **Admin → Data & Archive**. Sab kuch screen se control hota hai:

* **Google Drive folders** (Backup & Restore me ek baar Connect):
  `Invoicyfy Backups / FY 2026-27 / daily` (last 30 raat), `/ monthly` (har mahine ka pehla backup, last 12),
  aur 31 March ke baad us saal ka poora data ek file me: `FY 2026-27 / FY-2026-27-FINAL.json.gz` (hamesha rehta hai).
* **Kitne saal app me rakhna hai**: 3 / 4 / 5 / 6 / 7 / 8 / 10 saal ya "kabhi mat hatao". Logs (activity / message)
  ke liye alag: 3 / 6 / 12 / 24 / 36 mahine ya hamesha.
* **Make archive** — saal ka FINAL file Drive par (size check ke saath). **Download** se PC par bhi copy rakh sakte ho.
* **Remove year** — sirf keep period se purane saal, sabse purana pehle, aur tabhi jab uska archive Drive par safe ho.
  Laal list me dikhta hai kya hatega; `PURGE` likhna padta hai. Udhaar (unpaid) bills, pending orders, open cash shift,
  unpaid supplier bills kabhi nahi hat-te. Report me purane saal ka total (sale, GST) waisa hi rehta hai.
* Kaam thoda-thoda chalta hai (cron har minute / app khula ho to / screen khuli ho to) — timeout nahi hota, beech me
  ruk jaye to wahi se aage chalta hai. Har backup / archive / removal ka log screen par.
* *Remove automatically* on karoge to har raat ek purana saal (archive safe hone ke baad) apne aap hatega. Default: off.
* GST: bill records ~6 saal rakhne hote hain — Drive wali FINAL file wahi record hai, use delete mat karna.

## Printer (Windows PC par pehle se install printer)

Windows me jo printer apne driver ke saath install hai wo browser ki **USB** list me nahi aata (Windows use apne paas rakhta hai).
Counter & Printer → Printer → **Windows PC: silent print** → **Download shortcut** → desktop par rakho, bill printer ko
Windows ka *default printer* banao, aur app roz isi shortcut se kholo. Bill / KOT bina print window ke seedha print hote hain.
(*Test print* se check karo. Shortcut Chrome ya Edge dono me chalta hai.)

## Speed (API fast rakhne ke liye)

* Naya version upload karke **Update database** zaroor dabao: naye indexes + report summary tables bante hain.
  Data bahut bada ho (lakhs bills) to SSH se `cd public_html/billing/api && php database/migrate.php` chalao
  (browser timeout se bachne ke liye).
  V4 speed update ke baad pehli baar report summaries dobara bante hain (6 lakh bills par lagbhag 1 minute).
* **Cron job** (upar wala, har minute) report summaries ko bhi taaza rakhta hai — zaroor lagao.
* Bills / Payments me "Patel" jaisa bahut common naam search karna ho to date range (is mahine / 3 mahine) rakho —
  ye milliseconds me aata hai; "All time" par lakhs bills me common naam dhoondhne me ~1 second lag sakta hai.
* hPanel → **Advanced → PHP Configuration**: PHP **8.3**, **OPcache ON**, `memory_limit` 256M.
* `api/.env` me `DB_HOST=localhost` rakho (Hostinger ka MySQL usi server par hota hai — IP/remote host se slow hota hai).
* Speed check: browser me F12 → Network → koi API request → **Timing** tab me `Server-Timing` dikhega
  (`app` = server ka total time, `db` = MySQL time). Agar ye kam hai par request slow hai to network/hosting ki dikkat hai.
* Uptime check URL: `https://billing.scoopiez.in/api/health.php` (200 = sab theek).

## Backup ki aadat

* App: **Backup & Restore → Automatic backup every night** + Google Drive (Version 4) on rakho.
  Saath me **Download full backup** (Excel) mahine me ek baar pen drive me.
* Hostinger: **Files → Backups** me daily/weekly server backups bhi milte hain.

## Khanak SaaS — plan bechna, payment, har dukaan ka alag account

Is mode me Khanak ek product ki tarah bikta hai: koi bhi dukaan **/pricing** par plan dekhe → **/signup** par
details bhare → free trial ya Razorpay se payment → turant uski dukaan (alag database) ban jaati hai aur screen
par + email me **shop code, email, password** milta hai → **/login** par shop code ke saath login.
`SAAS_MODE` band ho (default) to app bilkul pehle jaisa ek-dukaan wala chalta hai — shop code field, plan menu,
sign-up kuch nahi dikhta.

| Plan | Saal | Mahina | Users | Stores | Kya extra |
|---|---|---|---|---|---|
| Starter | ₹2,999 | ₹299 | 2 | 1 | Billing, stock, reports, backup |
| Growth | ₹5,999 | ₹599 | 5 | 1 | + Orders/KOT, udhaar/loyalty, offers, purchases |
| Pro | ₹11,999 | ₹1,199 | 15 | 3 | + Recipes, combo, online orders, owner/staff report, GSTR-1 |
| Chain | ₹9,999 / store | — | ∞ | jitne kharide | Sab kuch |

Sab par 18% GST alag. Free trial 14 din. Plan khatam → 7 din grace (banner) → phir app *read-only*
(dekh / export kar sakte ho, naya bill save nahi). Data kabhi delete nahi hota. Daam aur features
**/khanak-admin → Plans** se badal sakte ho.

### Server kaunsa?

Har dukaan ka apna MySQL database banta hai, isliye app ko `CREATE DATABASE` ki permission chahiye.
**Hostinger VPS** (ya koi bhi VPS) best hai. Shared hosting (hPanel) par app khud database nahi bana sakta;
wahan pehle se khaali databases bana ke `SAAS_DB_POOL` me likh sakte ho — lekin tabhi chalega jab **ek hi
MySQL user** (`DB_USER`) un sab databases ko khol sake. Hostinger shared par har database ka alag user hota
hai, isliye SaaS ke liye VPS lo.

### 1. `.env` me ye jodo

```
SAAS_MODE=true
SAAS_DB_NAME=khanak_control          # control database: plans, dukaanein, payments, invoices
SAAS_DB_PREFIX=khanak_t_             # har dukaan: khanak_t_<shop_code>
# SAAS_DB_POOL=u123_shop1,u123_shop2 # sirf jab app database nahi bana sakta
SAAS_MASTER_KEY=<64 hex>             # php -r "echo bin2hex(random_bytes(32));"  — kabhi mat badlo
SAAS_PUBLIC_URL=https://app.khanak.in   # site ka address (API = /api)

SAAS_PAYMENT_MODE=razorpay           # test ke liye: mock (koi paisa nahi katta)
SAAS_RAZORPAY_KEY_ID=rzp_live_xxx
SAAS_RAZORPAY_KEY_SECRET=xxx
SAAS_RAZORPAY_WEBHOOK_SECRET=xxx

SAAS_TRIAL_DAYS=14                   # 0 = free trial band
SAAS_GRACE_DAYS=7
SAAS_GST_RATE=18
SAAS_SIGNUP_LIMIT=20                 # ek IP se ghante me kitne sign-up

# Khanak ke apne GST invoice (KH/26-27/0001) par
SAAS_SELLER_NAME=Khanak
SAAS_SELLER_GSTIN=24XXXXXXXXXXXZX
SAAS_SELLER_ADDRESS=Surat, Gujarat
SAAS_SELLER_STATE_CODE=24
SAAS_SAC_CODE=998314
SAAS_INVOICE_PREFIX=KH

# Login details / receipt / reminder email
SAAS_SMTP_HOST=smtp.hostinger.com
SAAS_SMTP_PORT=465
SAAS_SMTP_SECURE=ssl
SAAS_SMTP_USER=hello@khanak.in
SAAS_SMTP_PASS=xxx
SAAS_MAIL_FROM=hello@khanak.in
SAAS_MAIL_FROM_NAME=Khanak
SAAS_SUPPORT_PHONE=+91 98xxxxxxx
SAAS_SUPPORT_EMAIL=support@khanak.in
```

`DB_USER` ko `CREATE DATABASE` + `khanak_%` databases par poori permission chahiye:
`GRANT ALL ON \`khanak\_%\`.* TO 'khanak'@'localhost';`

### 2. Control database + super admin (ek baar, SSH)

```
cd ~/…/public_html/api
php saas/cli/setup.php --admin-email=you@khanak.in --admin-password='Strong@Pass1' --admin-name="Nirav"
```

Super admin console: **https://app.khanak.in/khanak-admin** — revenue (MRR/ARR), dukaanein, din jodna,
plan badalna, cash/bank payment entry + invoice, dukaan hold / chalu, payments, plans ke daam.

### 3. Razorpay

Dashboard → Settings → **API Keys** (key id + secret `.env` me) → **Webhooks** → Add:
URL `https://app.khanak.in/api/saas/webhook.php`, event **payment.captured**, secret = `SAAS_RAZORPAY_WEBHOOK_SECRET`.
Webhook isliye zaroori hai ki customer payment ke baad page band kar de tab bhi dukaan ban jaaye aur email jaaye.

### 4. Cron (har minute) — purane cron ki jagah

```
* * * * * /usr/bin/php /home/<user>/…/public_html/api/saas/cli/cron-all.php
```

Ye har dukaan ke offers / automatic messages chalata hai, aur din me ek baar plan khatam hone ke reminder
(7, 3, 1 din pehle aur 1 din baad) bhejta hai.

### 5. Naya version upload karne ke baad

Sab dukaanon ke database ek saath update:

```
php saas/cli/migrate-all.php
```

### Dukaan ka flow (customer ki taraf se)

1. **/pricing** → plan chuno → *Buy* ya *Start free trial*.
2. **/signup** → dukaan ka naam, malik, mobile, email, shop code (naam se apne aap ban jaata hai).
3. Payment (UPI / card / net banking) → **Your shop is ready!** — shop code, email, password (copy button) +
   wahi email me. Khanak ka GST invoice bhi email me.
4. **Log in to Khanak** → shop code pehle se bhara → dashboard. Andar **Admin → Plan & billing** me plan,
   users/stores ka use, renew / upgrade (Razorpay), aur sab invoices.

### Dhyan rakho

* **Google Drive backup**: har dukaan ka callback address alag hai (`/t/<shop>/api/...`), isliye Google Cloud me
  OAuth redirect URI har dukaan ke liye jodna padta hai — abhi Drive backup sirf un dukaanon ke liye on karo
  jinka URI joda ho. Local backup / restore sab ke liye chalta hai.
* `SAAS_MASTER_KEY` badla to sab dukaanon ke login token aur saved secrets bekaar ho jaate hain.
* Email set nahi hai to login details sirf screen par dikhte hain (admin console me *email_skipped* aata hai).

---

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
