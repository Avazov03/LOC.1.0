# Professional promptlar to'plami (Cursor / Claude / GPT uchun)

Foydalanish: yangi loyiha papkasini Cursor'da oching, `docs/` papkasini loyiha ichiga
(masalan `docs/design/`) ko'chiring, keyin quyidagi promptlardan birini yuboring. `{...}` joylarini
to'ldiring. Avval **0-prompt (Master)** ni bir marta yuboring yoki uni `.cursor/rules/design-dna.mdc`
qilib saqlang — shunda har bir keyingi so'rovda AI qoidalarni eslab qoladi.

---

## 0. MASTER PROMPT — dizayn DNK'sini o'rnatish (bir marta)

```text
Sen senior frontend muhandis va dizayn-tizim egasisan. Bu loyiha Zonic Admin panelining dizayn
DNK'si asosida quriladi. Quyidagi hujjatlar — qonun:
  • docs/design/01-DIZAYN-DNK.md  (tokenlar, layout, komponentlar, naqshlar, taqiqlar)
  • docs/design/02-QOLLANMA.md    (ish oqimi: yangi sahifa, mavjudini moslash, sayt)
Etalon kod: admin/js/zon-*.js, admin/css/zon-admin.css, admin/js/zon-ui.js, admin/js/zon-shell.js.

Loyiha: {LOYIHA_NOMI} — {1-2 gapda nima qiladi}.
Foydalanuvchilar: admin panel — {kimlar}; sayt — {kimlar}.
Backend: {texnologiya}, base URL {API_BASE_URL}, auth {JWT Bearer / cookie}.
Asosiy til: o'zbekcha (lotin); qo'shimcha: rus, ingliz.

QAT'IY QOIDALAR:
1. Stack o'zgarmaydi: Sneat Bootstrap 5 + vanilla JS IIFE modullar + Boxicons + Public Sans.
   React/Vue/Tailwind/boshqa UI kutubxona QO'SHMA.
2. admin/assets/vendor/* va core.css ga TEGMA. Yangi uslub faqat css/zon-admin.css oxiriga,
   prefiks bilan, faqat CSS tokenlar (var(--bs-*)) va color-mix() orqali. Qattiq hex yo'q.
3. Har yangi bo'lim = 1 HTML qobiq (mavjud app-zon-*.html nusxasi, faqat <title> va modul
   script almashadi) + 1 JS modul (ZonUI.ready → ZonUI.root() → innerHTML → load()).
4. Avval mavjud komponentni qidir (Sneat klassi, ZonUI helper, zon-* klass). Faqat yo'q bo'lsa yangisini yarat.
5. Har sahifada 4 holat: loading (zon-skel), empty, error (alert / modalAlert), 401 (ZonUI.authFail).
6. Har foydalanuvchi matni ZonUI.esc() dan o'tadi. innerHTML ga xom ma'lumot qo'yma.
7. Light + dark rejim, mobil/planshet/desktop, prefers-reduced-motion — hammasi ishlashi shart.
8. Menyu faqat zon-shell.js dagi WORK massivi orqali.
9. Kod uslubi etalonga o'xshasin: ES5-uslub IIFE, var, qisqa funksiyalar, izoh kam va faqat
   kod ko'rsata olmaydigan cheklov uchun.
10. Sirlarni (parol, kalit, .env) kodga yozma.

Ishni boshlashdan oldin: etalon fayllarni o'qi, so'ng qisqa reja ber (qaysi fayllar, qaysi
naqsh, qaysi mavjud komponentlar qayta ishlatiladi). Tugatgach: o'zgargan fayllar ro'yxati va
02-QOLLANMA.md dagi 10-bo'lim tekshiruv ro'yxati bo'yicha natija.
```

---

## 1. Loyihani boshlash — rebrend va skelet

```text
Master qoidalarga amal qilib, loyihani {LOYIHA_NOMI} uchun tayyorla:
1. starter/rebrand.py ni ishlat: --name "{To'liq nom}" --short "{Qisqa}" --prefix {prefix} --team "{Jamoa}".
2. brand-override.css ni ula, primary rang = {#HEX}. Kontrastni tekshir (≥4.5:1 oq fonda).
3. app-config.js: API_BASE_URL = {URL}. api.js dagi adminLogin/adminMe/adminLogout ni
   backendimga moslashtir: {endpointlar va javob formati}.
4. zon-shell.js WORK menyusini quyidagicha qil: {bo'limlar ro'yxati: nom — ikon — fayl}.
5. Har bir bo'lim uchun bo'sh qobiq sahifa va module-template.js asosida modul yarat
   (hozircha CONFIG bilan, endpointlar keyin ulanadi).
6. Login sahifasi va dashboard brend bilan to'g'ri ko'rinishini tekshir (light/dark).
Kodni yozishdan oldin reja ko'rsat.
```

---

## 2. YETISHMAYOTGAN sahifani noldan yaratish

```text
Master qoidalar asosida yangi bo'lim yarat: "{Bo'lim nomi}".
Maqsad: {admin bu sahifada nima qiladi — 2-3 gap}.
API:
  GET    {endpoint}           → {javob namunasi JSON}
  POST   {endpoint}           ← {body}
  PUT    {endpoint}/{id}      ← {body}
  DELETE {endpoint}/{id}
Naqsh: {CRUD | Profil | Analitika | Dashboard | Xarita} (01-DIZAYN-DNK.md 7-bo'lim).
Etalon sifatida {masalan js/zon-news.js} ning tuzilishi va UX'ini nusxala.
Kerakli elementlar:
  • KPI qatori: {qaysi 4 ko'rsatkich}
  • Filtrlar: {nav-pills / zon-seg / select / qidiruv}
  • Jadval ustunlari: {ro'yxat}
  • Modal forma maydonlari: {ro'yxat, majburiylari bilan}
  • Maxsus: {masalan rasm yuklash, holat badge ranglari, ommaviy amal}
Fayllar: admin/app-{prefix}-{slug}.html (qobiq), admin/js/{prefix}-{slug}.js, WORK menyusiga qo'sh.
Yangi CSS kerak bo'lsa — zon-admin.css oxiriga, tokenlar bilan, dark + reduced-motion bloklari bilan.
```

---

## 3. MAVJUD shablon sahifasini dizaynga moslash

```text
Master qoidalar asosida admin/{shablon-fayl.html} (Sneat demo sahifasi) ni real bo'limga aylantir:
"{Bo'lim nomi}" — {vazifasi}.
1. Shablondagi qaysi bloklar kerak, qaysilari keraksiz — avval ro'yxat ber.
2. Kerakli bloklarning HTML tuzilishi va klasslarini O'ZGARTIRMASDAN yangi modulga ko'chir
   (admin/js/{prefix}-{slug}.js), demo ma'lumotni {endpoint} dan keladigan real ma'lumot bilan almashtir.
3. Shablonning o'z assets/js/app-*.js fayliga bog'lanma — kerakli logikani modulga ko'chir.
4. Inglizcha matnlarni o'zbekchaga o'tkaz.
5. Yangi qobiq sahifa app-{prefix}-{slug}.html (app-zon-news.html nusxasi) yarat, menyuga qo'sh.
6. Asl shablon faylini o'chirma va o'zgartirma — u katalog sifatida qoladi.
Natijada sahifa etalon zon-* sahifalari bilan yonma-yon qo'yilganda farq qilmasligi kerak.
```

---

## 4. Mavjud sahifani yaxshilash (boyitish)

```text
Master qoidalar asosida admin/js/{modul}.js sahifasini boyit. Dizayn DNK'si o'zgarmasin.
Hozirgi muammolar / istaklar: {ro'yxat}.
Qo'shilsin: {masalan: davr filtri zon-seg bilan, CSV eksport, trend grafik .zon-trend bilan,
qatorni bosganda .zon-reveal accordion tafsilot, bo'sh holat illyustratsiyasi}.
Avval etalonlarda o'xshash yechim bormi — qidir (zon-leaderboard, zon-market-stats, zon-dashboard)
va o'shani qayta ishlat. O'zgarishlarni minimal diff bilan qil.
```

---

## 5. Ommaviy SAYT (front) qurish

```text
Master qoidalar asosida {LOYIHA_NOMI} uchun ommaviy sayt qur. Admin bilan bir xil DNK (core.css,
brand-override.css, Public Sans, Boxicons, tokenlar).
Asos: admin/front-pages/landing-page.html (+ pricing-page, help-center-* kerak bo'lsa).
Sahifalar: {Bosh sahifa, Xizmatlar, Narxlar, Yangiliklar, Aloqa, ...}.
Talablar:
  • Fayllar site/ papkada, assets admin bilan umumiy.
  • Cloudflare /cdn-cgi/ skript qoldig'i, "Buy now", themeselection havolalari olib tashlansin.
  • Hero: {sarlavha, izoh, CTA}; xususiyatlar: {6 ta}; narxlar: {rejalar}; FAQ: {savollar}.
  • Dinamik bloklar (yangiliklar, bannerlar) — api.js orqali public endpoint {endpoint}.
    Admin'da yaratilgan kontent saytda chiqsin.
  • SEO: title, meta description, og:*, semantik teglar, lang="uz".
  • Light/dark, mobil-first, Lighthouse ≥ 90 (performance, a11y).
Klass nomlari va bo'lim tuzilmasi shablondagicha qolsin — faqat kontent va brend o'zgarsin.
```

---

## 6. Dizayn auditi — DNK'dan chetlanishlarni topish

```text
Loyihani 01-DIZAYN-DNK.md ga nisbatan audit qil (faqat o'qi, hech narsani o'zgartirma):
1. Qattiq yozilgan hex ranglar (DNK istisnolaridan tashqari) — fayl:qator.
2. vendor/core fayllarga kiritilgan o'zgarishlar (git diff bilan).
3. Prefikssiz yangi CSS klasslar, inline dizayn style'lar.
4. esc() siz innerHTML'ga tushayotgan ma'lumotlar.
5. Loading/empty/error/401 holati yo'q sahifalar.
6. prefers-reduced-motion siz animatsiyalar; dark rejimda ko'rinmay qoladigan elementlar.
7. Menyuda bor, lekin modul yo'q (yoki aksincha) sahifalar.
Natija: jiddiylik bo'yicha saralangan jadval + har biri uchun aniq tuzatish taklifi.
```

---

## 7. Qisqa "kundalik" prompt (har kichik vazifa uchun)

```text
DNK qoidalari (docs/design/01-DIZAYN-DNK.md) bo'yicha: {vazifa}.
Mavjud komponent/etalonni qayta ishlat, yangi kutubxona qo'shma, light/dark va mobilda tekshir.
```

---

## Cursor rule sifatida saqlash (tavsiya)

`.cursor/rules/design-dna.mdc` fayli yarating:

```markdown
---
description: Zonic dizayn DNK'si — admin va sayt uchun majburiy qoidalar
alwaysApply: true
---
- Dizayn qonuni: docs/design/01-DIZAYN-DNK.md. Ish oqimi: docs/design/02-QOLLANMA.md.
- Stack: Sneat Bootstrap 5 + vanilla JS IIFE + Boxicons + Public Sans. Yangi UI kutubxona yo'q.
- vendor/* va core.css ga tegilmaydi; yangi CSS — zon-admin.css oxiriga, var(--bs-*) va color-mix bilan.
- Yangi bo'lim = app-*.html qobiq + js/<prefix>-*.js modul (ZonUI.ready → ZonUI.root()).
- Har sahifada loading/empty/error/401; matn ZonUI.esc() orqali; light+dark+mobil+reduced-motion.
- Menyu faqat zon-shell.js WORK massivida.
```
