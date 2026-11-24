# Changelog

BiscuitWP (fost Consent Manager Pro). Fiecare versiune are o secțiune `## X.Y.Z – AAAA-LL-ZZ`; `bin/release.sh` ia de aici notele din manifestul de update (ultimele 5 versiuni, text simplu).

## 1.1.1 – 2026-11-24

- Butonul de mutare merge și când pluginul stă într-un folder cu alt nume (de exemplu consent-manager-pro-main).

## 1.1.0 – 2026-11-24

- Pluginul se numește acum BiscuitWP (fost Consent Manager Pro).
- Setările, datele, cookie-urile, shortcode-urile și API-ul rămân aceleași.
- Site nou: biscuitwp.rt.md. Codul e pe github.com/rtdesignstudio/biscuitwp.
- Update-urile vin acum de pe biscuitwp.rt.md.
- Vechea adresă, consent.rt.md, duce la biscuitwp.rt.md.
- Opțiune nouă în Setări: „Respectă semnalul Global Privacy Control (GPC)”.
  E pornită implicit, deci totul merge ca până acum.
- Densitatea bannerului, în Aspect: Compact, Normal sau Extins.
  Normal arată exact ca înainte.
- Buton nou: „Mută pluginul în folderul biscuitwp”.
- Mutarea e opțională. Nimic nu se mută singur.
- Pluginul merge la fel de bine din folderul vechi, consent-manager-pro.
- Cum muți: Plugins, rândul BiscuitWP, „Mută pluginul în folderul biscuitwp”.
  Bifezi confirmarea și apeși butonul. Pe multisite, din administrarea rețelei.
- Pluginul nu se dezactivează. Setările, registrul și paginile legale rămân.
- După mutare, golește cache-ul paginilor și verifică bannerul pe site.
- Text domain nou: `biscuitwp`.
- Traducerile proprii din wp-content/languages/plugins/consent-manager-pro-*.mo
  nu se mai aplică. Redenumește-le în biscuitwp-*.mo (și .po).
- Două arhive: consent-manager-pro.zip pentru update-uri,
  biscuitwp.zip pentru instalări noi.
- Ai deja pluginul? Actualizează-l. Nu instala biscuitwp.zip alături.

## 1.0.0 – 2026-11-20

- Biblioteca de servicii: blocare automată pentru analytics, reclame, chat-uri și embed-uri (YouTube, Vimeo, Google Maps), servicii personalizate, excepții și „Încarcă mereu” per embed.
- Tabelul de cookie-uri se generează din serviciile detectate și cele personalizate (`[cmp_cookie_table]`), cu date verificate la furnizori.
- Integrare opțională cu WP Consent API.
- Moduri de măsurare Strict și Advanced (GA4/GTM cu Consent Mode implicit „denied”); instrumentele de analytics fără cookie-uri sunt scutite implicit.
- Re-consimțământ țintit: vizitatorul e întrebat din nou doar pentru serviciile noi.
- Texte legale RO/RU/EN pentru Moldova (Legea 195/2024, Legea 72/2025, GDPR), cu blocuri condiționale și adresa operatorului.
- Scanare pe mai multe pagini, cu sursele și resursele care trimit IP-ul vizitatorului; ecranul Verificare testează în browser ce se încarcă înainte și după consimțământ.
- Comutatoare pentru emoji-urile WordPress și Gravatar.
- Accesibilitate WCAG 2.2 AA: contrast, focus vizibil, tasta Escape, ținte de 24 px, mișcare redusă.
- Banda de cookie-uri pe telefon pe două rânduri, cu detalii la cerere; resurse minificate sub 12 KB, fără deplasări de layout.
- Test e2e în browser real: niciun tracker nu se încarcă înainte de consimțământ, pentru fiecare serviciu din bibliotecă.
- Ștergerea completă a datelor la dezinstalare (opțională) și suport multisite.
- API JavaScript public: `onConsent`, `whenAllowed`, `allowCategory`, `denyCategory`, `version`; în PHP, `cmp_is_allowed()`.
- Administrarea tradusă în engleză și rusă.
- Ecran Ghid în admin, cu linkuri contextuale.
- Ecran Aspect: stil și culori separate, previzualizare pe site, garanții de conformitate.
- Update-uri noi: manifest semnat pe consent.rt.md, pachete pe GitHub Releases, canale stable și beta, versiuni retrase; site-ul nu mai trimite date despre el.
- Pe multisite, „Caută actualizări” apare și în panoul rețelei.
- Scanarea citește corect sitemap-urile fără namespace pe PHP 7.4.
- Plugin gratuit și open-source (GPL-2.0-or-later); pagini publice pentru securitate, confidențialitate și changelog pe consent.rt.md.
