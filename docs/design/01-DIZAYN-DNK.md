# Zonic Admin — Dizayn DNK (Design System Spec)

Bu hujjat Zonic Admin panelining **o'zgarmas dizayn genlarini** ta'riflaydi. Yangi loyiha
(admin panel yoki ommaviy sayt) shu qoidalarga amal qilsa — u Zonic bilan "bir oiladan" bo'lib
ko'rinadi. Har qanday yangi sahifa, komponent yoki AI tomonidan yaratilgan kod shu hujjat
bilan tekshiriladi.

---

## 1. Poydevor (Foundation)

| Qatlam | Nima | Qayerda |
|---|---|---|
| Shablon | **Sneat Bootstrap 5 Admin** (vertical-menu-template, v3 uslub) | `admin/assets/vendor/*`, `admin/assets/js/*` |
| CSS yadro | `core.css` (Bootstrap 5 + Sneat tokenlari, light/dark) | `admin/assets/vendor/css/core.css` |
| Brend qatlam | `zon-admin.css` — barcha maxsus komponentlar `zon-` prefiksida | `admin/css/zon-admin.css` |
| JS qatlam | Vanilla JS IIFE modullar, global `window.ZonApi`, `window.ZonUI` | `admin/js/*.js` |
| Ikonlar | **Boxicons** (`icon-base bx bx-*`) iconify orqali | `assets/vendor/fonts/iconify-icons.css` |
| Shrift | **Public Sans** 300/400/500/600/700 — lokal woff2 (internet shart emas) | `assets/vendor/fonts/public-sans/` |
| Kutubxonalar | jQuery, Popper, Bootstrap JS, Select2, Perfect Scrollbar, ApexCharts, Leaflet, SweetAlert2 | `assets/vendor/libs/*` |

**Oltin qoida:** `core.css` va `assets/vendor/*` ga **hech qachon qo'l tegizilmaydi**. Barcha
o'zgarish faqat brend qatlamida (`css/<prefix>-admin.css`, `js/<prefix>-*.js`) bo'ladi. Shu tufayli
shablonni yangilash yoki boshqa loyihaga ko'chirish xavfsiz.

---

## 2. Rang tokenlari

Ranglar **hech qachon hex bilan qattiq yozilmaydi** — doim CSS o'zgaruvchilari orqali. Shunda dark
mode va brend rangini almashtirish avtomatik ishlaydi.

| Token | Light | Dark | Ma'nosi |
|---|---|---|---|
| `--bs-primary` | `#696cff` | `#696cff` | Asosiy brend (tugma, aktiv menyu, link, grafik) |
| `--bs-secondary` | `#8592a3` | | Neytral / ikkinchi darajali |
| `--bs-success` | `#71dd37` | | Muvaffaqiyat, "faol", o'sish |
| `--bs-info` | `#03c3ec` | | Ma'lumot, qadamlar |
| `--bs-warning` | `#ffab00` | | Ogohlantirish, yutuqlar, hudud |
| `--bs-danger` | `#ff3e1d` | | Xato, o'chirish, pasayish |
| `--bs-body-bg` | `#f5f5f9` | `#232333` | Sahifa foni |
| `--bs-paper-bg` | `#fff` | `#2b2c40` | Karta / modal / menyu foni |
| `--bs-heading-color` | `#384551` | `#d5d5e2` | Sarlavhalar |
| `--bs-secondary-color` | kulrang | | Yordamchi matn, izohlar |
| `--bs-border-color` | och kulrang | | Chegaralar |

### Rang ishlatish formulalari (DNK'ning eng muhim qismi)

```css
/* Yumshoq fon (tile, chip, hover) — rangning 6–14% i */
background: color-mix(in sRGB, var(--bs-primary) 8%, transparent);

/* Neytral yumshoq fon */
background: color-mix(in sRGB, var(--bs-heading-color, #5d596c) 7%, transparent);

/* Hover chegarasi */
border-color: color-mix(in sRGB, var(--bs-primary) 45%, transparent);
```

Bootstrap/Sneat tayyor klasslari: `bg-label-primary|success|info|warning|danger|secondary`
(yumshoq fon + to'q matn) — badge va ikon "avatar"larida doim shu ishlatiladi.

Medal ranglari (reyting): oltin `#f5b50a`, kumush `#a8aab5`, bronza `#cd7f32`.
Heatmap: `#9be9a8 → #40c463 → #30a14e → #216e39` (GitHub uslubi).

---

## 3. Tipografiya

- Oila: `Public Sans`, zaxira: system-ui stack.
- Sarlavhalar: `h4` (stat qiymatlari), `h5.card-title` (karta sarlavhasi), `h6` (kichik blok).
- Asosiy matn: `0.9375rem`; kichik: `0.8125rem`; mikro (label, oy nomi): `0.6875rem`.
- Og'irliklar: 400 matn, 500 nom/qiymat, 600 ism/sarlavha/aktiv menyu, 700 medal va katta raqam.
- Uppercase faqat guruh sarlavhasida (`letter-spacing: 0.04–0.06em`, `0.6875rem`).
- Raqamlar: `toLocaleString("uz-UZ")` (`ZonUI.n()`), sana `YYYY-MM-DD`, vaqt `YYYY-MM-DD HH:mm`.

---

## 4. Shakl, soya, harakat

| Element | Qiymat |
|---|---|
| Input/tugma radius | `0.375rem` |
| Kichik blok / dropdown | `0.5rem` |
| Tile / karta ichidagi bo'lak | `0.6–0.85rem` |
| Katta modal | `1rem` |
| Chip / pill | `999px` / `1rem` |
| Soya (yengil) | `0 0.25rem 0.75rem rgba(34,48,62,0.08)` |
| Soya (popup) | `0 0.5rem 1.25rem rgba(34,48,62,0.16–0.18)` |
| Soya (modal/cmdk) | `0 1rem 3rem rgba(34,48,62,0.3)` |
| Easing (ochilish) | `cubic-bezier(0.22, 1, 0.36, 1)` 0.28–0.38s |
| Hover | `0.15–0.2s`, `translateY(-2px/-3px)` yoki `translateX(3px)` strelka |
| Sahifa almashuvi | View Transitions API, 160ms crossfade; menyu va navbar joyida qoladi |

**Har bir animatsiya** `@media (prefers-reduced-motion: reduce)` ichida o'chiriladi — bu majburiy.

---

## 5. Layout skeleti (har sahifada bir xil)

```
<html class="layout-navbar-fixed layout-menu-fixed layout-compact"
      data-assets-path="assets/" data-template="vertical-menu-template" data-bs-theme="light">
 head:  helpers.js → zon-theme-boot.js (FOUC'siz tema) → config.js → core.css → zon-admin.css
 body:
  .layout-wrapper.layout-content-navbar
    .layout-container
      aside#layout-menu.layout-menu.menu-vertical       ← chap menyu (zon-shell.js to'ldiradi)
      .layout-page
        nav#layout-navbar                               ← qidiruv (Ctrl+K), til, tema, avatar
        .content-wrapper
          .container-xxl.flex-grow-1.container-p-y      ← SAHIFA ILDIZI (ZonUI.root())
          footer.content-footer
    .layout-overlay.layout-menu-toggle
 scripts: jquery → popper → bootstrap → perfect-scrollbar → menu.js → select2 → main.js
          → zon-i18n.js → app-config.js → api.js → zon-ui.js → <sahifa-moduli>.js → zon-shell.js
```

Muhim mexanizmlar:

1. **Sahifa ildizi yashirin boshlanadi** — `.container-xxl...:not([data-zon-ready]) { visibility:hidden }`.
   Modul `ZonUI.root()` chaqirganda `data-zon-ready="1"` qo'yiladi va demo kontent o'rniga real
   kontent chiqadi. Natija: hech qachon "demo miltillashi" yo'q.
2. **Menyu kod orqali** — `zon-shell.js` dagi `WORK` massivi yagona manba. Shablonning eski menyu
   elementlari CSS bilan yashiriladi (o'chirilmaydi), shuning uchun shablon sahifalari URL orqali ochiq.
3. **Auth guard** — token yo'q/eskirgan bo'lsa `auth-login-basic.html` ga qaytaradi; 401 → logout.
4. **Tema** — `light | dark | system`, `localStorage` da, `<head>` da oldindan qo'llanadi.
5. **i18n** — `uz` (asosiy), `ru`, `en`; `data-zon-i18n="key"` atributlari.

---

## 6. Komponentlar katalogi

### 6.1 Sneat'dan olingan (tayyor, qayta ishlatiladi)
Karta (`.card`, `.card-header`, `.card-body`), jadval (`.table.table-hover` + `.table-responsive`),
`nav-pills` filtrlari, modal (`.modal-dialog-centered`), badge (`bg-label-*`), avatar
(`.avatar > .avatar-initial.rounded.bg-label-*`), alert, dropdown, form-control/select, wizard,
timeline, ApexCharts grafiklar, Leaflet xarita, SweetAlert2 tasdiqlash.

### 6.2 Zon qatlamida yaratilgan (DNK kengaytmasi)

| Komponent | Klass | Vazifasi |
|---|---|---|
| Stat karta | `ZonUI.statCard(title, value, sub, icon, tone)` | 4 ta ustunli KPI qatori |
| Silliq ochilish | `.zon-reveal` + `.is-shown` (grid-rows 0fr→1fr) | accordion, filtr, forma bo'limlari |
| Segment tugma | `.zon-seg` | metrika/davr almashtirgich, ichida `.zon-seg-count` |
| Chip | `.zon-chip(.is-run/.is-step/.is-terr)` | kichik ko'rsatkichlar |
| Tile | `.zon-daymodal-tile(.is-success/...)`, `.zon-ep-tile` | modal/sahifa ichidagi mini-KPI |
| Ro'yxat qatori | `.zon-dayuser` (+ `-name`, `-sub`, `-stats`, `-go`) | odam/obyekt qatori, hover'da strelka |
| Reyting | `.zon-lb-podium`, `.zon-lb-rank.is-1/2/3`, `.zon-lb-bar` | TOP-3 podium + progress |
| Top-5 | `.zon-top5` | ixcham reyting kartalari |
| Trend grafik | `.zon-trend`, `.zon-trend-col/bar/x` | kutubxonasiz CSS ustun grafik |
| Heatmap | `.zon-heat-*` | yillik faollik kalendari |
| Delta | `.zon-ms-delta.is-up/.is-down` | % o'zgarish belgisi |
| Profil hero | `.zon-prof-cover/-avatar/-meta/-tabs/-facts` | foydalanuvchi profil sahifasi |
| Badge kartasi | `.zon-badge-grid`, `.zon-badge-card`, `.zon-item-thumb` | yutuq/mahsulot grid |
| Skeleton | `.zon-skel` | yuklanish holati (dark'da ham) |
| Bo'sh holat | `.zon-daymodal-empty`, `.zon-trend-empty` | ikon + sarlavha + izoh |
| Komanda paneli | `.zon-cmdk-*` | Ctrl+K global qidiruv |
| Select2 | `.zon-select2-dropdown` | animatsiyali select |
| Avatar | `ZonUI.userAvatarHtml()` + `hydrateAvatars()` | harf placeholder → token bilan rasm, zoom |
| Xarita pin | `.zon-map-pin` | avatar + ism Leaflet markeri |

---

## 7. Sahifa naqshlari (Page patterns)

Har yangi sahifa quyidagi 5 naqshdan biriga tushadi:

1. **Dashboard** — KPI qatori (4 × statCard) → grafik kartalar (8/4 grid) → faollik/heatmap → top ro'yxat.
2. **CRUD ro'yxat** — (ixtiyoriy) KPI qatori → karta: sarlavha + izoh + `Yangi` tugmasi → `nav-pills`
   filtr (sanoq bilan) → jadval → amal tugmalari → modal forma. Namuna: `zon-news.js`.
3. **Tafsilot/Profil** — hero (cover + avatar + meta + amallar) → tablar → 8/4 grid (asosiy + faktlar).
   Namuna: `zon-user-runs.js`, `zon-users.js`.
4. **Reyting/analitika** — filtr paneli (`zon-seg` + select + qidiruv) → podium → jadval + progress bar.
   Namuna: `zon-leaderboard.js`, `zon-market-stats.js`.
5. **Xarita** — chap xarita (Leaflet, dark'da filtr) + o'ng ro'yxat/rank. Namuna: `zon-regions.js`, `zon-map.js`.

Holatlar har sahifada majburiy: **loading** (skeleton), **empty** (ikon + matn), **error** (alert,
modal ichida bo'lsa `ZonUI.modalAlert`), **401** (`ZonUI.authFail`).

---

## 8. Kod DNK'si (JS modul shartnomasi)

```js
(function () {
  var U = window.ZonUI;
  U.ready(function () {
    var root = U.root();               // demo kontentni almashtiradi + data-zon-ready
    if (!root || !window.ZonApi) return;
    root.innerHTML = "...";            // faqat Sneat + zon- klasslari
    load();
  });
  function load() {
    ZonApi.get("/Admin/Something")
      .then(render)
      .catch(function (e) { if (!U.authFail(e)) setAlert("danger", U.errMsg(e)); });
  }
})();
```

- Har bir foydalanuvchi matni `U.esc()` dan o'tadi (XSS himoya).
- ID'lar sahifa ichida `z-` prefiksi bilan (`z-body`, `z-alert`, `zFormModal`).
- Hech qanday framework/bundler yo'q — fayl ochiladi va ishlaydi.

---

## 9. Qilinmaydigan narsalar (DNK buzilishi)

- Hex rangni qattiq yozish (faqat token / `color-mix`).
- `core.css` yoki vendor fayllarni tahrirlash.
- Yangi UI kutubxona qo'shish (Tailwind, MUI, boshqa ikon to'plami) — Boxicons + Sneat yetarli.
- Inline `style` bilan dizayn (faqat dinamik o'lcham/rang uchun ruxsat).
- Animatsiyani `prefers-reduced-motion` siz qo'shish.
- Dark mode'da tekshirmasdan topshirish.
- Shablon sahifasini o'chirish (yashiriladi, katalog sifatida qoladi).
