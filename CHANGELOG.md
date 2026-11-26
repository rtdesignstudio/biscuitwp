# Changelog

BiscuitWP (fost Consent Manager Pro). Fiecare versiune are o secțiune `## X.Y.Z – AAAA-LL-ZZ`; `bin/release.sh` ia de aici notele din manifestul de update (ultimele 5 versiuni, text simplu).

## 1.2.0 – TBD

- Biblioteca de servicii are două servicii noi: Google AdSense (marketing)
  și Cloudflare Turnstile (necesar).
- AdSense așteaptă acum acordul pentru marketing. Pe site-urile unde e
  prezent, el nu mai pornește înainte de acord; după prima scanare care îl
  găsește, o notificare îți spune o singură dată, și în Servicii.
- Dacă ai un serviciu personalizat cu un domeniu sau o adresă AdSense ori
  Turnstile, el decide mai departe pentru ce e scris la el, cu categoria și cu
  „nu bloca” alese de tine. Restul rămâne la serviciul din bibliotecă. Dacă nu
  i-ai scris cookie-uri, politica le arată pe cele AdSense.
- Cookie-urile AdSense apar în tabelul de cookie-uri, din lista Google.
- Ecranul Servicii arată pluginurile recunoscute: Site Kit, MonsterInsights,
  PixelYourSite, WooCommerce și Contact Form 7, cu starea fiecăruia. Un
  plugin apare ca blocat doar dacă toate serviciile lui sunt blocate acum.
- PixelYourSite: scriptul lui rămâne în așteptare până la acord, iar
  evenimentele trimise de pe server pleacă doar cu acord. Cine acceptă doar
  marketing primește Meta, nu Google Analytics; cine acceptă doar statistici
  nu primește nimic prin el. PixelYourSite Pro nu e legat.
- Pe site-urile cu PixelYourSite, semnalele Consent Mode ajung și în
  dataLayer-ul real, nu doar în dataLayerPYS.
- Unde PixelYourSite e legat, sesiunea PHP nu mai pornește fără acord. Paginile
  pot să nu mai primească antetul no-store pe care îl punea sesiunea, deci pot
  fi păstrate în cache de server sau CDN.
- WooCommerce: când blocarea e pornită, atribuirea comenzilor (cookie-urile
  sbjs_*) pornește doar după acordul pentru marketing, iar la retragere
  cookie-urile se șterg. În modul Light rămâne oprită. Coșul,
  plata și formularele nu sunt atinse. Fără efect cu WP Consent API.
- După update, golește cache-ul paginilor: o pagină veche din cache nu are
  legăturile noi.
- Un optimizator care lipește scripturile într-un singur fișier poate ascunde
  scriptul PixelYourSite. „Verifică în browser” rămâne proba.
- Email la domenii noi: scanarea găsește domeniile terțe care încarcă scripturi
  sau iframe-uri și nu aparțin niciunui serviciu. Ele apar în Servicii, cu
  „Adaugă ca serviciu” și „Ignoră”.
- Emailul pleacă cel mult o dată la 7 zile, doar cu domenii noi; din același
  domeniu anunță cel mult 10, restul apar în Servicii. Ce e deja pe site la
  prima scanare completă (prin cron) nu se anunță. E oprit pe site-urile
  existente și pornit pe cele noi; îl pornești în Setări. O adresă greșită nu
  îl pornește.
- Aspect: export și import. Exportul descarcă aspectul salvat într-un fișier
  .json; importul îl încarcă în formular, fără să salveze nimic. Cu WPML sau
  Polylang, textele bannerului nu intră în fișier.
- Harta Google pornită de temă (Maps JavaScript API) poate aștepta acordul,
  dintr-un comutator din Servicii. Oprit pe site-urile existente, unde nu se
  schimbă nimic, și pornit la instalările noi. Fără acord, în locul hărții apare
  un chenar cu „Încarcă harta”, iar codul temei merge mai departe fără erori.
  Probează harta după pornire și golește cache-ul paginilor.
- Linkurile preload, prefetch, modulepreload și prerender spre ce e blocat se
  scot din pagină, ca dns-prefetch și preconnect. Foile de stil rămân.
- O pagină cu un script foarte lung scris în ea (peste 1 MB) nu mai iese goală.
- Imagine în banner, din Aspect: un biscuit gata făcut (cu scut sau cu WP) ori o
  imagine din biblioteca media, într-un colț de sus, ieșită puțin din banner.
  Oprită implicit. Nu acoperă textul sau butoanele, nu apare în preferințe și în
  Light și nu se descarcă pentru cine a decis deja.
- Logo în fereastra de setări, din Aspect: logo-ul temei sau o imagine din
  biblioteca media, deasupra titlului, de 32 px înălțime. Oprit implicit. Doar
  imagini de pe site-ul tău, descărcate doar când vizitatorul deschide setările.
  Nu apare în Light.
- Ecranul Aspect arată bannerul pe loc, lângă formular: desktop sau telefon,
  temă deschisă sau întunecată, bannerul sau setările. Clicurile din
  previzualizare nu salvează nimic.
- Stilul Minimal: butonul de preferințe se numește „Setări”, iar pe ecranele
  de la 576 px în sus toate butoanele stau pe un singur rând. Pe site-urile
  cu Minimal bannerul arată puțin altfel după update. Un text propriu pentru
  acest buton rămâne cum l-ai scris.
- Nota despre WP Consent API spune când ai nevoie de el.
- Politicile generate spun că, înainte de acord, pot porni și serviciile pe care
  instrumentul le arată așa (de exemplu harta, cât comutatorul e oprit).
  Regenerează paginile legale dacă vrei textul nou.

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
