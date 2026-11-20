# Changelog

Consent Manager Pro. Fiecare versiune are o secțiune `## X.Y.Z – AAAA-LL-ZZ`; `bin/release.sh` ia de aici notele din manifestul de update (ultimele 5 versiuni, text simplu).

## 1.0.0 – TBD

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
