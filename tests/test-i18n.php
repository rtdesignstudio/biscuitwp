<?php
require_once __DIR__ . '/../includes/modules/class-i18n.php';

cmp_assert_eq( CMP_I18n::resolve( 'Custom', 'Default' ), 'Custom', 'non-empty stored kept' );
cmp_assert_eq( CMP_I18n::resolve( '', 'Default' ), 'Default', 'empty stored falls back to default' );
cmp_assert_eq( CMP_I18n::resolve( '   ', 'Default' ), 'Default', 'whitespace-only falls back to default' );
cmp_assert_eq( CMP_I18n::resolve( '0', 'Default' ), '0', 'literal "0" is kept (not treated as empty)' );

// translate with no multilingual plugin returns the source value unchanged.
cmp_assert_eq( CMP_I18n::translate( 'cmp_x', 'Salut' ), 'Salut', 'translate falls back to source without WPML/Polylang' );
cmp_assert_eq( CMP_I18n::text( 'banner_title', '', 'Titlu implicit' ), 'Titlu implicit', 'text uses default when stored empty' );
