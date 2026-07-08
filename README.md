# Consent Manager Pro

Plugin WordPress pentru consimțământ cookie conform GDPR, generare pagini legale și registru de consimțăminte. Theme-agnostic, fără jQuery, fără dependențe externe.

- **Autor:** Sergiu Pascaru — [rt.md](https://rt.md)
- **Licență:** GPL-2.0-or-later
- **Necesită:** WordPress ~6.x, PHP 7.4+

## Ce face

- **Banner de consimțământ** — modal centrat cu overlay + blur, scroll blocat până la decizie. Poziție/preset configurabile, texte editabile din admin.
- **Google Consent Mode v2** nativ — emite `gtag('consent', 'default'/'update', …)` din maparea categorie → semnale, înainte ca Site Kit/GTM să tragă tag-urile.
- **Categorii dinamice** — N categorii (Necesare/Statistici/Marketing implicit), fiecare cu cookies (nume/furnizor/durată/scop) și semnale Consent Mode.
- **Registru consimțăminte (audit GDPR)** — logare cu dedup 24h, hash SHA256 IP + salt, retenție pe cron, export CSV (BOM) / JSON, purge.
- **Wizard pagini legale** — generează Politica de confidențialitate / Termeni și condiții / Politica de cookie-uri ca pagini WP; integrare cu pagina oficială de confidențialitate WordPress și Privacy Policy Guide.
- **DNT / GPC** — respectă `Do-Not-Track` / `Sec-GPC`: refuză automat cookie-urile opționale fără a mai afișa banner-ul.
- **Multilingv** — WPML / Polylang (traducere stringuri + duplicare pagini legale) + i18n nativ (`.pot`).

## Instalare

Copiază folderul `consent-manager-pro/` în `wp-content/plugins/` și activează-l din **Plugins**. Tabelul de audit și categoriile implicite se creează la activare.

Configurare în **WP Admin → Consent Manager** (link „Setări" direct în lista de plugin-uri).

## Configurare

- **Setări** — poziție banner, preset, versiune politică, retenție log, mapare pagini legale, **texte banner**.
- **Categorii** — CRUD categorii + cookies + semnale Consent Mode.
- **Registru consimțăminte** — listă filtrabilă, sumar, export, purge.
- **Pagini legale** — wizard de generare.

> **Versiune politică:** incrementeaz-o când schimbi ce cookie-uri/scopuri folosești — cookie-urile de consimțământ mai vechi devin expirate și banner-ul reapare pentru reconfirmare (cerință GDPR la schimbarea scopului).

## API pentru dezvoltatori

### PHP

```php
CMP_Consent::is_allowed( 'statistics' );  // bool — gating server-side
CMP_Consent::get_categories();            // categoriile configurate
CMP_Consent::get_consent();               // decizia curentă sau null

do_action( 'cmp_consent_updated', $categories, $decision );  // la logare
do_action( 'cmp_legal_page_created', $post_id, $type );      // după generare pagină legală
apply_filters( 'cmp_render_banner', $html );
```

### JavaScript

```js
CMP.isAllowed('statistics');   // bool, din cookie
CMP.getConsent();              // obiectul de consimțământ
CMP.openPreferences();         // deschide panoul de preferințe
document.addEventListener('cmp:consent', e => e.detail.categories);
```

### Shortcode-uri

```
[cmp_privacy_link]           Link către Politica de confidențialitate
[cmp_terms_link]             Link către Termeni și condiții
[cmp_cookie_settings_link]   Buton „Setări cookie-uri" (redeschide preferințele)
```

## Dezvoltare

Logica pură (evaluare consimțământ, semnale, sanitizare, audit, i18n, legal) are teste PHP standalone, fără PHPUnit/Composer:

```bash
php tests/run.php
```

## Licență

GPL-2.0-or-later.
