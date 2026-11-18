<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP calls guarded.
}

/**
 * Built-in RO/RU/EN copy for the banner, picked by the current page language.
 * WPML/Polylang String Translation and admin-edited Settings still take
 * precedence (see templates); this is the default source when nothing is set.
 */
class CMP_Lang {

	const LANGS = array( 'ro', 'ru', 'en' );

	/** Pure: normalize a locale/slug ("en_US", "RU", "ro-RO") to ro|ru|en. */
	public static function normalize( $slug ) {
		$slug = strtolower( substr( (string) $slug, 0, 2 ) );
		return in_array( $slug, self::LANGS, true ) ? $slug : 'ro';
	}

	/** Pure: the full language packs. RO strings mirror the installer seed. */
	public static function packs() {
		return array(
			'ro' => array(
				'texts' => array(
					'title'       => 'Informații despre cookie-uri',
					'intro'       => 'Folosim cookie-uri esențiale pentru funcționarea site-ului și, cu acordul tău, cookie-uri de analiză pentru a-l îmbunătăți. Poți accepta sau refuza cookie-urile opționale.',
					'prefs'       => 'Gestionează cookie-urile',
					'reject'      => 'Doar cele necesare',
					'accept'      => 'Permite tot',
					'prefs_title' => 'Alege ce cookie-uri accepți',
					'save'        => 'Confirmă selecția',
					'badge'       => 'Obligatoriu',
					'locked'      => 'Activat permanent',
					'back'        => 'Înapoi',
					'reopen'      => 'Setări cookie',
					'light_intro' => 'Acest site folosește doar cookie-uri esențiale pentru funcționare.',
					'privacy'     => 'Politica de confidențialitate',
					'embed_text'  => 'Conținut de la %s. Se încarcă doar cu acordul tău, pentru că poate seta cookie-uri.',
					'embed_load'  => 'Încarcă conținutul',
					'embed_always'  => 'Încarcă mereu',
					'always_loaded' => 'Încărcate mereu:',
					'always_remove' => 'Nu mai încărca %s',
					'consent_id'  => 'ID-ul consimțământului',
					'reconsent_title' => 'Am adăugat servicii noi',
					'reconsent_text'  => 'Pe site au apărut servicii noi în categoriile pe care le-ai acceptat. Alege din nou.',
					'svc_new'         => 'nou',
					'tbl_name'     => 'Nume',
					'tbl_provider' => 'Furnizor',
					'tbl_duration' => 'Durată',
					'tbl_purpose'  => 'Scop',
					'tbl_category' => 'Categorie',
					'tbl_transfer' => 'Transfer',
					'tbl_none'     => '—',
					'tbl_empty'    => 'Nu au fost definite cookie-uri.',
					'title_privacy' => 'Politica de confidențialitate',
					'title_terms'   => 'Termeni și condiții',
					'title_cookie'  => 'Politica de cookie-uri',
				),
				'categories' => array(
					'necessary'  => array( 'label' => 'Necesare',   'description' => 'Cookie-uri esențiale pentru funcționarea site-ului.' ),
					'statistics' => array( 'label' => 'Statistici', 'description' => 'Ne ajută să înțelegem cum este folosit site-ul.' ),
					'marketing'  => array( 'label' => 'Marketing',  'description' => 'Folosite pentru publicitate relevantă.' ),
				),
			),
			'ru' => array(
				'texts' => array(
					'title'       => 'Информация о файлах cookie',
					'intro'       => 'Мы используем необходимые файлы cookie для работы сайта и, с вашего согласия, аналитические cookie для его улучшения. Вы можете принять или отклонить дополнительные cookie.',
					'prefs'       => 'Управление cookie',
					'reject'      => 'Только необходимые',
					'accept'      => 'Разрешить все',
					'prefs_title' => 'Выберите, какие cookie принять',
					'save'        => 'Подтвердить выбор',
					'badge'       => 'Обязательно',
					'locked'      => 'Всегда включено',
					'back'        => 'Назад',
					'reopen'      => 'Настройки cookie',
					'light_intro' => 'Этот сайт использует только необходимые для работы файлы cookie.',
					'privacy'     => 'Политика конфиденциальности',
					'embed_text'  => 'Контент от %s. Загружается только с вашего согласия, так как может устанавливать файлы cookie.',
					'embed_load'  => 'Загрузить контент',
					'embed_always'  => 'Загружать всегда',
					'always_loaded' => 'Загружаются всегда:',
					'always_remove' => 'Больше не загружать %s',
					'consent_id'  => 'ID согласия',
					'reconsent_title' => 'Мы добавили новые сервисы',
					'reconsent_text'  => 'На сайте появились новые сервисы в принятых вами категориях. Сделайте выбор снова.',
					'svc_new'         => 'новый',
					'tbl_name'     => 'Название',
					'tbl_provider' => 'Поставщик',
					'tbl_duration' => 'Срок хранения',
					'tbl_purpose'  => 'Цель',
					'tbl_category' => 'Категория',
					'tbl_transfer' => 'Передача данных',
					'tbl_none'     => '—',
					'tbl_empty'    => 'Файлы cookie не определены.',
					'title_privacy' => 'Политика конфиденциальности',
					'title_terms'   => 'Условия использования',
					'title_cookie'  => 'Политика использования файлов cookie',
				),
				'categories' => array(
					'necessary'  => array( 'label' => 'Необходимые', 'description' => 'Основные файлы cookie, необходимые для работы сайта.' ),
					'statistics' => array( 'label' => 'Статистика',  'description' => 'Помогают понять, как используется сайт.' ),
					'marketing'  => array( 'label' => 'Маркетинг',   'description' => 'Используются для релевантной рекламы.' ),
				),
			),
			'en' => array(
				'texts' => array(
					'title'       => 'Cookie information',
					'intro'       => 'We use essential cookies for the website to function and, with your consent, analytics cookies to improve it. You can accept or reject optional cookies.',
					'prefs'       => 'Manage cookies',
					'reject'      => 'Necessary only',
					'accept'      => 'Accept all',
					'prefs_title' => 'Choose which cookies to accept',
					'save'        => 'Confirm selection',
					'badge'       => 'Required',
					'locked'      => 'Always active',
					'back'        => 'Back',
					'reopen'      => 'Cookie settings',
					'light_intro' => 'This website uses only cookies essential for its operation.',
					'privacy'     => 'Privacy policy',
					'embed_text'  => 'Content from %s. It loads only with your consent, because it may set cookies.',
					'embed_load'  => 'Load content',
					'embed_always'  => 'Always load',
					'always_loaded' => 'Always loaded:',
					'always_remove' => 'Stop loading %s',
					'consent_id'  => 'Consent ID',
					'reconsent_title' => 'We added new services',
					'reconsent_text'  => 'New services were added to categories you accepted. Please choose again.',
					'svc_new'         => 'new',
					'tbl_name'     => 'Name',
					'tbl_provider' => 'Provider',
					'tbl_duration' => 'Duration',
					'tbl_purpose'  => 'Purpose',
					'tbl_category' => 'Category',
					'tbl_transfer' => 'Transfer',
					'tbl_none'     => '—',
					'tbl_empty'    => 'No cookies have been defined.',
					'title_privacy' => 'Privacy Policy',
					'title_terms'   => 'Terms and Conditions',
					'title_cookie'  => 'Cookie Policy',
				),
				'categories' => array(
					'necessary'  => array( 'label' => 'Necessary',  'description' => 'Essential cookies required for the website to function.' ),
					'statistics' => array( 'label' => 'Statistics', 'description' => 'Help us understand how the site is used.' ),
					'marketing'  => array( 'label' => 'Marketing',  'description' => 'Used for relevant advertising.' ),
				),
			),
		);
	}

	/** Current page language (WPML → Polylang → locale → ro). */
	public static function current() {
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return self::normalize( ICL_LANGUAGE_CODE );
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language();
			if ( $pll ) {
				return self::normalize( $pll );
			}
		}
		if ( function_exists( 'determine_locale' ) ) {
			return self::normalize( determine_locale() );
		}
		if ( function_exists( 'get_locale' ) ) {
			return self::normalize( get_locale() );
		}
		return 'ro';
	}

	public static function text( $key, $lang = null ) {
		$lang  = null === $lang ? self::current() : self::normalize( $lang );
		$packs = self::packs();
		return isset( $packs[ $lang ]['texts'][ $key ] ) ? $packs[ $lang ]['texts'][ $key ] : '';
	}

	public static function cat( $slug, $field, $lang = null ) {
		$lang  = null === $lang ? self::current() : self::normalize( $lang );
		$packs = self::packs();
		return isset( $packs[ $lang ]['categories'][ $slug ][ $field ] ) ? $packs[ $lang ]['categories'][ $slug ][ $field ] : '';
	}

	/** If $stored equals the RO seed value, return the localized value; else keep $stored. */
	public static function localize_seed( $slug, $field, $stored, $lang = null ) {
		$packs = self::packs();
		$ro    = isset( $packs['ro']['categories'][ $slug ][ $field ] ) ? $packs['ro']['categories'][ $slug ][ $field ] : null;
		if ( null !== $ro && (string) $stored === $ro ) {
			return self::cat( $slug, $field, $lang );
		}
		return (string) $stored;
	}
}
