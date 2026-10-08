# Qo'llanma: Zonic dizayn DNK'si asosida yangi loyiha (Admin + Sayt) qurish

Maqsad: Zonic Admin'ning dizayni va arxitekturasini **buzmasdan** yangi loyihaga ko'chirish,
yetishmayotgan sahifalarni shu uslubda yaratish va mavjud shablon sahifalarini moslashtirish.
Bitta dizayn tizimi ikki joyda ishlaydi: **admin panel** (`admin/`) va **ommaviy sayt** (`admin/front-pages/` → `site/`).

> Avval `01-DIZAYN-DNK.md` ni o'qing — bu qo'llanma o'sha qoidalarga tayanadi.

---

## 0. Arxiv tarkibi

```
Zonic-Admin-DNK-Kit/
  source/        ← Zonic Admin'ning to'liq kodi (git'dagi holat, sirlarsiz)
  docs/          ← 01-DIZAYN-DNK.md, 02-QOLLANMA.md, 03-PROMPTLAR.md
  starter/       ← rebrand.py, module-template.js, brand-override.css
```

`source/` — bu **etalon**. Uni hech qachon to'g'ridan-to'g'ri tahrirlamang; yangi loyiha uchun
nusxa oling. Etalon doim "qanday bo'lishi kerak" degan savolga javob beradi.

---

## 1. Yangi loyiha papkasini yaratish (5 daqiqa)

```powershell
# 1) Etalondan nusxa
Copy-Item -Recurse "Zonic-Admin-DNK-Kit\source\admin" "D:\Loyihalar\YangiLoyiha\admin"
Copy-Item "Zonic-Admin-DNK-Kit\starter\rebrand.py" "D:\Loyihalar\YangiLoyiha\"

# 2) Rebrend (faqat ko'rinadigan nom, prefiks va localStorage kalitlari)
cd D:\Loyihalar\YangiLoyiha
python rebrand.py --name "Nova Admin" --short "Nova" --prefix nova --team "Nova Team"

# 3) Lokal ishga tushirish
python -m http.server 8765
# brauzer: http://127.0.0.1:8765/admin/auth-login-basic.html
```

`rebrand.py` nima qiladi va nimaga tegmaydi:

| Qiladi | Tegmaydi |
|---|---|
| "Zon Admin", "Zon Team", title'lardagi brendni almashtiradi | `core.css`, vendor kutubxonalar |
| `app-config.js` da `appName`, `author`, token kalitlarini yangilaydi | Sneat klasslari, layout |
| localStorage kalitlari (`zon_admin_*` → `nova_admin_*`) — ikki panel bir brauzerda to'qnashmaydi | `zon-` CSS klass nomlari (DNK sifatida qoladi) |
| Faqat matnli fayllar (`.html .js .css .json .md .txt`) | `assets/vendor/`, rasm va shriftlar |

> CSS klasslari `zon-*` bo'lib qolishi **ataylab** — bu dizayn tizimining nomi (xuddi Bootstrap'da
> `btn-*` kabi). Faqat brend nomi o'zgaradi. Klasslarni qayta nomlash tavsiya etilmaydi: ular CSS,
> JS va HTML'da yuzlab joyda ishlatiladi va bitta xato butun sahifani buzadi.
>
> Avval `--dry-run` bilan ishga tushirib, qaysi fayllar o'zgarishini ko'ring.

---

## 2. Brend rangi va logotip (DNK'ni saqlab)

`starter/brand-override.css` ni `admin/css/` ga ko'chiring va `zon-admin.css` dan **keyin** ulang:

```html
<link rel="stylesheet" href="css/zon-admin.css" />
<link rel="stylesheet" href="css/brand-override.css" />
```

Faqat **bitta rang** almashadi — qolgan hamma narsa (hover, chip, tile, bar, grafik) `color-mix`
orqali avtomatik moslashadi, chunki DNK hex emas, token ishlatadi.

Logotip: `assets/img/favicon/favicon.ico` va menyudagi `.app-brand-logo` SVG. Brend nomi
`.app-brand-text` da.

Qoida: primary rang kontrasti oq fonda ≥ 4.5:1 bo'lsin (masalan `#696cff`, `#0d9488`, `#e11d48`, `#2563eb`).

---

## 3. Backend'ga ulash

1. `admin/js/app-config.js` → `API_BASE_URL` (lokal: to'liq URL, serverda: `/api` nginx proksi).
2. `admin/js/api.js` → login endpointlarini moslang:
   - `adminLogin` → `POST /Admin/Auth/Login` `{ userName, password }` → `{ accessToken, refreshToken }`
   - `adminMe` → `GET /Admin/Auth/Me`
   - `adminLogout` → `POST /Admin/Auth/Logout`
   Backend boshqacha bo'lsa — faqat shu 3 funksiyani o'zgartiring, qolgan modullar `ZonApi.get/post/put/del` ishlatadi.
3. Javob shartnomasi: xatoda `{ message }` yoki `{ message: [] }` qaytsin — `ZonUI.errMsg()` shuni o'qiydi.
4. Rasm: `GET /Admin/Image?fileId=` (public) va `POST /Admin/UploadImage` (multipart `file`).

---

## 4. Menyuni o'z loyihangizga moslash

Yagona manba — `admin/js/zon-shell.js` dagi `WORK` massivi:

```js
var WORK = [
  { href: "index.html",          icon: "bx-home-smile", label: "Boshqaruv" },
  { href: "app-nova-orders.html", icon: "bx-cart",      label: "Buyurtmalar" },
  { href: "app-nova-clients.html",icon: "bx-group",     label: "Mijozlar" },
];
```

Ikon nomlari: Boxicons (`bx-*`) — `admin/icons-boxicons.html` sahifasida hammasini ko'rish mumkin.
Menyu sarlavhasi matni (`"Zon Admin"`) `splitMenu()` ichida — rebrand skripti uni ham almashtiradi.

---

## 5. YETISHMAYOTGAN sahifani yaratish (asosiy ish oqimi)

Har yangi bo'lim = **1 HTML qobiq + 1 JS modul**. HTML'da dizayn yozilmaydi — u faqat qobiq.

### 5.1 HTML qobiq
```powershell
Copy-Item admin\app-zon-news.html admin\app-nova-orders.html
```
Ichida faqat 2 narsani almashtiring:
- `<title>` matni
- `<script src="js/zon-news.js?v=...">` → `<script src="js/nova-orders.js?v=1">`

(Qobiqdagi demo kontent CSS orqali yashirin — modul uni almashtiradi.)

### 5.2 JS modul
`starter/module-template.js` ni `admin/js/nova-orders.js` ga ko'chiring. U allaqachon:
KPI qatori, filtr pills (sanoq bilan), qidiruv, jadval, skeleton, bo'sh holat, xato, CRUD modal,
o'chirish tasdig'i, 401 boshqaruvini o'z ichiga oladi. Siz faqat `CONFIG` blokini to'ldirasiz:

```js
var CONFIG = {
  title: "Buyurtmalar",
  subtitle: "Barcha buyurtmalar va holatlari",
  endpoint: "/Admin/Orders",
  idField: "id",
  columns: [ { key: "number", label: "№" }, { key: "status", label: "Holat", badge: {...} } ],
  fields:  [ { key: "number", label: "Raqam", type: "text", required: true } ],
  filters: [ { id: "all", label: "Barchasi" }, { id: "paid", label: "To'langan", test: ... } ],
  stats:   function (items) { return [ ... ] },
};
```

### 5.3 Menyuga qo'shing (4-bo'lim) → tayyor.

### 5.4 Murakkab sahifa kerak bo'lsa — naqsh tanlang
| Kerak | Naqsh | Etalon fayl |
|---|---|---|
| Ro'yxat + qo'shish/tahrirlash | CRUD | `js/zon-news.js`, `js/zon-badges.js` |
| Batafsil kartochka, tablar | Profil | `js/zon-user-runs.js` |
| Reyting, TOP, filtrlar | Analitika | `js/zon-leaderboard.js`, `js/zon-market-stats.js` |
| KPI + grafik + heatmap | Dashboard | `js/zon-dashboard.js` |
| Hudud, geolokatsiya | Xarita | `js/zon-regions.js`, `js/zon-map.js` |
| Ishtirokchilar, ommaviy amal | Jadval + checkbox | `js/zon-events.js` |
| Xabar yuborish | Forma + preview | `js/zon-push.js` |

---

## 6. MAVJUD shablon sahifasini dizaynga moslash

`admin/` da ~150 ta tayyor Sneat sahifasi bor (menyuda yashirin, URL orqali ochiladi). Yangi
funksiya kerak bo'lganda **noldan chizmang** — eng yaqin shablonni toping:

| Kerak bo'lgan funksiya | Tayyor shablon sahifa |
|---|---|
| Kalendar / jadval | `app-calendar.html` |
| Chat / qo'llab-quvvatlash | `app-chat.html` |
| Kanban (vazifalar) | `app-kanban.html` |
| Hisob-faktura | `app-invoice-*.html` |
| E-commerce (mahsulot, buyurtma, mijoz) | `app-ecommerce-*.html` |
| Logistika / transport | `app-logistics-*.html` |
| Kurslar / ta'lim | `app-academy-*.html` |
| Rollar va ruxsatlar | `app-access-roles.html`, `app-access-permission.html` |
| Sozlamalar | `pages-account-settings-*.html` |
| Ko'p bosqichli forma | `form-wizard-*.html`, `wizard-ex-*.html` |
| Analitik kartalar | `cards-analytics.html`, `cards-statistics.html`, `cards-advance.html` |
| Grafiklar | `charts-apex.html` |
| Xato / texnik ishlar sahifalari | `pages-misc-*.html` |

Moslash tartibi:
1. Shablon sahifani brauzerda oching, kerakli blokni tanlang (DevTools → HTML'ni nusxalang).
2. Shu HTML'ni JS modul ichida `root.innerHTML` ga joylang — **klasslarni o'zgartirmang**.
3. Demo matn/raqamlarni API ma'lumoti bilan almashtiring, `U.esc()` dan o'tkazing.
4. Inglizcha matnlarni o'zbekchaga (yoki `data-zon-i18n` kalitiga) o'tkazing.
5. Agar shablon sahifasi o'zining `assets/js/app-*.js` faylini ishlatsa — uni ulamang; o'rniga
   kerakli qismini o'z modulingizga ko'chiring (demo JSON'ga bog'lanib qolmaslik uchun).
6. Yangi CSS kerak bo'lsa — `zon-admin.css` oxiriga **`zon-` (yoki loyiha) prefiksi bilan**, tokenlar
   orqali, dark va reduced-motion bloklari bilan qo'shing.

---

## 7. Ommaviy SAYT (front) — xuddi shu DNK bilan

`admin/front-pages/` da tayyor sayt sahifalari bor va ular **o'sha `core.css`** ni ishlatadi —
ya'ni rang, shrift, tugma, karta bir xil:

| Sayt sahifasi | Fayl |
|---|---|
| Bosh sahifa (hero, xususiyatlar, narxlar, FAQ, aloqa) | `landing-page.html` |
| Narxlar | `pricing-page.html` |
| Yordam markazi / maqola | `help-center-landing.html`, `help-center-article.html` |
| Checkout / to'lov | `checkout-page.html`, `payment-page.html` |

Tavsiya etilgan tuzilma (bitta domen, ikki qism):
```
/var/www/nova/
  index.html            ← sayt (front-pages/landing-page.html dan)
  pricing.html, help/…
  assets/  → admin/assets bilan UMUMIY (symlink yoki bitta papka)
  admin/                ← panel
```

Saytni tayyorlash qadamlari:
1. `front-pages/*.html` ni yangi `site/` ga ko'chiring, `../assets/` yo'llarini moslang.
2. **Majburiy tozalash:** sahifa oxiridagi `/cdn-cgi/challenge-platform/...` (Cloudflare skript qoldig'i)
   `<script>` ni o'chiring; demo "Buy now" va themeselection havolalarini olib tashlang.
3. Hero, xususiyatlar, narxlar bloklaridagi matnni o'zingiznikiga almashtiring, klasslarni saqlang.
4. Saytdagi dinamik ma'lumot (yangiliklar, narxlar) — o'sha `api.js` orqali public endpointlardan.
5. Admin'da yaratilgan kontent (yangilik, banner) → saytda ko'rinadi: bu "admin uchun ham, sayt
   uchun ham" degan talabning to'g'ri arxitekturasi — bitta backend, bitta dizayn, ikki frontend.
6. `brand-override.css` ni saytga ham ulang — rang ikkalasida bir xil bo'ladi.

---

## 8. Ko'p tillilik

- Statik matn: elementga `data-zon-i18n="orders.title"` va `zon-i18n.js` dagi `DICTS.uz/ru/en` ga kalit.
- Dinamik (JS) matn: modulda to'g'ridan-to'g'ri o'zbekcha (etalondagi kabi) yoki `ZonI18n.t("key")`.
- Asosiy til — `uz`. Kalit topilmasa `en`, keyin kalitning o'zi chiqadi.

---

## 9. Serverga chiqarish

- Statik fayllar: nginx `root /var/www/<loyiha>`; `location /api/ { proxy_pass http://127.0.0.1:<port>/; }`
  (namuna: `source/nginx.example.conf`).
- `client_max_body_size` rasm yuklash uchun ≥ 10m.
- JS/CSS ulanishlarida `?v=YYYYMMDDx` — har deployda oshiring (kesh muammosi bo'lmasin).
- Sirlar (`.env`, kalitlar, parollar) hech qachon frontend kodiga va git'ga tushmaydi.

---

## 10. Topshirishdan oldingi tekshiruv ro'yxati

- [ ] Light **va** dark rejimda ko'rildi
- [ ] Mobil (≤576px), planshet (≤992px), desktop
- [ ] Loading skeleton, bo'sh holat, xato holati, 401 → login
- [ ] Hamma foydalanuvchi matni `U.esc()` orqali
- [ ] Hech qayerda qattiq hex rang yo'q (medal/heatmap kabi DNK istisnolaridan tashqari)
- [ ] Yangi animatsiyalar `prefers-reduced-motion` da o'chadi
- [ ] Menyu `WORK` ga qo'shildi, aktiv holat to'g'ri
- [ ] Konsolda xato yo'q, demo kontent miltillamaydi
- [ ] `?v=` versiyasi yangilandi
- [ ] Hech qanday vendor/core fayl o'zgartirilmadi
