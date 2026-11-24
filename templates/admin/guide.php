<?php
/**
 * "Ghid" admin screen (CMP_Guide::render()). $titles: anchor => section title.
 * Each paragraph is one translatable text; links and code come in through
 * placeholders and the paragraph is printed with wp_kses_post().
 *
 * @var array $titles
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmp_max_pages = class_exists( 'CMP_Scanner' ) ? (int) CMP_Scanner::MAX_PAGES : 20;

$cmp_code_attr = <<<'CMPCODE'
<script data-cmp-category="statistics" src="https://example.com/tracker.js"></script>
CMPCODE;

$cmp_code_js = <<<'CMPCODE'
window.CMP.whenAllowed('marketing', function () {
  startChat();
});
CMPCODE;

$cmp_code_php = <<<'CMPCODE'
if ( function_exists( 'cmp_is_allowed' ) && cmp_is_allowed( 'statistics' ) ) {
    // ...
}
CMPCODE;
?>
<div class="wrap cmp-admin cmp-guide">
	<h1><?php esc_html_e( 'BiscuitWP — Ghid', 'biscuitwp' ); ?></h1>
	<p><?php esc_html_e( 'Ghidul te duce de la instalare la un site care cere acord corect. Citește-l o dată de la cap la coadă, apoi revino la secțiunea de care ai nevoie.', 'biscuitwp' ); ?></p>
	<p><?php esc_html_e( 'Fiecare ecran al pluginului are sus un link „Ghid”. El te aduce direct la secțiunea despre acel ecran.', 'biscuitwp' ); ?></p>

	<nav class="cmp-guide-toc" aria-labelledby="cmp-guide-toc-title">
		<h2 id="cmp-guide-toc-title"><?php esc_html_e( 'Cuprins', 'biscuitwp' ); ?></h2>
		<ol>
			<?php foreach ( CMP_Guide::ANCHORS as $cmp_anchor ) : ?>
				<li><a href="#<?php echo esc_attr( $cmp_anchor ); ?>"><?php echo esc_html( $titles[ $cmp_anchor ] ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<?php /* 1. Start */ ?>
	<section class="cmp-guide-section">
		<h2 id="start"><?php echo esc_html( $titles['start'] ); ?></h2>
		<p><?php esc_html_e( 'Cinci pași pentru un site care cere acord corect. Fă-i în ordine.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( '%1$sSetări%2$s: alege „Mod afișare”. Pentru majoritatea site-urilor, Pro. Lasă bifat „Blochează scripturile de tracking până la consimțământ” și „Mod de măsurare” pe Strict. Apasă „Salvează setările”.', 'biscuitwp' ), 'cmp-settings' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( '%1$sServicii%2$s: uită-te la lista „Găsite pe site”. Pluginul scanează singur paginile site-ului. Dacă lista e goală, apasă „Rescanează”. Verifică să nu lipsească nimic din ce știi că folosește site-ul.', 'biscuitwp' ), 'cmp-services' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Pagini legale screen */ __( '%1$sPagini legale%2$s: completează „Datele operatorului”, apoi apasă „Generează” la fiecare document (sau „Regenerează”, dacă documentul are deja o pagină: textul ei va fi înlocuit). O pagină nouă se salvează ca ciornă: citește-o și publică-o din editor.', 'biscuitwp' ), 'cmp-legal' ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: 1: the shortcode, 2: the menu link URL */ __( 'Pune în footer linkul „Setări cookie”: shortcode-ul %1$s sau un link de meniu cu URL-ul %2$s. Prin el vizitatorul își schimbă sau își retrage acordul.', 'biscuitwp' ), CMP_Guide::code( '[cmp_cookie_settings_link]' ), CMP_Guide::code( '#cmp-settings' ) ) ); ?>
				<?php esc_html_e( 'În modul Pro, dacă linkul lipsește de pe pagina principală, ecranul Setări arată avertismentul „Lipsește linkul „Setări cookie””. În loc de link poți porni butonul plutitor din Aspect, la „Redeschidere” (doar în Pro), dar linkul din footer e varianta recomandată.', 'biscuitwp' ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen; %3$s, %4$s: link to the "Cum verifici blocarea" section */ __( '%1$sVerificare%2$s: apasă „Verifică în browser”. Nicio pagină nu trebuie să iasă roșie. Galbenul nu e o blocare ratată, dar merită citit. Dacă o pagină e roșie, citește %3$sCum verifici blocarea%4$s.', 'biscuitwp' ), 'cmp-check', '#verify' ) ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Secțiunile de mai jos explică fiecare pas pe larg.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 2. Modes */ ?>
	<section class="cmp-guide-section">
		<h2 id="modes"><?php echo esc_html( $titles['modes'] ); ?></h2>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Modurile se aleg în %1$sSetări%2$s. Sunt două alegeri: Pro sau Light („Mod afișare”), apoi, în Pro, Strict sau Advanced („Mod de măsurare”).', 'biscuitwp' ), 'cmp-settings' ) ); ?></p>

		<h3><?php esc_html_e( 'Pro sau Light', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Pro afișează un banner cu butoane de acceptare, de refuz și de alegere pe categorii. Pluginul ține minte alegerea vizitatorului și o scrie în registru. Alege Pro dacă folosești statistici, publicitate, un chat sau video încorporat.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Dacă browserul vizitatorului trimite semnalul Global Privacy Control (GPC), pluginul înregistrează automat refuzul („Doar cele necesare”) și nu mai arată bannerul. Vizitatorul poate accepta oricând din „Setări cookie”. Poți opri asta din Setări, opțiunea „Respectă semnalul Global Privacy Control (GPC)” (pornită implicit); atunci vizitatorii cu GPC văd bannerul ca toți ceilalți.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Light afișează doar o bară informativă. Nu cere acord și nu pune cookie-uri. De aceea serviciile care cer acord sunt blocate permanent: Google Analytics, Tag Manager, Meta Pixel și celelalte nu rulează deloc, în afară de excepțiile marcate de tine. Video-urile și hărțile se încarcă doar dacă vizitatorul apasă „Încarcă conținutul”.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Alege Light pentru un site de prezentare fără tracking. Dacă în Light pluginul găsește scripturi de tracking pe site, ecranul Setări te avertizează.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Strict sau Advanced', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( '„Mod de măsurare” contează doar în modul Pro, cu blocarea scripturilor pornită.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Strict: serviciile blocate nu se încarcă deloc înainte de acord. Google nu primește nimic de la vizitatorii care refuză, deci statisticile arată mai puține vizite. E alegerea recomandată.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Advanced: Google Analytics 4 și Tag Manager se încarcă de la început, cu Consent Mode pe „refuzat”. Fără acord, Google primește ping-uri fără cookie-uri și estimează vizitele celor care refuză. Ce câștigi: statistici mai complete.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'În Advanced, Google Ads (AW-), Floodlight (DC-), vechiul Universal Analytics și celelalte servicii rămân blocate până la acord. Tag Manager poate încărca și alte etichete: verifică în Tag Manager că ele respectă Consent Mode.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Ce riști: Google primește adresa IP și datele browserului înainte de acord. E o zonă gri în Moldova (Legea 72/2025, art. 116) și în UE. De aceea Advanced se activează doar după ce bifezi confirmarea de sub opțiune.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Ce spune textul legal: în Advanced, dacă pe site s-au găsit Google Analytics sau Tag Manager (sau dacă site-ul nu a fost încă scanat), Politica de cookie-uri descrie măsurarea fără cookie-uri, iar Politica de confidențialitate îi dă temeiul: interesul legitim. După ce schimbi modul, regenerează paginile legale. Ecranul Setări îți amintește printr-un mesaj.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 3. Appearance */ ?>
	<section class="cmp-guide-section">
		<h2 id="appearance"><?php echo esc_html( $titles['appearance'] ); ?></h2>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Aspect screen */ __( 'Cum arată bannerul se alege în %1$sAspect%2$s: stilul, culorile, tema, formatul și poziția, fundalul, detaliile, butoanele, textele, animația, butonul de redeschidere, paginile excluse și CSS-ul propriu. Site-urile care aveau pluginul înainte arată la fel; o salvare a ecranului nu schimbă aspectul. Fără WPML sau Polylang, textele vechi ale bannerului devin textele limbii curente a site-ului, iar celelalte limbi folosesc textele implicite până le completezi.', 'biscuitwp' ), 'cmp-appearance' ) ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Stilul dă forma (Minimal, Brand, Prietenos): colțurile, umbra, lățimea, butoanele și animația. Brand e aspectul de până acum. Când schimbi stilul, aceste valori revin la cele ale stilului nou, iar ecranul te avertizează înainte.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Culorile se aleg separat: o paletă, culorile temei sau un accent propriu. Pentru accent, ecranul arată contrastul și, sub 4,5:1, propune nuanța cea mai apropiată care se citește bine.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Tema: Luminos, Întunecat, Automat (după setarea dispozitivului) sau „Ca site-ul” (după o clasă a temei pe html sau body).', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( '„Accept” și „Refuz” arată mereu la fel, indiferent de setări și de CSS-ul propriu. Nu există acord prin derulare, iar categoriile nu sunt bifate din oficiu.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Excluderile opresc doar deschiderea automată a bannerului pe acele pagini. Blocarea rămâne activă.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'În modul Light, câmpurile care nu se aplică cardului Light sunt dezactivate.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Fără WPML sau Polylang, textele bannerului se scriu pe limbi (RO, RU, EN). Cu unul dintre ele, scrii textele de bază și le traduci în pluginul de traduceri.', 'biscuitwp' ); ?></li>
		</ul>
		<h3><?php esc_html_e( 'CSS propriu și variabile', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'CSS-ul propriu se adaugă după CSS-ul bannerului. La salvare, pluginul elimină @import, adresele spre alte site-uri și regulile care ar putea ascunde, micșora sau deosebi butoanele „Accept” și „Refuz”, apoi îți arată ce a eliminat.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1: .cmp, 2: .cmp-light, 3: example rule */ __( 'Cel mai simplu e să schimbi variabilele, pe %1$s (sau pe %2$s în modul Light), de exemplu %3$s. Variabilele disponibile:', 'biscuitwp' ), CMP_Guide::code( '.cmp' ), CMP_Guide::code( '.cmp-light' ), CMP_Guide::code( '.cmp{--cmp-radius:6px}' ) ) ); ?></p>
		<ul>
			<?php foreach ( CMP_Guide::variables() as $cmp_var => $cmp_desc ) : ?>
				<li><?php echo wp_kses_post( CMP_Guide::code( $cmp_var ) . ': ' . esc_html( $cmp_desc ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php /* 4. Services */ ?>
	<section class="cmp-guide-section">
		<h2 id="services"><?php echo esc_html( $titles['services'] ); ?></h2>

		<h3><?php esc_html_e( 'Biblioteca', 'biscuitwp' ); ?></h3>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'Pluginul recunoaște singur serviciile din bibliotecă: Google Analytics, Meta Pixel, YouTube, Clarity, Hotjar, Tawk.to, Crisp și altele. Lista completă e la „Toată biblioteca”, pe ecranul %1$sServicii%2$s.', 'biscuitwp' ), 'cmp-services' ) ); ?></p>
		<p><?php esc_html_e( '„Găsite pe site” arată serviciile din ultima scanare. Pluginul rescanează site-ul o dată pe zi și după fiecare salvare a setărilor sau a serviciilor. „Rescanează” pornește o scanare nouă pe loc.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'La fiecare serviciu poți schimba categoria de acord. Bifa „nu bloca (excepție)” îl lasă să se încarce fără acord. Folosește-o doar pentru unelte care nu pun cookie-uri și nu urmăresc vizitatorul, de exemplu statistici fără cookie-uri.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Scanarea vede doar ce e în HTML-ul paginii. Ce adaugă ulterior Google Tag Manager sau alt JavaScript trebuie controlat din Tag Manager, cu Consent Mode.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Serviciu personalizat', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Dacă un serviciu lipsește din bibliotecă, apasă „+ Adaugă serviciu” la „Servicii personalizate” și completează:', 'biscuitwp' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Nume: numele serviciului, așa cum îl văd vizitatorii.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Categorie: categoria de acord de care depinde serviciul.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Domenii (unul pe rând): pluginul blochează până la acord scripturile și iframe-urile de pe aceste domenii.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Cookie-uri: numele, durata și scopul fiecărui cookie. Apar în tabelul din Politica de cookie-uri.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Script (opțional): cod care rulează doar după acord. Câmpul apare doar pentru administratorii care au dreptul de a adăuga cod (unfiltered_html).', 'biscuitwp' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Apoi apasă „Salvează serviciile”. Domeniul site-ului tău nu poate fi domeniul unui serviciu, pentru că ar bloca site-ul însuși.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Scripturi proprii: atributul data-cmp-category', 'biscuitwp' ); ?></h3>
		<p><?php echo wp_kses_post( sprintf( /* translators: %s: the data-cmp-category attribute */ __( 'Ai un script pus în temă sau într-un plugin, pe care pluginul nu îl recunoaște? Adaugă-i atributul %s, cu slug-ul categoriei:', 'biscuitwp' ), CMP_Guide::code( 'data-cmp-category' ) ) ); ?></p>
		<pre class="cmp-guide-code"><code><?php echo esc_html( $cmp_code_attr ); ?></code></pre>
		<p><?php esc_html_e( 'Scriptul nu rulează până când vizitatorul acceptă categoria. Atributul merge la fel pe un iframe.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( sprintf( /* translators: %1$s, %2$s: link to the Categorii screen; %3$s, %4$s, %5$s: category slugs */ __( 'Slug-urile sunt pe ecranul %1$sCategorii%2$s. Implicit: %3$s, %4$s și %5$s. Cu valoarea %3$s scriptul rulează mereu. O valoare pe care pluginul nu o înțelege blochează scriptul, ca să nu scape nimic.', 'biscuitwp' ), '<a href="' . esc_url( CMP_Guide::screen( 'cmp-categories' ) ) . '">', '</a>', CMP_Guide::code( 'necessary' ), CMP_Guide::code( 'statistics' ), CMP_Guide::code( 'marketing' ) ) ); ?></p>
		<p><?php esc_html_e( 'Atributul contează doar când blocarea e activă: în Pro, cu „Blochează scripturile de tracking până la consimțământ” bifat; în Light, mereu.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( '„Încarcă mereu” la embed-uri', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Până la acord, video-urile YouTube și Vimeo, hărțile Google Maps și iframe-urile serviciilor tale apar ca un bloc cu butonul „Încarcă conținutul”. El încarcă doar acel conținut, pe pagina curentă.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Butonul „Încarcă mereu” ține minte alegerea pentru acel serviciu, pe tot site-ul, fără să accepte toată categoria. Alegerea se scrie în registru ca „Serviciu permis („Încarcă mereu”)”.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Vizitatorul retrage alegerea din „Setări cookie”, din lista serviciilor încărcate mereu. „Încarcă mereu” nu există în modul Light, pentru că Light nu pune cookie-uri.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( '„Încarcă mereu” apare doar la conținutul unui serviciu din bibliotecă sau al unui serviciu personalizat, nu și la un iframe marcat cu atributul data-cmp-category.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 5. Examples */ ?>
	<section class="cmp-guide-section">
		<h2 id="examples"><?php echo esc_html( $titles['examples'] ); ?></h2>
		<p><?php esc_html_e( 'Cazuri frecvente, fiecare cu pașii de urmat.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( '„Am Clarity (sau alt tracker) pus direct în temă”', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Problema: codul de tracking e lipit în tema site-ului, de exemplu în header.php sau într-un câmp pentru cod din setările temei. Vrei să nu ruleze înainte de acord.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'În %1$sSetări%2$s, verifică că modul e Pro și că „Blochează scripturile de tracking până la consimțământ” e bifat.', 'biscuitwp' ), 'cmp-settings' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'În %1$sServicii%2$s, apasă „Rescanează”. Clarity e în bibliotecă, deci trebuie să apară la „Găsite pe site”, cu starea „blocat până la acord”.', 'biscuitwp' ), 'cmp-services' ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: the attribute with a category */ __( 'Dacă trackerul nu apare (nu e în bibliotecă sau codul lui arată altfel), adaugă în temă, pe tag-ul lui script, atributul %s. Sau adaugă un serviciu personalizat cu domeniul lui.', 'biscuitwp' ), CMP_Guide::code( 'data-cmp-category="statistics"' ) ) ); ?></li>
			<li><?php esc_html_e( 'Dacă trackerul vine din Google Tag Manager, nu din temă, pluginul nu îl vede în pagină. Setează în Tag Manager eticheta să ceară consimțământ (Consent Mode).', 'biscuitwp' ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'Rulează %1$sVerificare%2$s. Pagina trebuie să fie verde, iar Clarity să apară doar în coloana „După „Acceptă tot””.', 'biscuitwp' ), 'cmp-check' ) ); ?></li>
		</ol>

		<h3><?php esc_html_e( '„Am un chat (Tawk.to, Crisp)”', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Problema: widgetul de chat pune cookie-uri și contactează serverele furnizorului imediat ce se încarcă. Tawk.to și Crisp sunt în bibliotecă, în categoria Marketing.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'În %1$sServicii%2$s, apasă „Rescanează” și verifică că chatul apare la „Găsite pe site”, cu starea „blocat până la acord”.', 'biscuitwp' ), 'cmp-services' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Categorii screen */ __( 'Vrei ca chatul să depindă de o categorie separată? Creeaz-o în %1$sCategorii%2$s („+ Adaugă categorie”, apoi „Salvează categoriile”). Alege-o apoi în lista de lângă serviciu și apasă „Salvează serviciile”.', 'biscuitwp' ), 'cmp-categories' ) ); ?></li>
			<li><?php esc_html_e( 'Fără acord, bula de chat nu apare. Spune-le vizitatorilor asta, de exemplu pe pagina de contact: pentru chat, trebuie acceptată categoria lui din „Setări cookie”.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Pentru un furnizor de chat care nu e în bibliotecă, adaugă un serviciu personalizat cu domeniile lui.', 'biscuitwp' ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'Rulează %1$sVerificare%2$s. Domeniile chatului nu trebuie să apară în coloana „Înainte de acord”.', 'biscuitwp' ), 'cmp-check' ) ); ?></li>
		</ol>

		<h3><?php esc_html_e( '„Am YouTube în articole”', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Problema: un video YouTube încorporat pune cookie-uri chiar dacă vizitatorul nu apasă play.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Nu schimbi nimic în articole. Cu blocarea pornită, pluginul înlocuiește singur fiecare video cu un bloc „Încarcă conținutul”. Imaginea video-ului, când există, se servește de pe site-ul tău, deci YouTube nu e contactat înainte de acord.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Vizitatorul poate încărca un singur video („Încarcă conținutul”) sau toate video-urile YouTube („Încarcă mereu”). Dacă acceptă în banner categoria lui YouTube, video-urile se încarcă direct.', 'biscuitwp' ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'YouTube e în categoria Marketing. Dacă vrei altă categorie, schimb-o la YouTube, în %1$sServicii%2$s.', 'biscuitwp' ), 'cmp-services' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'Deschide un articol cu video într-o fereastră privată și uită-te la bloc. Apoi rulează %1$sVerificare%2$s.', 'biscuitwp' ), 'cmp-check' ) ); ?></li>
		</ol>

		<h3><?php esc_html_e( '„Folosesc Google Fonts din temă”', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Problema: tema încarcă fonturile de la fonts.googleapis.com. La fiecare vizită, Google primește IP-ul vizitatorului. Soluția e să servești fonturile de pe site-ul tău.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Funcția merge doar în modul Pro. În %1$sSetări%2$s, bifează „Localizare Google Fonts” și apasă „Salvează setările”.', 'biscuitwp' ), 'cmp-settings' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen; %3$s, %4$s: link to the Verificare screen */ __( 'Apasă „Localizează fonturile”, în secțiunea Google Fonts de jos din %1$sSetări%2$s. Sau apasă „Localizează” în %3$sVerificare%4$s: butonul apare acolo când scanarea găsește Google Fonts și opțiunea „Localizare Google Fonts” e pornită.', 'biscuitwp' ), 'cmp-settings', 'cmp-check' ) ); ?></li>
			<li><?php esc_html_e( 'Pluginul descarcă fonturile găsite pe paginile scanate și pe pagina principală. Apoi trimite vizitatorii la copia de pe site, nu la Google.', 'biscuitwp' ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'În %1$sVerificare%2$s, apasă „Rescanează”. Google Fonts nu mai trebuie să apară la „Resurse care trimit IP-ul”.', 'biscuitwp' ), 'cmp-check' ) ); ?></li>
			<li><?php esc_html_e( 'Pluginul înlocuiește doar fonturile încărcate cu un tag link în HTML. Dacă tema le încarcă din CSS (@import) sau din JavaScript, ele rămân la Google. Atunci găzduiește fonturile din temă.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Dacă schimbi fonturile temei, apasă din nou „Localizează fonturile”. „Șterge fonturile localizate” te întoarce la fonturile de la Google.', 'biscuitwp' ); ?></li>
		</ol>
	</section>

	<?php /* 6. Verify */ ?>
	<section class="cmp-guide-section">
		<h2 id="verify"><?php echo esc_html( $titles['verify'] ); ?></h2>

		<h3><?php esc_html_e( 'Ecranul Verificare', 'biscuitwp' ); ?></h3>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'Ecranul %1$sVerificare%2$s face două lucruri:', 'biscuitwp' ), 'cmp-check' ) ); ?></p>
		<ul>
			<li><?php esc_html_e( '„Scanarea pe server” citește HTML-ul paginilor ca un vizitator fără cookie-uri. Arată, pe fiecare pagină, serviciile găsite, starea lor și de unde vin (temă, plugin, cod în pagină). Arată și resursele care trimit IP-ul vizitatorului: Google Fonts, Adobe Fonts, Font Awesome, CDN-uri, Gravatar, emoji WordPress.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( '„Verifică în browser” încarcă pe rând, invizibil, paginile în browserul tău. Notează ce se încarcă înainte de acord și ce apare în plus după un „Acceptă tot” simulat.', 'biscuitwp' ); ?></li>
		</ul>
		<p><?php echo esc_html( sprintf( /* translators: %d: max pages */ __( 'Se verifică cel mult %d de pagini: pagina principală, paginile legale, ultima pagină publicată de fiecare tip, adresele tale și sitemap-ul. Adaugi sau scoți pagini în „Adaugă pagini (una pe rând)” și „Exclude pagini (una pe rând)”, apoi apeși „Salvează lista”.', 'biscuitwp' ), $cmp_max_pages ) ); ?></p>
		<p><?php esc_html_e( 'Ce înseamnă culorile din raport:', 'biscuitwp' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Roșu: un serviciu care cere acord sau un domeniu terț necunoscut s-a încărcat înainte de acord. Asta trebuie reparat.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Galben: pagina folosește resurse care trimit IP-ul vizitatorului la terți, sau cookie-uri pe care pluginul nu le cunoaște. Nu e o blocare ratată, dar merită verificat.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Verde: nimic din ce cere acord nu s-a încărcat înainte de acord.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( '„nu s-a putut verifica”: pagina nu a răspuns, nu permite încărcarea într-un cadru, e pe altă origine decât adminul sau nu are bannerul. Nu înseamnă verde: verific-o manual.', 'biscuitwp' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Excepțiile, serviciile necesare și, în Advanced, Google Analytics și Tag Manager apar separat și nu schimbă culoarea.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Pentru emoji și Gravatar, bifează opțiunile din „Resurse terțe WordPress”, în %1$sSetări%2$s.', 'biscuitwp' ), 'cmp-settings' ) ); ?></p>
		<p><?php esc_html_e( 'Limitele verificării, pe scurt:', 'biscuitwp' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Rulează ca administrator logat. Pluginurile care schimbă pagina pentru utilizatorii logați pot da alt rezultat decât pentru vizitatori.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Ce apare doar după un clic sau după scroll nu e prins.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Cookie-urile puse de terți pe domeniile lor nu se pot citi. Se văd doar domeniile contactate.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'După „Acceptă tot”, cookie-urile serviciilor rămân în browserul tău, iar paginile verificate pot apărea ca vizite în statistici.', 'biscuitwp' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Lista completă e pe ecran, la „Limitele verificării”.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Verificarea manuală cu DevTools', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'DevTools sunt uneltele pentru dezvoltatori din browser. Le deschizi cu tasta F12 sau cu clic dreapta pe pagină, apoi „Inspect” („Inspectează”).', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Tab-ul Network („Rețea”) din DevTools arată fiecare cerere a paginii: fiecare fișier, script sau imagine, cu domeniul de la care vine.', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Deschide o fereastră privată (incognito) în Chrome sau Firefox. Așa pornești fără cookie-uri și fără un acord salvat. Folosește un browser fără GPC (sau oprește-l): Brave și DuckDuckGo îl trimit, iar atunci pluginul înregistrează automat refuzul și bannerul nu apare.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Deschide DevTools, mergi la tab-ul Network și bifează „Disable cache”.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Intră pe site. Nu apăsa nimic în banner.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'În câmpul de filtrare din Network, scrie pe rând domeniile serviciilor tale, de exemplu google-analytics.com, googletagmanager.com, facebook.net, clarity.ms, youtube.com. Nu trebuie să apară nicio cerere spre ele.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'În tab-ul Application (în Firefox, Storage), la Cookies, uită-te la cookie-urile site-ului. Înainte de acord nu trebuie să fie _ga, _fbp, _clck sau alte cookie-uri de urmărire.', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'Apasă „Permite tot” în banner (sau textul pe care l-ai pus tu în Setări). Acum cererile și cookie-urile serviciilor trebuie să apară. Asta arată că blocarea ține exact până la acord.', 'biscuitwp' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'În modul Advanced e normal să vezi cereri spre Google Analytics și Tag Manager înainte de acord, dar fără cookie-ul _ga.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 7. Legal */ ?>
	<section class="cmp-guide-section">
		<h2 id="legal"><?php echo esc_html( $titles['legal'] ); ?></h2>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Pagini legale screen */ __( 'Pe ecranul %1$sPagini legale%2$s, pluginul generează trei documente din șabloane: Politica de confidențialitate, Termeni și condiții și Politica de cookie-uri. Șabloanele sunt scrise pentru Moldova și există în română, rusă și engleză.', 'biscuitwp' ), 'cmp-legal' ) ); ?></p>
		<p><?php esc_html_e( 'Textele se adaptează la setările tale: modul Pro sau Light, blocarea scripturilor, modul de măsurare, durata acordului și retenția registrului.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( sprintf( /* translators: %s: the cookie table shortcode */ __( 'Politica de cookie-uri conține tabelul de cookie-uri (shortcode-ul %s). Tabelul se actualizează singur când se schimbă serviciile. Nu trebuie să regenerezi pagina pentru el.', 'biscuitwp' ), CMP_Guide::code( '[cmp_cookie_table]' ) ) ); ?></p>
		<p><?php esc_html_e( 'O pagină nouă se salvează ca ciornă. Citește-o, completeaz-o și publică-o din editor. Linkul „Politica de confidențialitate” din banner apare doar după ce pagina e publicată.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1, 2: shortcodes */ __( 'Pentru linkuri spre Politica de confidențialitate și spre Termeni și condiții (de exemplu în footer) folosește shortcode-urile %1$s și %2$s. Linkul apare doar când pagina e publicată; până atunci se vede doar numele documentului, fără link.', 'biscuitwp' ), CMP_Guide::code( '[cmp_privacy_link]' ), CMP_Guide::code( '[cmp_terms_link]' ) ) ); ?></p>
		<p><?php esc_html_e( 'Pe paginile legale legate în plugin, bannerul apare fără fundalul întunecat, ca vizitatorul să le poată citi înainte să decidă.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Ce completezi tu', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'La „Datele operatorului”: Denumire operator, Email contact, Responsabil date (DPO), Jurisdicție și Adresa. Ele apar în paginile generate.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( 'Caseta „Ce face pluginul și ce rămâne la tine” de pe ecran arată ce nu acoperă pluginul. De exemplu: registrul activităților de prelucrare, informarea pentru formulare, newsletter sau magazin, notificarea CNPDCP.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'De ce e nevoie de o revizie juridică', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Șabloanele sunt un punct de plecare, nu consultanță juridică. Pluginul nu știe ce alte date prelucrezi: formulare, comenzi, newsletter, conturi. Dă textul unui jurist să-l citească înainte de publicare.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Când regenerezi', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( '„Regenerează” înlocuiește textul paginii cu șablonul. Ce ai schimbat de mână în pagină se pierde, deci notează-ți modificările înainte.', 'biscuitwp' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'după ce schimbi „Mod afișare” (Pro sau Light) sau blocarea scripturilor;', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'după ce schimbi „Mod de măsurare” (Strict sau Advanced);', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'după ce schimbi „Memorarea consimțământului (zile)” sau „Retenție log (luni)”;', 'biscuitwp' ); ?></li>
			<li><?php esc_html_e( 'când ecranul Pagini legale spune că șabloanele au fost actualizate sau că o pagină a fost generată pentru alt mod de măsurare.', 'biscuitwp' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Regenerarea actualizează și traducerile paginii create de plugin (WPML sau Polylang). Traducerile făcute de tine nu sunt atinse.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 8. After an update */ ?>
	<section class="cmp-guide-section">
		<h2 id="update"><?php echo esc_html( $titles['update'] ); ?></h2>
		<p><?php esc_html_e( 'Pluginul se actualizează din ecranul Plugins, ca orice plugin. După un update, fă trei verificări:', 'biscuitwp' ); ?></p>
		<ol>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'Citește notificările BiscuitWP din admin. De exemplu, „Am început să blocăm până la acord: …” apare o singură dată, după actualizarea de la o versiune 0.4.x, și numește serviciile care până atunci rulau fără acord. Deschide %1$sServicii%2$s și verifică lista „Găsite pe site”: după un update, biblioteca poate recunoaște servicii noi fără să te anunțe.', 'biscuitwp' ), 'cmp-services' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Verificare screen */ __( 'Rulează %1$sVerificare%2$s, ca să vezi că paginile sunt încă verzi.', 'biscuitwp' ), 'cmp-check' ) ); ?></li>
			<li><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Pagini legale screen */ __( 'Dacă %1$sPagini legale%2$s spune că șabloanele au fost actualizate, regenerează fiecare document. Mesajul dispare după ce le regenerezi pe toate trei.', 'biscuitwp' ), 'cmp-legal' ) ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Din versiunea 1.1.0, pluginul se poate muta din folderul plugins/consent-manager-pro în plugins/biscuitwp. Pe site-urile instalate înainte nu se mută nimic singur: pluginul merge la fel de bine din folderul vechi și primește update-uri. Când vrei, deschide ecranul Plugins și apasă „Mută pluginul în folderul biscuitwp” pe rândul BiscuitWP (pe multisite, din administrarea rețelei), apoi confirmă. Pluginul nu se dezactivează: setările, registrul și paginile legale rămân la fel. După mutare, golește cache-ul paginilor (pluginul de cache, serverul sau CDN-ul) și verifică bannerul pe prima pagină a site-ului. Dacă mutarea nu reușește, de exemplu pentru că serverul nu permite scrierea în folderul plugins sau există deja un folder plugins/biscuitwp, ecranul îți arată motivul, iar pluginul rămâne în folderul vechi.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Re-consimțământul', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Când pe site apare un serviciu nou, într-o categorie pe care vizitatorul a acceptat-o deja, pluginul nu îl încarcă pe baza acordului vechi. La următoarea pagină pe care o deschide, bannerul se arată direct la preferințe, cu serviciile noi marcate, până când vizitatorul decide. Până atunci, categoria e tratată ca refuzată.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Servicii screen */ __( 'Pluginul ține minte toate serviciile găsite vreodată pe site. Dacă ai scos un serviciu și vrei ca lista să conțină doar ce e acum pe site, apasă „Resetează lista serviciilor văzute” în %1$sServicii%2$s.', 'biscuitwp' ), 'cmp-services' ) ); ?></p>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Vrei să întrebi din nou toți vizitatorii, de exemplu după o schimbare mare în politică? Mărește „Versiune politică” în %1$sSetări%2$s.', 'biscuitwp' ), 'cmp-settings' ) ); ?></p>
	</section>

	<?php /* 9. Register and data */ ?>
	<section class="cmp-guide-section">
		<h2 id="register"><?php echo esc_html( $titles['register'] ); ?></h2>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Registru consimțăminte screen */ __( '%1$sRegistru consimțăminte%2$s păstrează dovada alegerilor făcute de vizitatori, cerută de Legea 195/2024. Registrul există doar în modul Pro: în Light nu se cere acord.', 'biscuitwp' ), 'cmp-audit' ) ); ?></p>
		<p><?php esc_html_e( 'La fiecare alegere se păstrează: data, ID-ul consimțământului, decizia, categoriile și serviciile, limba, versiunea politicii și adresa paginii. IP-ul și browserul se păstrează doar ca hash, nu în clar.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Înregistrările se șterg automat după numărul de luni din „Retenție log (luni)”, în %1$sSetări%2$s. Implicit: 13 luni.', 'biscuitwp' ), 'cmp-settings' ) ); ?></p>

		<h3><?php esc_html_e( 'Căutarea după ID', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'Vizitatorul își vede ID-ul în fereastra „Setări cookie”, sub preferințe. Dacă îți scrie cu o cerere, pune ID-ul în câmpul „ID consimțământ” și apasă „Filtrează”. Vezi tot istoricul alegerilor lui.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Exportul', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( '„Export CSV” și „Export JSON” descarcă tot registrul. Păstrează fișierul în siguranță: e o dovadă legală.', 'biscuitwp' ); ?></p>
		<p><?php esc_html_e( '„Golește registrul” șterge definitiv toate înregistrările. Folosește-l doar dacă știi sigur că nu mai ai nevoie de ele.', 'biscuitwp' ); ?></p>

		<h3><?php esc_html_e( 'Dezinstalarea', 'biscuitwp' ); ?></h3>
		<p><?php esc_html_e( 'La dezinstalare, pluginul șterge mereu datele lui de lucru: rezultatele scanărilor, raportul verificării, imaginile video și fonturile localizate.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( CMP_Guide::with_links( /* translators: %1$s, %2$s: link to the Setări screen */ __( 'Setările, categoriile, serviciile configurate și registrul se șterg doar dacă ai bifat „Șterge toate datele la dezinstalare”, în %1$sSetări%2$s. Registrul e dovada legală a alegerilor: păstrează-l (sau un export al lui) cât cere legea înainte să bifezi.', 'biscuitwp' ), 'cmp-settings' ) ); ?></p>
		<p><?php esc_html_e( 'Paginile legale generate rămân pe site. Le ștergi tu, din Pagini, dacă nu mai ai nevoie de ele.', 'biscuitwp' ); ?></p>
	</section>

	<?php /* 10. Developers */ ?>
	<section class="cmp-guide-section">
		<h2 id="developers"><?php echo esc_html( $titles['developers'] ); ?></h2>
		<p><?php esc_html_e( 'Pluginul are un API JavaScript și o funcție PHP, ca să legi codul tău de acordul vizitatorului.', 'biscuitwp' ); ?></p>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1: window.CMP, 2: the script handle, 3: DOMContentLoaded */ __( 'În JavaScript, %1$s există după ce se încarcă scriptul bannerului (handle-ul %2$s, în footer). Încarcă scriptul tău cu el ca dependență sau așteaptă %3$s.', 'biscuitwp' ), CMP_Guide::code( 'window.CMP' ), CMP_Guide::code( 'cmp-banner' ), CMP_Guide::code( 'DOMContentLoaded' ) ) ); ?></p>
		<ul>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: dacă o categorie e permisă acum.', 'biscuitwp' ), CMP_Guide::code( 'CMP.isAllowed(slug)' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: decizia salvată, sau null dacă vizitatorul nu a decis.', 'biscuitwp' ), CMP_Guide::code( 'CMP.getConsent()' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: deschide preferințele.', 'biscuitwp' ), CMP_Guide::code( 'CMP.openPreferences()' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: apelează funcția la fiecare decizie, și imediat dacă vizitatorul a decis deja.', 'biscuitwp' ), CMP_Guide::code( 'CMP.onConsent(fn)' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: apelează funcția o singură dată, când categoria e permisă.', 'biscuitwp' ), CMP_Guide::code( 'CMP.whenAllowed(slug, fn)' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: adaugă o categorie la acord. Merge doar dintr-un clic real al vizitatorului, de exemplu pe butonul tău „Încarcă harta”.', 'biscuitwp' ), CMP_Guide::code( 'CMP.allowCategory(slug)' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: method name */ __( '%s: scoate o categorie din acord.', 'biscuitwp' ), CMP_Guide::code( 'CMP.denyCategory(slug)' ) ) ); ?></li>
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: event name */ __( '%s: eveniment pe document, după fiecare decizie pe categorii.', 'biscuitwp' ), CMP_Guide::code( 'cmp:consent' ) ) ); ?></li>
		</ul>
		<pre class="cmp-guide-code"><code><?php echo esc_html( $cmp_code_js ); ?></code></pre>
		<p><?php echo wp_kses_post( sprintf( /* translators: %s: the PHP function */ __( 'În PHP, %s spune dacă vizitatorul a permis o categorie. Nu o folosi pe pagini puse complet în cache: cookie-ul diferă de la un vizitator la altul.', 'biscuitwp' ), CMP_Guide::code( 'cmp_is_allowed( $category )' ) ) ); ?></p>
		<pre class="cmp-guide-code"><code><?php echo esc_html( $cmp_code_php ); ?></code></pre>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1-3: category slugs */ __( 'Categoriile se identifică prin slug: %1$s (mereu permisă), %2$s, %3$s și cele create de tine în Categorii.', 'biscuitwp' ), CMP_Guide::code( 'necessary' ), CMP_Guide::code( 'statistics' ), CMP_Guide::code( 'marketing' ) ) ); ?></p>
		<p><?php esc_html_e( 'În modul Light nu există decizie: allowCategory și denyCategory nu fac nimic, iar onConsent și whenAllowed nu apelează funcția pentru categoriile opționale.', 'biscuitwp' ); ?></p>
	</section>
</div>
