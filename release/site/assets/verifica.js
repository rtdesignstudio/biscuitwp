/* Consent Manager Pro, consent.rt.md: the "Verifică-ți site-ul" tool, its confetti
   and the demo style switch. No libraries, nothing loaded from other sites.
   Everything that comes from the server (titles, explanations, the checked address)
   is written with textContent only, never parsed as HTML. */
(function () {
	'use strict';

	var doc = document;
	// 'ro' (default), 'en' or 'ru', from <html lang>; anything else reads as Romanian.
	var LANG = doc.documentElement.lang === 'en' || doc.documentElement.lang === 'ru' ? doc.documentElement.lang : 'ro';
	// 1240 -> "1.240" (ro), "1,240" (en) or "1 240" (ru, a no-break space): fixed, not left to the browser's locale data.
	function group(n, sep) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, sep); }
	var T = LANG === 'en' ? {
		empty: 'Enter your site\'s address, for example example.com.',
		owner: "Tick that you own the site or have the owner's permission.",
		checking: 'Checking the site, up to 10 pages. It can take up to 45 seconds.',
		steps: [
			'Opening the homepage as a new visitor',
			'Looking for the pages of the site (sitemap)',
			'Checking up to 10 pages',
			'Looking for trackers: Google Analytics, Meta Pixel, Yandex, Clarity and others',
			'Checking cookies set before consent',
			'Looking for resources that send the IP address: Google Fonts, CDNs, Gravatar',
			'Looking for the cookie banner and the policy link'
		],
		cancelled: 'You cancelled the check.',
		law: 'Legal basis: ',
		cleanVerdict: 'No problems found on the pages we checked.',
		cleanLimits: 'What we cannot see from here: what scripts load after the page starts, what the site does with the data and what its policy says.',
		issuesTitle: function (n) { return n === 1 ? 'We found one problem' : 'We found ' + n + ' problems'; },
		unsureTitle: 'We cannot be sure',
		unsureText: 'The site has something that can load trackers we cannot see from here. Check it yourself, it takes 5 minutes, with DevTools.',
		facts: [function (n) { return n === 1 ? 'problem' : 'problems'; }, function () { return 'to check'; }, function () { return 'all good'; }],
		stopped: {
			requests: function (n) { return 'We stopped after ' + n + (n === 1 ? ' page' : ' pages') + ': the site has many redirects or files, and a check has a request limit.'; },
			time: function (n) { return 'We stopped after ' + n + (n === 1 ? ' page' : ' pages') + ' so the check does not take too long.'; }
		},
		pagesChecked: function (n) { return n === 1 ? 'We checked 1 page.' : 'We checked ' + n + ' pages.'; },
		more: {
			sitemap: function (n, c) { return 'The site has ' + n + ' pages; we checked the first ' + c + '. A full check is not available right now.'; },
			links: function (n, c) { return 'The site has at least ' + n + ' pages; we checked the first ' + c + '. A full check is not available right now.'; }
		},
		failed: 'We could not read: ',
		failCodes: {
			timeout: 'did not answer in time',
			too_large: 'the page is too big',
			other_host: 'leads to another site',
			limit: 'request limit reached',
			fetch_failed: 'could not be read',
			http_status: 'could not be read',
			not_html: 'could not be read'
		},
		failOther: 'could not be read',
		badAddress: 'Enter only the site address, for example example.md. We check the homepage and the other pages ourselves.',
		first: 'What to do first',
		issuesList: 'Problems',
		unsureList: 'To check',
		found: 'What we found',
		onPages: 'On which pages',
		why: 'Why it matters',
		howFix: 'How to fix it',
		withCmp: 'With Consent Manager Pro: ',
		passed: 'All good',
		info: 'Good to know',
		howto: 'How to check it yourself',
		fixTitle: 'Consent Manager Pro fixes this',
		fixText: 'It keeps trackers off until consent, moves Google Fonts to your server and gives you the legal texts for Moldova. Free.',
		oursTitle: 'You already use Consent Manager Pro',
		oursText: 'Check script blocking and the service list in its settings, then update the plugin to the latest version.',
		checks: function (n) { return group(n, ',') + ' checks so far'; },
		foundTitle: 'What we find most often',
		savePdf: 'Save as PDF',
		copy: 'Copy the report',
		copied: 'Report copied. Paste it into an email or a message.',
		copyFailed: 'Could not copy the report. Use “Save as PDF” instead.',
		months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
		printHead: function (d) { return 'Report from “Check your site” on consent.rt.md, ' + d; },
		copyFoot: function (d, url) { return 'Report made with “Check your site”, ' + url + ', ' + d + '.'; },
		foundKinds: { 'tracker': 'Tracker started before consent', 'google-fonts': 'Google Fonts loaded from Google', 'ip-resources': 'Other resources that send the visitor\'s IP', 'no-banner': 'Trackers with no cookie banner at all', 'no-policy': 'No link to the privacy policy' },
		foundNote: function (n) { return 'From ' + group(n, ',') + ' checks made with the tool. A site checked several times counts each time.'; },
		otherTitle: 'Your site does not look like WordPress',
		otherText: 'The problems above apply on any platform and are fixed in the site\'s code. Consent Manager Pro is a WordPress plugin; for other sites there is the standalone version: two files, and you mark the scripts that wait for consent yourself.',
		standalone: 'Standalone version',
		download: 'Download',
		latest: 'Latest version',
		features: 'See what it does',
		errorTitle: 'We could not check the site',
		errors: {
			bad_request: 'Enter your site\'s address, for example example.com.',
			owner_required: "Tick that you own the site or have the owner's permission.",
			bad_url: 'Enter only the site address, for example example.md. We check the homepage and the other pages ourselves.',
			blocked_address: 'This address leads to a private or reserved network. We can only check public sites.',
			fetch_failed: 'We could not open the site. Check the address or try again later.',
			timeout: 'The site answered too slowly. Try again later.',
			too_large: 'The homepage is over 2 MB, too much for an automatic check.',
			too_many_redirects: 'The site redirects too many times.',
			http_status: 'The site answered with an error, so we could not read the page.',
			not_html: 'This address does not lead to a web page.',
			rate_limited: 'You have run many checks in a short time. Try again in a minute.',
			busy: 'There are a lot of checks right now. Try again in a few minutes.',
			unavailable: 'The check is not available right now. Try again later.',
			forbidden: 'Reload this page and try again.',
			method_not_allowed: 'Reload this page and try again.',
			network: 'We could not reach our server. Check your connection and try again.',
			slow: 'The check took too long. Try again later.'
		}
	} : LANG === 'ru' ? {
		empty: 'Введите адрес сайта, например example.md.',
		owner: 'Отметьте, что сайт принадлежит вам или у вас есть разрешение владельца.',
		checking: 'Проверяем сайт, до 10 страниц. Это может занять до 45 секунд.',
		steps: [
			'Открываем главную страницу как новый посетитель',
			'Ищем страницы сайта (sitemap)',
			'Проверяем до 10 страниц',
			'Ищем трекеры: Google Analytics, Meta Pixel, Yandex, Clarity и другие',
			'Проверяем файлы cookie, установленные до согласия',
			'Ищем ресурсы, которые передают IP-адрес: Google Fonts, CDN, Gravatar',
			'Ищем баннер cookie и ссылку на политику'
		],
		cancelled: 'Вы отменили проверку.',
		law: 'Правовая основа: ',
		cleanVerdict: 'На проверенных страницах мы не нашли проблем.',
		cleanLimits: 'Чего мы не видим отсюда: что загружают скрипты после запуска страницы, что сайт делает с данными и что написано в его политике.',
		issuesTitle: function (n) { return n === 1 ? 'Мы нашли одну проблему' : 'Мы нашли ' + n + ' ' + ru(n, 'проблему', 'проблемы', 'проблем'); },
		unsureTitle: 'Мы не можем быть уверены',
		unsureText: 'На сайте есть что-то, что может загружать трекеры, которых мы не видим отсюда. Проверьте сами, за 5 минут, с помощью DevTools.',
		facts: [function (n) { return ru(n, 'проблема', 'проблемы', 'проблем'); }, function () { return 'нужно проверить'; }, function () { return 'в порядке'; }],
		stopped: {
			requests: function (n) { return 'Мы остановились после ' + n + ' ' + ru(n, 'страницы', 'страниц', 'страниц') + ': на сайте много перенаправлений или файлов, а у проверки есть лимит запросов.'; },
			time: function (n) { return 'Мы остановились после ' + n + ' ' + ru(n, 'страницы', 'страниц', 'страниц') + ', чтобы проверка не длилась слишком долго.'; }
		},
		pagesChecked: function (n) { return n === 1 ? 'Мы проверили одну страницу.' : 'Мы проверили ' + n + ' ' + ru(n, 'страницу', 'страницы', 'страниц') + '.'; },
		more: {
			sitemap: function (n, c) { return 'На сайте ' + n + ' ' + ru(n, 'страница', 'страницы', 'страниц') + '; мы проверили первые ' + c + '. Полная проверка сейчас недоступна.'; },
			links: function (n, c) { return 'На сайте не менее ' + n + ' ' + ru(n, 'страницы', 'страниц', 'страниц') + '; мы проверили первые ' + c + '. Полная проверка сейчас недоступна.'; }
		},
		failed: 'Не удалось прочитать: ',
		failCodes: {
			timeout: 'не ответила вовремя',
			too_large: 'страница слишком большая',
			other_host: 'ведёт на другой сайт',
			limit: 'достигнут лимит запросов',
			fetch_failed: 'не удалось прочитать',
			http_status: 'не удалось прочитать',
			not_html: 'не удалось прочитать'
		},
		failOther: 'не удалось прочитать',
		badAddress: 'Введите только адрес сайта, например example.md. Главную и остальные страницы мы проверяем сами.',
		first: 'С чего начать',
		issuesList: 'Проблемы',
		unsureList: 'Нужно проверить',
		found: 'Что мы нашли',
		onPages: 'На каких страницах',
		why: 'Почему это важно',
		howFix: 'Как исправить',
		withCmp: 'С Consent Manager Pro: ',
		passed: 'В порядке',
		info: 'Полезно знать',
		howto: 'Как проверить самостоятельно',
		fixTitle: 'Consent Manager Pro это исправляет',
		fixText: 'Блокирует трекеры до согласия, переносит Google Fonts на ваш сервер и даёт вам правовые тексты для Молдовы. Бесплатно.',
		oursTitle: 'Вы уже используете Consent Manager Pro',
		oursText: 'Проверьте в настройках блокировку скриптов и список сервисов, затем обновите плагин до последней версии.',
		checks: function (n) { return group(n, ' ') + ' ' + ru(n, 'проверка', 'проверки', 'проверок') + ' с момента запуска'; },
		foundTitle: 'Что мы находим чаще всего',
		savePdf: 'Сохранить как PDF',
		copy: 'Скопировать отчёт',
		copied: 'Мы скопировали отчёт. Вставьте его в письмо или сообщение.',
		copyFailed: 'Не удалось скопировать отчёт. Используйте «Сохранить как PDF».',
		months: ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
		printHead: function (d) { return 'Отчёт «Проверьте свой сайт» с consent.rt.md, ' + d; },
		copyFoot: function (d, url) { return 'Отчёт сделан с помощью «Проверьте свой сайт», ' + url + ', ' + d + '.'; },
		foundKinds: { 'tracker': 'Трекер запущен до согласия', 'google-fonts': 'Google Fonts загружаются с Google', 'ip-resources': 'Другие ресурсы, передающие IP-адрес посетителя', 'no-banner': 'Трекеры вообще без баннера cookie', 'no-policy': 'Нет ссылки на политику конфиденциальности' },
		foundNote: function (n) { return 'Из ' + group(n, ' ') + ' ' + ru(n, 'проверки, сделанной', 'проверок, сделанных', 'проверок, сделанных') + ' с помощью инструмента. Сайт, проверенный несколько раз, учитывается каждый раз.'; },
		otherTitle: 'Ваш сайт, похоже, сделан не на WordPress',
		otherText: 'Проблемы выше актуальны на любой платформе и исправляются в коде сайта. Consent Manager Pro является плагином для WordPress; для других сайтов есть автономная версия (standalone): два файла, а скрипты, которые ждут согласия, вы помечаете сами.',
		standalone: 'Автономная версия',
		download: 'Скачать',
		latest: 'Последняя версия',
		features: 'Что он умеет',
		errorTitle: 'Мы не смогли проверить сайт',
		errors: {
			bad_request: 'Введите адрес сайта, например example.md.',
			owner_required: 'Отметьте, что сайт принадлежит вам или у вас есть разрешение владельца.',
			bad_url: 'Введите только адрес сайта, например example.md. Главную и остальные страницы мы проверяем сами.',
			blocked_address: 'Адрес ведёт в частную или зарезервированную сеть. Мы можем проверять только публичные сайты.',
			fetch_failed: 'Мы не смогли открыть сайт. Проверьте адрес или попробуйте ещё раз позже.',
			timeout: 'Сайт ответил слишком медленно. Попробуйте ещё раз позже.',
			too_large: 'Главная страница больше 2 МБ, это слишком много для автоматической проверки.',
			too_many_redirects: 'Сайт перенаправляет слишком много раз.',
			http_status: 'Сайт ответил ошибкой, поэтому мы не смогли прочитать страницу.',
			not_html: 'Адрес не ведёт на веб-страницу.',
			rate_limited: 'Вы сделали много проверок за короткое время. Попробуйте через минуту.',
			busy: 'Сейчас очень много проверок. Попробуйте ещё раз через несколько минут.',
			unavailable: 'Проверка сейчас недоступна. Попробуйте ещё раз позже.',
			forbidden: 'Перезагрузите страницу и попробуйте ещё раз.',
			method_not_allowed: 'Перезагрузите страницу и попробуйте ещё раз.',
			network: 'Мы не смогли связаться с нашим сервером. Проверьте подключение и попробуйте ещё раз.',
			slow: 'Проверка заняла слишком много времени. Попробуйте ещё раз позже.'
		}
	} : {
		empty: 'Scrie adresa site-ului, de exemplu exemplu.md.',
		owner: 'Bifează că deții site-ul sau ai permisiunea proprietarului.',
		checking: 'Verificăm site-ul, până la 10 pagini. Poate dura până la 45 de secunde.',
		steps: [
			'Deschidem pagina principală, ca un vizitator nou',
			'Căutăm paginile site-ului (sitemap)',
			'Verificăm până la 10 pagini',
			'Căutăm trackere: Google Analytics, Meta Pixel, Yandex, Clarity și altele',
			'Verificăm cookie-urile puse înainte de acord',
			'Căutăm resurse care trimit IP-ul: Google Fonts, CDN-uri, Gravatar',
			'Căutăm bannerul de cookie-uri și linkul spre politică'
		],
		cancelled: 'Ai anulat verificarea.',
		law: 'Baza legală: ',
		cleanVerdict: 'N-am găsit probleme pe paginile verificate.',
		cleanLimits: 'Ce nu vedem de aici: ce încarcă scripturile după ce pornește pagina, ce face site-ul cu datele și ce scrie în politica lui.',
		issuesTitle: function (n) { return n === 1 ? 'Am găsit o problemă' : 'Am găsit ' + n + de(n, 'probleme'); },
		unsureTitle: 'Nu putem fi siguri',
		unsureText: 'Pe site e ceva care poate încărca trackere pe care nu le vedem de aici. Verifică singur, în 5 minute, cu DevTools.',
		facts: [function (n) { return n === 1 ? 'problemă' : 'probleme'; }, function () { return 'de verificat'; }, function () { return 'în regulă'; }],
		stopped: {
			requests: function (n) { return 'Ne-am oprit după ' + n + de(n, 'pagini') + ': site-ul are multe redirecționări sau fișiere, iar o verificare are o limită de cereri.'; },
			time: function (n) { return 'Ne-am oprit după ' + n + de(n, 'pagini') + ', ca verificarea să nu dureze prea mult.'; }
		},
		pagesChecked: function (n) { return n === 1 ? 'Am verificat o pagină.' : 'Am verificat ' + n + de(n, 'pagini') + '.'; },
		more: {
			sitemap: function (n, c) { return 'Site-ul are ' + n + de(n, 'pagini') + '; am verificat primele ' + c + '. Verificarea completă nu e disponibilă acum.'; },
			links: function (n, c) { return 'Site-ul are cel puțin ' + n + de(n, 'pagini') + '; am verificat primele ' + c + '. Verificarea completă nu e disponibilă acum.'; }
		},
		failed: 'Nu am putut citi: ',
		failCodes: {
			timeout: 'nu a răspuns la timp',
			too_large: 'pagina e prea mare',
			other_host: 'duce spre alt site',
			limit: 'limita de cereri atinsă',
			fetch_failed: 'nu a putut fi citită',
			http_status: 'nu a putut fi citită',
			not_html: 'nu a putut fi citită'
		},
		failOther: 'nu a putut fi citită',
		badAddress: 'Scrie doar adresa site-ului, de exemplu exemplu.md. Verificăm singuri pagina principală și celelalte pagini.',
		first: 'Ce faci întâi',
		issuesList: 'Probleme',
		unsureList: 'De verificat',
		found: 'Ce am găsit',
		onPages: 'Pe ce pagini',
		why: 'De ce contează',
		howFix: 'Cum rezolvi',
		withCmp: 'Cu Consent Manager Pro: ',
		passed: 'În regulă',
		info: 'De știut',
		howto: 'Cum verifici singur',
		fixTitle: 'Consent Manager Pro rezolvă asta',
		fixText: 'Oprește trackerele până la acord, mută Google Fonts pe serverul tău și îți dă textele legale pentru Moldova. Gratuit.',
		oursTitle: 'Folosești deja Consent Manager Pro',
		oursText: 'Verifică în setări blocarea scripturilor și lista de servicii, apoi actualizează pluginul la ultima versiune.',
		checks: function (n) { return group(n, '.') + (n % 100 === 0 || n % 100 >= 20 ? ' de verificări' : ' verificări') + ' făcute până acum'; },
		foundTitle: 'Ce găsim cel mai des',
		savePdf: 'Salvează ca PDF',
		copy: 'Copiază raportul',
		copied: 'Am copiat raportul. Lipește-l într-un email sau mesaj.',
		copyFailed: 'Nu am putut copia raportul. Folosește „Salvează ca PDF”.',
		months: ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
		printHead: function (d) { return 'Raport „Verifică-ți site-ul” de pe consent.rt.md, ' + d; },
		copyFoot: function (d, url) { return 'Raport făcut cu „Verifică-ți site-ul”, ' + url + ', ' + d + '.'; },
		foundKinds: { 'tracker': 'Tracker pornit înainte de acord', 'google-fonts': 'Google Fonts încărcate de la Google', 'ip-resources': 'Alte resurse care trimit IP-ul vizitatorului', 'no-banner': 'Trackere fără niciun banner de cookie-uri', 'no-policy': 'Niciun link spre politica de confidențialitate' },
		foundNote: function (n) { return 'Din ' + group(n, '.') + (n % 100 === 0 || n % 100 >= 20 ? ' de verificări' : ' verificări') + ' făcute cu instrumentul. Un site verificat de mai multe ori se numără de fiecare dată.'; },
		otherTitle: 'Site-ul tău nu pare făcut pe WordPress',
		otherText: 'Problemele de mai sus rămân valabile pe orice platformă și se rezolvă în codul site-ului. Consent Manager Pro e un plugin WordPress; pentru alte site-uri există versiunea standalone: două fișiere, iar scripturile care așteaptă acordul le marchezi singur.',
		standalone: 'Versiunea standalone',
		download: 'Descarcă',
		latest: 'Ultima versiune',
		features: 'Vezi ce face',
		errorTitle: 'Nu am putut verifica site-ul',
		errors: {
			bad_request: 'Scrie adresa site-ului, de exemplu exemplu.md.',
			owner_required: 'Bifează că deții site-ul sau ai permisiunea proprietarului.',
			bad_url: 'Scrie doar adresa site-ului, de exemplu exemplu.md. Verificăm singuri pagina principală și celelalte pagini.',
			blocked_address: 'Adresa duce spre o rețea privată sau rezervată. Putem verifica doar site-uri publice.',
			fetch_failed: 'Nu am putut deschide site-ul. Verifică adresa sau încearcă din nou mai târziu.',
			timeout: 'Site-ul a răspuns prea încet. Încearcă din nou mai târziu.',
			too_large: 'Pagina principală are peste 2 MB, prea mult pentru o verificare automată.',
			too_many_redirects: 'Site-ul redirecționează de prea multe ori.',
			http_status: 'Site-ul a răspuns cu o eroare, așa că nu am putut citi pagina.',
			not_html: 'Adresa nu duce la o pagină web.',
			rate_limited: 'Ai făcut multe verificări într-un timp scurt. Încearcă peste un minut.',
			busy: 'Sunt foarte multe verificări acum. Încearcă din nou peste câteva minute.',
			unavailable: 'Verificarea nu e disponibilă acum. Încearcă din nou mai târziu.',
			forbidden: 'Reîncarcă pagina și încearcă din nou.',
			method_not_allowed: 'Reîncarcă pagina și încearcă din nou.',
			network: 'Nu am putut ajunge la serverul nostru. Verifică conexiunea și încearcă din nou.',
			slow: 'Verificarea a durat prea mult. Încearcă din nou mai târziu.'
		}
	};
	// Romanian numerals: „20 de pagini”, but „5 pagini” and „101 pagini” (n % 100 from 1 to 19).
	function de(n, word) { var r = n % 100; return (r >= 1 && r <= 19 ? ' ' : ' de ') + word; }
	// Russian plurals: 1, 21 проверка (one); 2-4, 22-24 проверки (few); 0, 5-20, 25 проверок (many).
	function ru(n, one, few, many) {
		var a = n % 10, b = n % 100;
		return a === 1 && b !== 11 ? one : (a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many);
	}

	/* The visitor's field, checked like the server does (site/api/lib/fetch.php,
	   cmp_check_normalize_input): a bare domain only. Returns the host (punycode
	   for an IDN) or '' when the input must be refused. */
	var LOCAL_TLDS = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'arpa', 'onion'];
	function siteAddress(raw) {
		var s = String(raw === undefined || raw === null ? '' : raw).replace(/^[ \t\n\r]+|[ \t\n\r]+$/g, '');
		if (s === '' || s.length > 300 || /[\x00-\x20\x7F]/.test(s)) { return ''; }
		s = s.replace(/^https?:[/][/]/i, '');
		if (s.charAt(s.length - 1) === '/') { s = s.slice(0, -1); }
		if (s === '' || /[\/?#:@\\\[\]%]/.test(s)) { return ''; }
		var h = s.toLowerCase(), labels, tld, i;
		if (/[^\x00-\x7F]/.test(h)) {
			try { h = new URL('https://' + h + '/').hostname; } catch (e) { return ''; }
		}
		if (h === '' || h.length > 253) { return ''; }
		labels = h.split('.');
		if (labels.length < 2) { return ''; }
		for (i = 0; i < labels.length; i++) {
			if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(labels[i])) { return ''; }
		}
		tld = labels[labels.length - 1];
		if (!/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/.test(tld) || LOCAL_TLDS.indexOf(tld) !== -1) { return ''; }
		return h;
	}
	window.cmpSiteAddress = siteAddress;

	/* Small DOM helpers: text only. */
	function el(tag, cls, text) {
		var n = doc.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = String(text); }
		return n;
	}
	function link(href, text, cls) {
		var a = el('a', cls, text);
		a.setAttribute('href', href);
		return a;
	}
	function str(v) { return typeof v === 'string' ? v : ''; }
	// The standalone guide, from the page's own link (this script names no outside address).
	function standaloneHref() {
		var a = doc.querySelector('a[data-standalone]');
		return a ? a.getAttribute('href') : '#instalare';
	}
	function downloadHref() {
		var a = doc.querySelector('a[data-download]');
		return a ? a.getAttribute('href') : '#instalare';
	}

	/* confetti */
	function confetti() {
		if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches || doc.querySelector('canvas.confetti')) { return; }
		var c = doc.createElement('canvas'), x = c.getContext && c.getContext('2d');
		if (!x) { return; }
		var w = c.width = window.innerWidth, h = c.height = window.innerHeight;
		var cols = ['#8bc34a', '#f4b400', '#e4572e', '#3f8efc', '#a259ff'], p = [], t0 = 0, i;
		c.className = 'confetti';
		c.setAttribute('aria-hidden', 'true');
		doc.body.appendChild(c);
		for (i = 0; i < 120; i++) {
			p.push({ x: w / 2 + (Math.random() - 0.5) * w * 0.3, y: h * 0.4, vx: (Math.random() - 0.5) * 14, vy: -4 - Math.random() * 12, r: Math.random() * 6, s: 5 + Math.random() * 5, c: cols[i % 5] });
		}
		function frame(t) {
			if (!t0) { t0 = t; }
			var k = t - t0;
			x.clearRect(0, 0, w, h);
			x.globalAlpha = k > 1500 ? Math.max(0, 1 - (k - 1500) / 500) : 1;
			p.forEach(function (q) {
				q.vy += 0.35; q.vx *= 0.99; q.x += q.vx; q.y += q.vy; q.r += 0.12;
				x.save(); x.translate(q.x, q.y); x.rotate(q.r);
				x.fillStyle = q.c; x.fillRect(-q.s / 2, -q.s / 4, q.s, q.s / 2);
				x.restore();
			});
			if (k < 2000) { window.requestAnimationFrame(frame); } else if (c.parentNode) { c.parentNode.removeChild(c); }
		}
		window.requestAnimationFrame(frame);
	}
	/* /confetti */

	/* The demo style switch: links target the demo iframe; we only mark the current one. */
	Array.prototype.forEach.call(doc.querySelectorAll('.switch a[data-style]'), function (a) {
		a.addEventListener('click', function () {
			Array.prototype.forEach.call(doc.querySelectorAll('.switch a[data-style]'), function (b) { b.removeAttribute('aria-current'); });
			a.setAttribute('aria-current', 'true');
		});
	});

	var form = doc.getElementById('verifica-form');
	var out = doc.getElementById('verifica-rezultat');
	if (!form || !out || !window.fetch || !window.AbortController || !doc.getElementById('verifica-owner')) { return; }
	form.hidden = false; // Without JS (or fetch) the form stays hidden; the <noscript> text explains.
	var input = doc.getElementById('verifica-url');
	var submit = doc.getElementById('verifica-buton');
	var cancel = doc.getElementById('verifica-anuleaza');
	var owner = doc.getElementById('verifica-owner');
	var ownerErr = doc.getElementById('verifica-owner-eroare');
	var urlErr = doc.getElementById('verifica-eroare');
	var ctrl = null, timer = null, slow = false;

	// The owner box: required. Unticked, the message sits right under it.
	function ownerError() {
		if (!owner) { return; }
		owner.setAttribute('aria-invalid', 'true');
		if (ownerErr) { ownerErr.textContent = T.owner; ownerErr.hidden = false; }
		owner.focus();
	}
	// The counter line under the form: the total of successful checks, from 100 on.
	var counter = doc.getElementById('verifica-contor');
	var checks = -1;
	function showChecks() {
		if (!counter || checks < 100) { return; }
		counter.textContent = T.checks(checks);
		counter.hidden = false;
	}
	if (counter) {
		window.fetch('/api/contor.php', { credentials: 'omit' }).then(function (r) {
			return r && r.status === 200 ? r.json() : null;
		}).then(function (d) {
			if (d && typeof d.checks === 'number' && isFinite(d.checks) && d.checks >= 0) {
				checks = Math.floor(d.checks);
				showChecks();
			}
			if (d) { showFound(d.found); }
		}).catch(function () {});
	}
	// „Ce găsim cel mai des”: from 100 counted checks, one row per kind, most frequent first.
	function showFound(f) {
		var box = doc.getElementById('verifica-gasim');
		var of = f && typeof f === 'object' ? num(f.of) : 0;
		if (!box || of < 100) { return; }
		var rows = ['tracker', 'google-fonts', 'ip-resources', 'no-policy', 'no-banner'].map(function (k, i) {
			return { k: k, i: i, pct: Math.round(Math.min(num(f[k]), of) * 100 / of) };
		});
		rows.sort(function (a, b) { return b.pct - a.pct || a.i - b.i; });
		box.appendChild(el('h3', null, T.foundTitle));
		var ul = el('ul', 'found-list');
		rows.forEach(function (r) {
			var li = el('li');
			li.appendChild(el('span', 'found-label', T.foundKinds[r.k]));
			var bar = el('span', 'found-bar');
			bar.setAttribute('aria-hidden', 'true');
			var fill = el('span');
			fill.setAttribute('style', 'width:' + r.pct + '%');
			bar.appendChild(fill);
			li.appendChild(bar);
			li.appendChild(el('span', 'found-pct', r.pct + '%'));
			ul.appendChild(li);
		});
		box.appendChild(ul);
		box.appendChild(el('p', 'muted small', T.foundNote(of)));
		box.hidden = false;
	}
	function ownerOk() {
		if (!owner) { return; }
		owner.removeAttribute('aria-invalid');
		if (ownerErr) { ownerErr.textContent = ''; ownerErr.hidden = true; }
	}
	if (owner) { owner.addEventListener('change', function () { if (owner.checked) { ownerOk(); } }); }

	function clear() {
		while (out.firstChild) { out.removeChild(out.firstChild); }
		markHtml('has-report', false);
	}

	/* Sharing the report: nothing leaves the browser. "Salvează ca PDF" is the
	   browser's own print dialog, with every folded detail open and only the
	   report on paper (print rules in style.css); "Copiază raportul" puts the
	   report's text on the clipboard. */
	function markHtml(cls, on) {
		var h = doc.documentElement, c = (' ' + (h.className || '') + ' ').replace(' ' + cls + ' ', ' ');
		h.className = (on ? c + cls : c).replace(/^\s+|\s+$/g, '').replace(/\s+/g, ' ');
	}
	function today() {
		var d = new Date();
		return d.getDate() + ' ' + T.months[d.getMonth()] + ' ' + d.getFullYear();
	}
	var reopened = [];
	function printPrep() {
		if (!out.firstChild) { return; }
		markHtml('print-report', true);
		all(out, 'DETAILS').forEach(function (d) {
			if (d.getAttribute('open') === null) { d.setAttribute('open', ''); reopened.push(d); }
		});
	}
	function printDone() {
		reopened.forEach(function (d) { d.removeAttribute('open'); });
		reopened = [];
		markHtml('print-report', false);
	}
	if (window.addEventListener) {
		window.addEventListener('beforeprint', function () { if (!reopened.length) { printPrep(); } });
		window.addEventListener('afterprint', printDone);
	}
	// Elements of a tag under a node, in document order (no querySelector needed).
	function all(node, tag, acc) {
		acc = acc || [];
		Array.prototype.forEach.call(node.childNodes || [], function (c) {
			if (c.nodeType !== 1) { return; }
			if (c.tagName === tag) { acc.push(c); }
			all(c, tag, acc);
		});
		return acc;
	}
	// The report as plain text: one line per block, "- " before list items; the
	// buttons, links and the print-only title are left out.
	var BLOCK = /^(H3|H4|P|LI|DT|DD|UL|OL|DL|DIV|SUMMARY|DETAILS)$/;
	function reportText(node) {
		var lines = [], cur = '', pad = '';
		function flush() {
			var t = cur.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			if (t) { lines.push(pad + t); }
			cur = '';
		}
		function spaces(n) { return new Array(n + 1).join('  '); }
		// depth: how many list items hold this node (2 spaces each).
		function walk(n, depth) {
			if (n.nodeType === 3) { cur += n.data; return; }
			if (n.nodeType !== 1) { return; }
			var cls = ' ' + (n.className || '') + ' ';
			if (/ (actions|r-share|r-print-head|r-dt-fold) /.test(cls) || n.tagName === 'BUTTON' || n.tagName === 'A') { return; }
			var block = BLOCK.test(n.tagName), kids = n.childNodes;
			if (block) {
				flush();
				if (n.tagName === 'H4' && lines.length) { lines.push(''); }
				pad = spaces(depth);
				if (n.tagName === 'LI') { cur = '- '; depth++; }
			}
			Array.prototype.forEach.call(kids, function (c, i) {
				walk(c, depth);
				if (c.nodeType === 1 && !BLOCK.test(c.tagName)) {
					var next = kids[i + 1];
					// "Title: what to do" after a bold title followed by inline text; else a space.
					cur += c.tagName === 'STRONG' && next && !(next.nodeType === 1 && BLOCK.test(next.tagName)) ? ': ' : ' ';
				}
			});
			if (block) { flush(); pad = spaces(depth); }
		}
		walk(node, 0);
		return lines.join('\n').replace(/\n{3,}/g, '\n\n');
	}
	function copyText(text, done) {
		var nav = window.navigator;
		if (nav && nav.clipboard && nav.clipboard.writeText) {
			nav.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
			return;
		}
		done(false);
	}
	function share(r) {
		var date = today();
		var head = el('p', 'r-print-head', T.printHead(date));
		r.box.insertBefore ? r.box.insertBefore(head, r.box.firstChild) : r.box.appendChild(head);
		var wrap = el('div', 'r-share');
		var pdf = el('button', 'btn btn-ghost r-print', T.savePdf);
		var cp = el('button', 'btn btn-ghost r-copy', T.copy);
		pdf.setAttribute('type', 'button');
		cp.setAttribute('type', 'button');
		var msg = el('p', 'muted small r-share-status');
		msg.setAttribute('role', 'status');
		pdf.addEventListener('click', function () {
			printPrep();
			window.print();
		});
		cp.addEventListener('click', function () {
			var origin = window.location && window.location.origin ? window.location.origin + '/' : 'consent.rt.md';
			copyText(reportText(r.box) + '\n\n' + T.copyFoot(date, origin), function (ok) {
				msg.textContent = ok ? T.copied : T.copyFailed;
			});
		});
		wrap.appendChild(pdf);
		wrap.appendChild(cp);
		wrap.appendChild(msg);
		r.box.appendChild(wrap);
		markHtml('has-report', true);
	}
	function busy(on) {
		submit.disabled = on;
		cancel.hidden = !on;
		form.setAttribute('aria-busy', on ? 'true' : 'false');
	}
	// Status lines (checking, cancelled) are announced by the live region. A final
	// result is not: focus moves to its heading instead, so it is read only once.
	function status(text) {
		clear();
		out.setAttribute('aria-live', 'polite');
		out.appendChild(el('p', 'r-loading', text));
	}
	function box(kind, title) {
		clear();
		out.setAttribute('aria-live', 'off');
		var b = el('div', 'r r-' + kind);
		var hd = el('h3', null, title);
		hd.setAttribute('tabindex', '-1');
		b.appendChild(hd);
		out.appendChild(b);
		return { box: b, head: hd };
	}
	function show(r) {
		r.head.focus();
		window.setTimeout(function () { out.setAttribute('aria-live', 'polite'); }, 500);
	}
	function list(parent, items, title, cls) {
		if (!items.length) { return; }
		if (title) { parent.appendChild(el('h4', null, title)); }
		var ul = el('ul', 'findings' + (cls ? ' ' + cls : ''));
		items.forEach(function (f) {
			var li = el('li');
			li.appendChild(el('strong', null, str(f.title)));
			if (str(f.explain)) { li.appendChild(el('p', null, str(f.explain))); }
			if (str(f.law)) { li.appendChild(el('p', 'law', T.law + str(f.law))); }
			ul.appendChild(li);
		});
		parent.appendChild(ul);
	}
	function actions(parent, items) {
		var p = el('p', 'actions');
		items.forEach(function (it) { p.appendChild(link(it[0], it[1], it[2])); });
		parent.appendChild(p);
	}
	function arr(v) { return Array.isArray(v) ? v : []; }
	function strs(v) { return arr(v).map(str).filter(function (x) { return x !== ''; }); }
	function num(v) { return typeof v === 'number' && isFinite(v) && v >= 0 ? Math.floor(v) : 0; }
	function bySeverity(findings, sev) {
		return findings.filter(function (f) { return f && typeof f === 'object' && f.severity === sev; });
	}
	// The checked host, for the summary (text only).
	function host(u) {
		try { return new URL(u).hostname || u; } catch (e) { return u; }
	}
	// A page of the checked site, shown as its path (text only, never a link).
	function rel(u) {
		try { var x = new URL(u); return (x.pathname || '/') + x.search; } catch (e) { return u; }
	}

	// Summary: the checked host, a one-line verdict, three counters (rt.md .facts),
	// how many pages were checked and, over 10, how many the site has.
	function summary(kind, data, verdict, counts) {
		var r = box(kind, '');
		r.head.appendChild(el('span', 'r-host', host(str(data.checked_url))));
		r.head.appendChild(doc.createTextNode(' ')); // read as two words, not "exemplu.mdAm"
		r.head.appendChild(el('span', 'r-verdict', verdict));
		var ul = el('ul', 'facts r-facts');
		counts.forEach(function (n, i) {
			var li = el('li');
			li.appendChild(el('b', null, String(n)));
			li.appendChild(el('span', null, T.facts[i](n)));
			ul.appendChild(li);
		});
		r.box.appendChild(ul);
		var pages = data.pages && typeof data.pages === 'object' ? data.pages : {};
		var checked = strs(pages.checked).length || (str(data.checked_url) ? 1 : 0);
		var p = el('p', 'r-pages', checked ? T.pagesChecked(checked) : '');
		var total = num(pages.total), src = str(pages.total_source);
		var stopped = pages.stopped === 'requests' || pages.stopped === 'time' ? pages.stopped : '';
		// When we stopped early, the stopped note explains it: a sitemap total also counts files, so "N pages" would mislead.
		if (!stopped && total > checked && checked >= 10 && (src === 'sitemap' || src === 'links')) {
			p.appendChild(doc.createTextNode(' ' + T.more[src](total, checked)));
		}
		if (p.textContent) { r.box.appendChild(p); }
		if (stopped && checked) { r.box.appendChild(el('p', 'muted small r-stopped', T.stopped[stopped](checked))); }
		var failed = arr(pages.failed).filter(function (f) { return f && typeof f === 'object' && str(f.url); });
		if (failed.length) {
			r.box.appendChild(el('p', 'muted small r-failed', T.failed + failed.map(function (f) {
				var c = f.code;
				return rel(str(f.url)) + ' (' + (Object.prototype.hasOwnProperty.call(T.failCodes, c) ? T.failCodes[c] : (/^\d{3}$/.test(String(c)) ? 'HTTP ' + c : T.failOther)) + ')';
			}).join(', ') + '.'));
		}
		return r;
	}

	// "Ce faci întâi": the findings named in data.top, in that order, at most 3.
	function first(parent, findings, top) {
		var items = strs(top).slice(0, 3).map(function (id) {
			return findings.filter(function (f) { return str(f.id) === id; })[0];
		}).filter(Boolean);
		if (!items.length) { return; }
		parent.appendChild(el('h4', null, T.first));
		var ol = el('ol', 'r-first');
		items.forEach(function (f) {
			var li = el('li');
			li.appendChild(el('strong', null, str(f.title)));
			if (str(f.fix)) { li.appendChild(el('span', null, str(f.fix))); }
			ol.appendChild(li);
		});
		parent.appendChild(ol);
	}

	// Every finding: what we found, on which pages, why it matters, how to fix it.
	function detailed(parent, items, title) {
		if (!items.length) { return; }
		parent.appendChild(el('h4', null, title));
		var ul = el('ul', 'findings r-findings');
		items.forEach(function (f) {
			var li = el('li');
			li.appendChild(el('strong', null, str(f.title)));
			var dl = el('dl');
			var ev = strs(f.evidence).slice(0, 3);
			if (ev.length) {
				dl.appendChild(el('dt', null, T.found));
				var dd = el('dd'), eu = el('ul', 'r-evidence');
				ev.forEach(function (e) { eu.appendChild(el('li', null, e)); });
				dd.appendChild(eu);
				dl.appendChild(dd);
			}
			var pg = strs(f.pages).map(rel);
			if (pg.length) {
				var pu = el('ul', 'r-paths');
				pg.forEach(function (x) { pu.appendChild(el('li', null, x)); });
				if (pg.length > 3) {
					// Many pages: folded, the count on the summary.
					dl.appendChild(el('dt', 'r-dt-fold', T.onPages));
					var dd2 = el('dd'), det = el('details');
					det.appendChild(el('summary', null, T.onPages + ' (' + pg.length + ')'));
					det.appendChild(pu);
					dd2.appendChild(det);
					dl.appendChild(dd2);
				} else {
					dl.appendChild(el('dt', null, T.onPages));
					var dd3 = el('dd');
					dd3.appendChild(pu);
					dl.appendChild(dd3);
				}
			}
			if (str(f.explain) || str(f.law)) {
				dl.appendChild(el('dt', null, T.why));
				var dd4 = el('dd');
				if (str(f.explain)) { dd4.appendChild(el('p', null, str(f.explain))); }
				if (str(f.law)) { dd4.appendChild(el('p', 'law', T.law + str(f.law))); }
				dl.appendChild(dd4);
			}
			if (str(f.fix) || str(f.cmp_fix)) {
				dl.appendChild(el('dt', null, T.howFix));
				var dd5 = el('dd');
				if (str(f.fix)) { dd5.appendChild(el('p', null, str(f.fix))); }
				if (str(f.cmp_fix)) { dd5.appendChild(el('p', 'r-cmp', T.withCmp + str(f.cmp_fix))); }
				dl.appendChild(dd5);
			}
			if (dl.childNodes.length) { li.appendChild(dl); }
			ul.appendChild(li);
		});
		parent.appendChild(ul);
	}

	// "În regulă": the checks with nothing found, with a check mark (CSS).
	function passed(parent, items) {
		var ok = items.filter(function (p) { return p && typeof p === 'object' && str(p.title); });
		if (!ok.length) { return; }
		parent.appendChild(el('h4', null, T.passed));
		var ul = el('ul', 'passed');
		ok.forEach(function (p) { ul.appendChild(el('li', null, str(p.title))); });
		parent.appendChild(ul);
	}

	function renderError(code, serverMessage) {
		var r = box('error', T.errorTitle);
		var msg = T.errors[code] || str(serverMessage) || T.errors.unavailable;
		if (code === 'http_status' && str(serverMessage)) { msg = str(serverMessage); }
		r.box.appendChild(el('p', null, msg));
		if (code === 'owner_required') { ownerError(); }
		if (code !== 'rate_limited' && code !== 'busy' && code !== 'bad_url' && code !== 'bad_request' && code !== 'owner_required') {
			actions(r.box, [['#cum-verifici', T.howto, 'btn btn-ghost']]);
		}
		show(r);
	}

	function render(data) {
		if (!data || typeof data !== 'object') { renderError('unavailable'); return; }
		var findings = arr(data.findings).filter(function (f) { return f && typeof f === 'object'; });
		var issues = bySeverity(findings, 'issue'), unsure = bySeverity(findings, 'unsure'), info = bySeverity(findings, 'info');
		var ok = arr(data.passed);
		var ours = data.cmp === 'Consent Manager Pro';
		if (checks >= 0 && (data.status === 'clean' || data.status === 'issues' || data.status === 'unsure')) {
			checks++; // This check is in the total now.
			showChecks();
		}
		// Only an explicit false: an older answer without the field keeps the plugin box.
		var other = !ours && data.wordpress === false;
		var counts = [issues.length, unsure.length, ok.filter(function (p) { return p && typeof p === 'object' && str(p.title); }).length];
		var r;
		if (ours && data.status === 'issues') {
			// The "already using it" block below says the same as our own CMP finding.
			info = info.filter(function (f) { return str(f.id).indexOf('cmp:consent-manager-pro') !== 0; });
		}
		if (data.status === 'clean') {
			r = summary('clean', data, T.cleanVerdict, counts);
			detailed(r.box, unsure, T.unsureList);
			passed(r.box, ok);
			r.box.appendChild(el('p', 'muted', T.cleanLimits));
			list(r.box, info, T.info, 'muted-list');
			share(r);
			show(r);
			confetti();
		} else if (data.status === 'issues') {
			r = summary('issues', data, T.issuesTitle(issues.length), counts);
			first(r.box, issues, data.top);
			detailed(r.box, issues, T.issuesList);
			detailed(r.box, unsure, T.unsureList);
			passed(r.box, ok);
			list(r.box, info, T.info, 'muted-list');
			var fix = el('div', 'fix');
			fix.appendChild(el('h4', null, ours ? T.oursTitle : other ? T.otherTitle : T.fixTitle));
			fix.appendChild(el('p', null, ours ? T.oursText : other ? T.otherText : T.fixText));
			r.box.appendChild(fix);
			actions(fix, ours ?
				[[downloadHref(), T.latest, 'btn'], ['#cum-verifici', T.howto, 'btn btn-ghost']] :
				other ?
				[[standaloneHref(), T.standalone, 'btn'], ['#cum-verifici', T.howto, 'btn btn-ghost']] :
				[[downloadHref(), T.download, 'btn'], ['#functii', T.features, 'btn btn-ghost']]);
			share(r);
			show(r);
		} else if (data.status === 'unsure') {
			r = summary('unsure', data, T.unsureTitle, counts);
			r.box.appendChild(el('p', null, T.unsureText));
			detailed(r.box, unsure, T.unsureList);
			passed(r.box, ok);
			list(r.box, info, T.info, 'muted-list');
			actions(r.box, [['#cum-verifici', T.howto, 'btn btn-ghost']]);
			share(r);
			show(r);
		} else if (data.status === 'error' && !str(data.code) && findings.length) {
			// The page was fetched, but there was nothing to analyse (an empty page).
			r = box('error', T.errorTitle);
			if (str(data.checked_url)) { r.box.appendChild(el('p', 'muted small checked', host(str(data.checked_url)))); }
			list(r.box, findings);
			show(r);
		} else {
			renderError(str(data.code) || 'unavailable', data.message);
		}
	}

	/* What is being checked, step by step, while the request runs. Visual only:
	   the list is aria-hidden; screen readers hear the status line, then the result. */
	var run = null;
	function reduced() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}
	function setStep(li, state) {
		li.className = 'run-step is-' + state;
	}
	function stepsStart() {
		stepsStop();
		var ol = el('ol', 'run-steps');
		ol.setAttribute('aria-hidden', 'true');
		var items = T.steps.map(function (text, i) {
			var li = el('li');
			li.appendChild(el('span', 'run-num', String(i + 1)));
			li.appendChild(el('span', 'run-text', text));
			setStep(li, i === 0 ? 'active' : 'todo');
			ol.appendChild(li);
			return li;
		});
		out.appendChild(ol);
		run = { items: items, at: 0, tick: null, cascade: null };
		var mine = run;
		mine.tick = window.setInterval(function () {
			if (run !== mine || mine.at >= items.length - 1) { return; } // The last step stays active until the answer.
			setStep(items[mine.at], 'done');
			mine.at++;
			setStep(items[mine.at], 'active');
		}, 1200);
	}
	function stepsStop() {
		if (!run) { return; }
		window.clearInterval(run.tick);
		window.clearTimeout(run.cascade);
		run = null;
	}
	// Mark every step done (a short cascade, or at once under reduced motion), then go on.
	function stepsFinish(then) {
		if (!run) { then(); return; }
		var mine = run;
		window.clearInterval(mine.tick);
		if (reduced()) {
			mine.items.forEach(function (li) { setStep(li, 'done'); });
			stepsStop();
			then();
			return;
		}
		(function next() {
			if (run !== mine) { return; }
			if (mine.at >= mine.items.length) { stepsStop(); then(); return; }
			setStep(mine.items[mine.at], 'done');
			mine.at++;
			mine.cascade = window.setTimeout(next, mine.at >= mine.items.length ? 250 : 150);
		}());
	}

	function stop() {
		stepsStop();
		if (timer) { window.clearTimeout(timer); timer = null; }
		ctrl = null;
		busy(false);
	}

	cancel.addEventListener('click', function () {
		if (ctrl) { ctrl.abort(); }
	});

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (ctrl) { return; }
		var url = (input.value || '').trim();
		if (urlErr) { urlErr.textContent = ''; urlErr.hidden = true; }
		input.removeAttribute('aria-invalid');
		if (url && !siteAddress(url)) {
			// Not a bare domain: say so next to the field, send nothing.
			clear();
			input.setAttribute('aria-invalid', 'true');
			if (urlErr) { urlErr.textContent = T.badAddress; urlErr.hidden = false; } else { clear(); out.appendChild(el('p', 'r-loading', T.badAddress)); }
			input.focus();
			return;
		}
		if (!url) {
			var r0 = box('error', T.errorTitle);
			r0.box.appendChild(el('p', null, T.empty));
			show(r0);
			input.focus();
			return;
		}
		if (!owner.checked) {
			clear();
			ownerError();
			return;
		}
		ownerOk();
		status(T.checking);
		stepsStart();
		busy(true);
		ctrl = new window.AbortController();
		slow = false;
		var mine = ctrl;
		timer = window.setTimeout(function () { slow = true; mine.abort(); }, 45000);
		window.fetch('/api/verifica.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ url: url, lang: LANG, owner: 1 }),
			credentials: 'same-origin',
			cache: 'no-store',
			signal: mine.signal
		}).then(function (res) {
			return res.json().catch(function () { return { status: 'error', code: res.status === 429 ? 'rate_limited' : 'unavailable' }; });
		}).then(function (data) {
			if (timer) { window.clearTimeout(timer); timer = null; }
			var done = function () { stop(); render(data); };
			// An error answer skips the cascade: nothing was checked.
			if (data && typeof data === 'object' && data.status !== 'error') { stepsFinish(done); } else { done(); }
		}).catch(function (err) {
			stop();
			if (err && err.name === 'AbortError') {
				if (slow) {
					renderError('slow');
				} else {
					status(T.cancelled);
					input.focus();
				}
				return;
			}
			renderError('network');
		});
	});
}());
