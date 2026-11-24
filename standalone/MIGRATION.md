# Checklist — adăugarea CMP pe o pagină statică

Pași de urmat pentru fiecare pagină HTML (fiecare limbă, fiecare LP).

## 1. Adaugă loader-ul (o singură dată per pagină, în `<head>`)

```html
<!-- BiscuitWP -->
<link rel="stylesheet" href="https://rt.md/cmp/cmp.css">
<script>
window.CMP_CONFIG = {
  site: "DOMENIUL.md",          // ex. velfruct.md
  mode: "pro",
  preset: "egov",
  position: "modal-center",
  lang: "ro",                   // ← "ro" / "ru" / "en" după pagină
  privacyUrl: "/politica-confidentialitate.html",  // ← linkul TĂU real
  categories: ["necessary","statistics","marketing"]
};
</script>
<script src="https://rt.md/cmp/cmp.js" defer></script>
```

Ajustează pe fiecare pagină: **`lang`** (ro/ru/en) și **`privacyUrl`**.

## 2. Marchează TOATE scripturile-tracker

Regula de aur: **fiecare** script al unui tracker se marchează — atât loader-ul
`<script src>`, cât și blocul inline (`gtag('config')`, `fbq('init')`, etc.).
Dacă lași unul singur nemarcat, trackerul tot pornește.

Categorii: **GA / GTM → `statistics`** · **Meta Pixel / Ads → `marketing`**.

### Google Analytics (gtag) — exemplu
**Înainte:**
```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXX');
</script>
```
**După:**
```html
<script type="text/plain" data-cmp-block="statistics" data-cmp-enc="1"
        data-cmp-src="BASE64_AL_URL-ULUI_gtag"></script>
<script type="text/plain" data-cmp-block="statistics">
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXX');
</script>
```
Base64-ul URL-ului îl generezi cu `marker-generator.html` (sau
`echo -n "URL" | base64`).

### Meta Pixel — exemplu (doar dacă îl folosești)
**După:**
```html
<script type="text/plain" data-cmp-block="marketing">
  !function(f,b,e,v,n,t,s){...}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
  fbq('init','ID_REAL');
  fbq('track','PageView');
</script>
```
Nu folosi `fbq('init','…')` cu „…" — pune ID-ul REAL, sau șterge blocul.

## 3. Verifică (F12 → Console)

```js
(function(){var c=window.CMP;console.table({
 'cmp.js': !!document.querySelector('script[src*="cmp.js"]'),
 'CMP_CONFIG': !!window.CMP_CONFIG,
 'window.CMP': typeof c==='object',
 'scripturi marcate': document.querySelectorAll('script[type="text/plain"][data-cmp-block]').length,
 'GA ruleaza ACUM (ar trebui false pana la accept)': typeof window.google_tag_manager!=='undefined',
 'tracker nemarcat care ruleaza': !!document.querySelector('script[src*="googletagmanager.com/gtag/js"]:not([type="text/plain"]),script[src*="connect.facebook.net"]:not([type="text/plain"])')
});})();
```

**Corect =** `cmp.js`/`CMP_CONFIG`/`window.CMP` true · „scripturi marcate" ≥ 1 ·
„GA rulează ACUM" **false** înainte de accept · „tracker nemarcat" **false**.
După ce accepți Statistici și reîncarci, „GA rulează ACUM" devine true.

## Greșeli frecvente
- Ai marcat loader-ul dar ai uitat blocul inline `gtag('config')` → GA tot pornește.
- Ai lăsat GA pe `marketing` în loc de `statistics`.
- Ai pus loader-ul CMP dar ai uitat `window.CMP_CONFIG` → nu apare bannerul.
- Ai lăsat placeholder-ul `fbq('init','…')` din exemplu.
- Cache: reîncarcă cu Ctrl+Shift+R după modificări.
