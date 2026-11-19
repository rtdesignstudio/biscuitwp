<?php
require_once __DIR__ . '/../includes/modules/class-lang.php';

// normalize
cmp_assert_eq( CMP_Lang::normalize( 'en-US' ), 'en', 'normalize en-US -> en' );
cmp_assert_eq( CMP_Lang::normalize( 'ru_RU' ), 'ru', 'normalize ru_RU -> ru' );
cmp_assert_eq( CMP_Lang::normalize( 'ro' ), 'ro', 'normalize ro -> ro' );
cmp_assert_eq( CMP_Lang::normalize( 'zz' ), 'ro', 'unknown -> ro' );
cmp_assert_eq( CMP_Lang::normalize( '' ), 'ro', 'empty -> ro' );

// packs completeness: every text key + category present in all langs
$keys = array( 'title','intro','prefs','reject','accept','prefs_title','save','badge','locked','back','reopen','light_intro','privacy','embed_text','embed_load','consent_id','details' );
$slugs = array( 'necessary','statistics','marketing' );
foreach ( array( 'ro','ru','en' ) as $l ) {
	foreach ( $keys as $k ) {
		cmp_assert( '' !== CMP_Lang::text( $k, $l ), "text $k present in $l" );
	}
	foreach ( $slugs as $s ) {
		cmp_assert( '' !== CMP_Lang::cat( $s, 'label', $l ), "cat $s label in $l" );
		cmp_assert( '' !== CMP_Lang::cat( $s, 'description', $l ), "cat $s desc in $l" );
	}
}

// embed placeholder text names the service through %s
foreach ( array( 'ro','ru','en' ) as $l ) {
	cmp_assert( false !== strpos( CMP_Lang::text( 'embed_text', $l ), '%s' ), "embed_text has %s in $l" );
}

// text picks the right language
cmp_assert_eq( CMP_Lang::text( 'accept', 'en' ), 'Accept all', 'en accept' );
cmp_assert_eq( CMP_Lang::text( 'accept', 'ru' ), 'Разрешить все', 'ru accept' );

// localize_seed: unchanged RO seed becomes localized; custom stays
cmp_assert_eq( CMP_Lang::localize_seed( 'statistics', 'label', 'Statistici', 'en' ), 'Statistics', 'seed label localized to en' );
cmp_assert_eq( CMP_Lang::localize_seed( 'statistics', 'label', 'Analytics XYZ', 'en' ), 'Analytics XYZ', 'custom label kept' );

// B1: "Always load" copy in every language.
foreach ( array( 'ro','ru','en' ) as $l ) {
	foreach ( array( 'embed_always','always_loaded','always_remove' ) as $k ) {
		cmp_assert( '' !== CMP_Lang::text( $k, $l ), "text $k present in $l" );
	}
	cmp_assert( false !== strpos( CMP_Lang::text( 'always_remove', $l ), '%s' ), "always_remove has %s in $l" );
}
cmp_assert_eq( CMP_Lang::text( 'embed_always', 'ro' ), 'Încarcă mereu', 'ro embed_always' );
cmp_assert_eq( CMP_Lang::text( 'always_loaded', 'en' ), 'Always loaded:', 'en always_loaded' );
cmp_assert_eq( CMP_Lang::text( 'always_remove', 'ru' ), 'Больше не загружать %s', 'ru always_remove' );

// B4: re-consent copy in every language.
foreach ( array( 'ro','ru','en' ) as $l ) {
	foreach ( array( 'reconsent_title','reconsent_text','svc_new' ) as $k ) {
		cmp_assert( '' !== CMP_Lang::text( $k, $l ), "text $k present in $l" );
	}
}
cmp_assert_eq( CMP_Lang::text( 'reconsent_title', 'ro' ), 'Am adăugat servicii noi', 'ro reconsent_title' );
cmp_assert_eq( CMP_Lang::text( 'svc_new', 'ro' ), 'nou', 'ro svc_new' );
cmp_assert_eq( CMP_Lang::text( 'reconsent_text', 'ro' ), 'Pe site au apărut servicii noi în categoriile pe care le-ai acceptat. Alege din nou.', 'ro reconsent_text' );
cmp_assert_eq( CMP_Lang::text( 'reconsent_title', 'ru' ), 'Мы добавили новые сервисы', 'ru reconsent_title' );
cmp_assert_eq( CMP_Lang::text( 'svc_new', 'ru' ), 'новый', 'ru svc_new' );
cmp_assert_eq( CMP_Lang::text( 'reconsent_text', 'ru' ), 'На сайте появились новые сервисы в принятых вами категориях. Сделайте выбор снова.', 'ru reconsent_text' );
cmp_assert_eq( CMP_Lang::text( 'reconsent_title', 'en' ), 'We added new services', 'en reconsent_title' );
cmp_assert_eq( CMP_Lang::text( 'svc_new', 'en' ), 'new', 'en svc_new' );
cmp_assert_eq( CMP_Lang::text( 'reconsent_text', 'en' ), 'New services were added to categories you accepted. Please choose again.', 'en reconsent_text' );
