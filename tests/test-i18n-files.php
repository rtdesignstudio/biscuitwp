<?php
/**
 * Admin translations (B7.3): languages/*.po are complete, their placeholders
 * match the source, the .mo files are compiled, and the .pot matches the code.
 * Pure PHP: reads the .po/.pot files and tokenizes the plugin sources.
 * Fix any failure here with bin/i18n.sh (then translate what it reports).
 */

if ( ! function_exists( 'cmp_i18n_po_parse' ) ) {
	/** Decode one quoted .po string ("..."). */
	function cmp_i18n_po_unquote( $s ) {
		$s = substr( trim( $s ), 1, -1 );
		return stripcslashes( $s );
	}

	/**
	 * Parse a .po/.pot file into entries keyed by "ctx\x04msgid" (or msgid).
	 * Each entry: msgid, plural (or null), msgstr (array index => string), fuzzy.
	 * The header (empty msgid) is returned under '' too. Obsolete (#~) entries are skipped.
	 */
	function cmp_i18n_po_parse( $file ) {
		$entries = array();
		$lines   = file( $file, FILE_IGNORE_NEW_LINES );
		$cur     = null;
		$field   = null;
		$flush   = function () use ( &$cur, &$entries ) {
			if ( null !== $cur && null !== $cur['msgid'] ) {
				$key             = ( null !== $cur['ctx'] ? $cur['ctx'] . "\x04" : '' ) . $cur['msgid'];
				$entries[ $key ] = $cur;
			}
			$cur = null;
		};
		$fresh = function () {
			return array( 'ctx' => null, 'msgid' => null, 'plural' => null, 'msgstr' => array(), 'fuzzy' => false );
		};
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( '' === $line ) {
				$flush();
				$field = null;
				continue;
			}
			if ( 0 === strpos( $line, '#~' ) ) {
				continue;
			}
			if ( '#' === $line[0] ) {
				if ( null !== $cur && null !== $cur['msgid'] ) {
					$flush();
				}
				if ( null === $cur ) {
					$cur = $fresh();
				}
				if ( 0 === strpos( $line, '#,' ) && false !== strpos( $line, 'fuzzy' ) ) {
					$cur['fuzzy'] = true;
				}
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $m ) ) {
				if ( null === $cur ) {
					$cur = $fresh();
				}
				$val = cmp_i18n_po_unquote( $m[3] );
				if ( 'msgctxt' === $m[1] ) {
					if ( null !== $cur['msgid'] ) { // a new entry without a blank line before it
						$flush();
						$cur = $fresh();
					}
					$cur['ctx'] = $val;
					$field      = array( 'ctx' );
				} elseif ( 'msgid' === $m[1] ) {
					if ( null !== $cur['msgid'] ) {
						$flush();
						$cur = $fresh();
					}
					$cur['msgid'] = $val;
					$field        = array( 'msgid' );
				} elseif ( 'msgid_plural' === $m[1] ) {
					$cur['plural'] = $val;
					$field         = array( 'plural' );
				} else {
					$i                   = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
					$cur['msgstr'][ $i ] = $val;
					$field               = array( 'msgstr', $i );
				}
				continue;
			}
			if ( '"' === $line[0] && null !== $cur && null !== $field ) {
				$val = cmp_i18n_po_unquote( $line );
				if ( 'msgstr' === $field[0] ) {
					$cur['msgstr'][ $field[1] ] .= $val;
				} else {
					$cur[ $field[0] ] .= $val;
				}
			}
		}
		$flush();
		return $entries;
	}

	/** printf placeholders of a string, sorted (a multiset). %% is not a placeholder. */
	function cmp_i18n_placeholders( $s ) {
		$s = str_replace( '%%', '', (string) $s );
		preg_match_all( '/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $s, $m );
		$out = $m[0];
		sort( $out );
		return $out;
	}

	/** nplurals from a .po header entry. */
	function cmp_i18n_nplurals( array $header ) {
		$h = isset( $header['msgstr'][0] ) ? $header['msgstr'][0] : '';
		return preg_match( '/nplurals\s*=\s*(\d+)/', $h, $m ) ? (int) $m[1] : 2;
	}

	/**
	 * Read a compiled .mo file into original => translation (as msgfmt stores them:
	 * "ctx\x04msgid", plural joined with "\0"; plural translations joined with "\0").
	 *
	 * @return array|null Null when the file is not a valid .mo.
	 */
	function cmp_i18n_mo_parse( $file ) {
		$data = (string) @file_get_contents( $file );
		if ( strlen( $data ) < 28 ) {
			return null;
		}
		$magic = unpack( 'V', substr( $data, 0, 4 ) );
		$magic = $magic[1] & 0xffffffff;
		if ( 0x950412de === $magic ) {
			$f = 'V';
		} elseif ( 0xde120495 === $magic ) {
			$f = 'N';
		} else {
			return null;
		}
		$h   = unpack( "{$f}rev/{$f}n/{$f}o/{$f}t", substr( $data, 4, 16 ) );
		$out = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$o = unpack( "{$f}len/{$f}off", substr( $data, $h['o'] + 8 * $i, 8 ) );
			$t = unpack( "{$f}len/{$f}off", substr( $data, $h['t'] + 8 * $i, 8 ) );
			$out[ substr( $data, $o['off'], $o['len'] ) ] = substr( $data, $t['off'], $t['len'] );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** What msgfmt puts in the .mo for parsed .po entries: complete, non-fuzzy ones (header included). */
	function cmp_i18n_po_to_mo_map( array $entries ) {
		$out = array();
		foreach ( $entries as $key => $e ) {
			$strs = $e['msgstr'];
			ksort( $strs );
			if ( $e['fuzzy'] || ! $strs || in_array( '', $strs, true ) ) {
				continue;
			}
			$orig         = (string) $key . ( null !== $e['plural'] ? "\0" . $e['plural'] : '' );
			$out[ $orig ] = implode( "\0", $strs );
		}
		if ( isset( $out[''] ) ) { // msgfmt leaves POT-Creation-Date out of the compiled header
			$out[''] = preg_replace( '/^POT-Creation-Date:[^\n]*\n/m', '', $out[''] );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** Decode a PHP string literal token (single or double quoted). */
	function cmp_i18n_php_literal( $tok ) {
		$q    = $tok[0];
		$body = substr( $tok, 1, -1 );
		if ( "'" === $q ) {
			return str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $body );
		}
		return stripcslashes( $body );
	}

	/**
	 * Every translatable text in the PHP files under $dirs, as keys like the .pot's
	 * ("ctx\x04msgid", plus the plural as its own value). Only literal calls on the
	 * plugin's text domain count.
	 *
	 * @return array key => array( msgid, plural|null, file:line )
	 */
	function cmp_i18n_source_texts( array $dirs ) {
		// function => positions of (text, plural, context, domain); -1 = none.
		$fns = array(
			'__'         => array( 0, -1, -1, 1 ),
			'_e'         => array( 0, -1, -1, 1 ),
			'esc_html__' => array( 0, -1, -1, 1 ),
			'esc_html_e' => array( 0, -1, -1, 1 ),
			'esc_attr__' => array( 0, -1, -1, 1 ),
			'esc_attr_e' => array( 0, -1, -1, 1 ),
			'_n'         => array( 0, 1, -1, 3 ),
			'_x'         => array( 0, -1, 1, 2 ),
		);
		$out = array();
		foreach ( $dirs as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( 'php' !== strtolower( pathinfo( (string) $f, PATHINFO_EXTENSION ) ) ) {
					continue;
				}
				$toks = token_get_all( file_get_contents( (string) $f ) );
				$n    = count( $toks );
				for ( $i = 0; $i < $n; $i++ ) {
					$t = $toks[ $i ];
					if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $fns[ $t[1] ] ) ) {
						continue;
					}
					// Skip method calls / definitions: previous meaningful token.
					$p = $i - 1;
					while ( $p >= 0 && is_array( $toks[ $p ] ) && in_array( $toks[ $p ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						$p--;
					}
					if ( $p >= 0 && is_array( $toks[ $p ] ) && in_array( $toks[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
						continue;
					}
					$j = $i + 1;
					while ( $j < $n && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) {
						$j++;
					}
					if ( $j >= $n || '(' !== $toks[ $j ] ) {
						continue;
					}
					// Collect the top-level arguments: each is a literal string or null.
					$args  = array();
					$depth = 0;
					$arg   = array();
					for ( $k = $j + 1; $k < $n; $k++ ) {
						$x = $toks[ $k ];
						if ( is_array( $x ) && in_array( $x[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
							continue;
						}
						if ( '(' === $x || '[' === $x || '{' === $x ) {
							$depth++;
						} elseif ( ')' === $x || ']' === $x || '}' === $x ) {
							if ( 0 === $depth ) {
								$args[] = $arg;
								break;
							}
							$depth--;
						} elseif ( ',' === $x && 0 === $depth ) {
							$args[] = $arg;
							$arg    = array();
							continue;
						}
						$arg[] = $x;
					}
					$lit = function ( $idx ) use ( $args ) {
						if ( $idx < 0 || ! isset( $args[ $idx ] ) || 1 !== count( $args[ $idx ] ) ) {
							return null;
						}
						$a = $args[ $idx ][0];
						return is_array( $a ) && T_CONSTANT_ENCAPSED_STRING === $a[0] ? cmp_i18n_php_literal( $a[1] ) : null;
					};
					list( $ti, $pi, $ci, $di ) = $fns[ $t[1] ];
					if ( 'biscuitwp' !== $lit( $di ) ) {
						continue;
					}
					$text = $lit( $ti );
					if ( null === $text || '' === $text ) {
						continue;
					}
					$ctx         = $ci >= 0 ? $lit( $ci ) : null;
					$key         = ( null !== $ctx ? $ctx . "\x04" : '' ) . $text;
					$out[ $key ] = array( $text, $pi >= 0 ? $lit( $pi ) : null, basename( (string) $f ) . ':' . $t[2] );
				}
			}
		}
		return $out;
	}
}

$cmp_i18n_root = dirname( __DIR__ );
$cmp_i18n_dir  = $cmp_i18n_root . '/languages';
$cmp_i18n_pot  = $cmp_i18n_dir . '/biscuitwp.pot';

cmp_assert( is_file( $cmp_i18n_pot ), 'i18n: languages/biscuitwp.pot exists' );
$cmp_i18n_pot_entries = is_file( $cmp_i18n_pot ) ? cmp_i18n_po_parse( $cmp_i18n_pot ) : array();
unset( $cmp_i18n_pot_entries[''] );
cmp_assert( count( $cmp_i18n_pot_entries ) > 100, 'i18n: the .pot lists the admin texts (' . count( $cmp_i18n_pot_entries ) . ')' );

// The .pot is fresh: every literal text in includes/ and templates/ is in it.
$cmp_i18n_src     = cmp_i18n_source_texts( array( $cmp_i18n_root . '/includes', $cmp_i18n_root . '/templates' ) );
$cmp_i18n_missing = array();
foreach ( $cmp_i18n_src as $cmp_k => $cmp_v ) {
	if ( ! isset( $cmp_i18n_pot_entries[ $cmp_k ] ) ) {
		$cmp_i18n_missing[] = $cmp_v[2] . ' "' . $cmp_v[0] . '"';
	} elseif ( null !== $cmp_v[1] && $cmp_i18n_pot_entries[ $cmp_k ]['plural'] !== $cmp_v[1] ) {
		$cmp_i18n_missing[] = $cmp_v[2] . ' plural "' . $cmp_v[1] . '"';
	}
}
cmp_assert( count( $cmp_i18n_src ) > 100, 'i18n: the source scan finds the gettext calls (' . count( $cmp_i18n_src ) . ')' );
cmp_assert(
	! $cmp_i18n_missing,
	'i18n: the .pot is up to date with the code (run bin/i18n.sh)' . ( $cmp_i18n_missing ? ': ' . implode( '; ', array_slice( $cmp_i18n_missing, 0, 5 ) ) : '' )
);

// The scanner's own parsing, on a fixed snippet.
$cmp_i18n_tmp = sys_get_temp_dir() . '/cmp-i18n-scan-' . getmypid();
@mkdir( $cmp_i18n_tmp );
file_put_contents(
	$cmp_i18n_tmp . '/x.php',
	"<?php\n__( 'Unu', 'biscuitwp' );\nesc_html_e( 'Doi \\'trei\\'', 'biscuitwp' );\n_n( '%d pagină', '%d pagini', \$n, 'biscuitwp' );\n_x( 'Post', 'noun', 'biscuitwp' );\n__( 'Alt domeniu', 'other' );\n__( \$var, 'biscuitwp' );\n\$o->__( 'Metodă', 'biscuitwp' );\n"
);
$cmp_i18n_fx = cmp_i18n_source_texts( array( $cmp_i18n_tmp ) );
@unlink( $cmp_i18n_tmp . '/x.php' );
@rmdir( $cmp_i18n_tmp );
cmp_assert_eq(
	array_keys( $cmp_i18n_fx ),
	array( 'Unu', "Doi 'trei'", '%d pagină', "noun\x04Post" ),
	'i18n: the source scan reads literals on the plugin domain only'
);
cmp_assert_eq( $cmp_i18n_fx['%d pagină'][1], '%d pagini', 'i18n: the source scan reads the _n() plural' );

// Placeholder multiset helper.
cmp_assert_eq( cmp_i18n_placeholders( 'A %1$d din %2$d, 100%% %s' ), array( '%1$d', '%2$d', '%s' ), 'i18n: placeholders are read as a multiset (%% ignored)' );

foreach ( array( 'en_US', 'ru_RU' ) as $cmp_loc ) {
	$cmp_po = $cmp_i18n_dir . '/biscuitwp-' . $cmp_loc . '.po';
	$cmp_mo = $cmp_i18n_dir . '/biscuitwp-' . $cmp_loc . '.mo';
	cmp_assert( is_file( $cmp_po ), "i18n {$cmp_loc}: .po exists" );
	cmp_assert( is_file( $cmp_mo ), "i18n {$cmp_loc}: .mo exists" );
	if ( ! is_file( $cmp_po ) ) {
		continue;
	}
	$cmp_entries = cmp_i18n_po_parse( $cmp_po );
	// The .mo holds exactly the .po's translations (content, not mtimes: those are
	// arbitrary after a clone, zip or rsync). Fails when a .po is edited and not recompiled.
	$cmp_mo_map = is_file( $cmp_mo ) ? cmp_i18n_mo_parse( $cmp_mo ) : null;
	$cmp_po_map = cmp_i18n_po_to_mo_map( $cmp_entries );
	$cmp_mo_bad = array();
	if ( is_array( $cmp_mo_map ) ) {
		foreach ( $cmp_po_map + $cmp_mo_map as $cmp_k => $cmp_unused ) {
			if ( ! isset( $cmp_po_map[ $cmp_k ], $cmp_mo_map[ $cmp_k ] ) || $cmp_po_map[ $cmp_k ] !== $cmp_mo_map[ $cmp_k ] ) {
				$cmp_mo_bad[] = strtok( '' === $cmp_k ? '(header)' : (string) $cmp_k, "\0" );
			}
		}
	}
	cmp_assert(
		is_array( $cmp_mo_map ) && ! $cmp_mo_bad,
		"i18n {$cmp_loc}: .mo matches the .po (run bin/i18n.sh)" . ( $cmp_mo_bad ? ' (' . count( $cmp_mo_bad ) . ' differ, e.g. "' . $cmp_mo_bad[0] . '")' : '' )
	);
	$cmp_header  = isset( $cmp_entries[''] ) ? $cmp_entries[''] : array( 'msgstr' => array( '' ) );
	unset( $cmp_entries[''] );
	$cmp_np = cmp_i18n_nplurals( $cmp_header );
	cmp_assert_eq( $cmp_np, 'ru_RU' === $cmp_loc ? 3 : 2, "i18n {$cmp_loc}: Plural-Forms nplurals" );
	if ( 'ru_RU' === $cmp_loc ) {
		cmp_assert(
			false !== strpos( $cmp_header['msgstr'][0], 'plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),
			'i18n ru_RU: the Russian plural rule'
		);
	}

	$cmp_untr = array();
	$cmp_fuzz = array();
	$cmp_ph   = array();
	foreach ( $cmp_i18n_pot_entries as $cmp_k => $cmp_pe ) {
		if ( ! isset( $cmp_entries[ $cmp_k ] ) ) {
			$cmp_untr[] = $cmp_pe['msgid'];
			continue;
		}
		$cmp_e = $cmp_entries[ $cmp_k ];
		if ( $cmp_e['fuzzy'] ) {
			$cmp_fuzz[] = $cmp_pe['msgid'];
		}
		$cmp_forms = null !== $cmp_pe['plural'] ? $cmp_np : 1;
		for ( $cmp_i = 0; $cmp_i < $cmp_forms; $cmp_i++ ) {
			$cmp_str = isset( $cmp_e['msgstr'][ $cmp_i ] ) ? $cmp_e['msgstr'][ $cmp_i ] : '';
			if ( '' === $cmp_str ) {
				$cmp_untr[] = $cmp_pe['msgid'] . ( $cmp_forms > 1 ? " [{$cmp_i}]" : '' );
				continue;
			}
			$cmp_got = cmp_i18n_placeholders( $cmp_str );
			if ( null === $cmp_pe['plural'] ) {
				$cmp_ok = cmp_i18n_placeholders( $cmp_pe['msgid'] ) === $cmp_got;
			} else {
				// Every form matches the plural; with 2 forms the first may match the singular.
				$cmp_ok = cmp_i18n_placeholders( $cmp_pe['plural'] ) === $cmp_got
					|| ( 0 === $cmp_i && 2 === $cmp_np && cmp_i18n_placeholders( $cmp_pe['msgid'] ) === $cmp_got );
			}
			if ( ! $cmp_ok ) {
				$cmp_ph[] = $cmp_pe['msgid'] . ( $cmp_forms > 1 ? " [{$cmp_i}]" : '' ) . ' => ' . $cmp_str;
			}
		}
	}
	cmp_assert( ! $cmp_untr, "i18n {$cmp_loc}: every .pot text is translated" . ( $cmp_untr ? ' (' . count( $cmp_untr ) . ' missing, e.g. "' . $cmp_untr[0] . '")' : '' ) );
	cmp_assert( ! $cmp_fuzz, "i18n {$cmp_loc}: no fuzzy translations" . ( $cmp_fuzz ? ' (' . count( $cmp_fuzz ) . ', e.g. "' . $cmp_fuzz[0] . '")' : '' ) );
	cmp_assert( ! $cmp_ph, "i18n {$cmp_loc}: placeholders match the source" . ( $cmp_ph ? ' (' . implode( '; ', array_slice( $cmp_ph, 0, 3 ) ) . ')' : '' ) );
}

// The translations must load from <plugin folder>/languages, via plugin_basename() of the
// main file (handles a symlinked plugin folder; dirname() of the folder name alone was ".").
$cmp_plugin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
cmp_assert( false !== strpos( $cmp_plugin_src, "load_plugin_textdomain( 'biscuitwp', false, dirname( CMP_Plugin::basename() ) . '/languages' )" ), 'textdomain loads from <plugin folder>/languages (basename of the loaded entry file)' );
cmp_assert( 'biscuitwp/languages' === dirname( 'biscuitwp/biscuitwp.php' ) . '/languages', 'dirname() of the main file\'s plugin_basename is the folder name' );

// Text domain switch (biscuitwp): no gettext call in the plugin code uses the old domain.
// Only literal calls with an explicit domain are checked; the WordPress-core _n() without a
// domain (CMP_Updater::dedupe_update_data) is not one.
$cmp_old_domain_hits = array();
$cmp_old_files       = array( $cmp_i18n_root . '/biscuitwp.php', $cmp_i18n_root . '/uninstall.php', $cmp_i18n_root . '/consent-manager-pro.php' );
foreach ( array( 'includes', 'templates' ) as $cmp_d ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cmp_i18n_root . '/' . $cmp_d, FilesystemIterator::SKIP_DOTS ) ) as $cmp_f ) {
		if ( 'php' === strtolower( pathinfo( (string) $cmp_f, PATHINFO_EXTENSION ) ) ) {
			$cmp_old_files[] = (string) $cmp_f;
		}
	}
}
foreach ( $cmp_old_files as $cmp_f ) {
	if ( ! is_file( $cmp_f ) ) {
		continue;
	}
	if ( preg_match_all( '/\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\s*\([^;]*?[\'"]consent-manager-pro[\'"]\s*\)/s', file_get_contents( $cmp_f ), $cmp_m ) ) {
		$cmp_old_domain_hits[] = basename( $cmp_f ) . ' (' . count( $cmp_m[0] ) . ')';
	}
}
cmp_assert( ! $cmp_old_domain_hits, 'i18n: no gettext call uses the old text domain consent-manager-pro' . ( $cmp_old_domain_hits ? ': ' . implode( ', ', $cmp_old_domain_hits ) : '' ) );
cmp_assert( 0 === count( glob( $cmp_i18n_dir . '/consent-manager-pro*' ) ), 'i18n: no languages/consent-manager-pro* file remains' );
cmp_assert( false !== strpos( file_get_contents( $cmp_i18n_root . '/biscuitwp.php' ), 'Text Domain: biscuitwp' ) && false !== strpos( file_get_contents( $cmp_i18n_root . '/biscuitwp.php' ), 'Domain Path: /languages' ), 'i18n: the plugin header declares Text Domain biscuitwp and Domain Path /languages (Plugins-screen name and description translate)' );

// A known string translates through the compiled .mo files.
foreach ( array( 'ru_RU' => 'Файлы cookie', 'en_US' => 'Cookies' ) as $cmp_loc => $cmp_want ) {
	$cmp_map = cmp_i18n_mo_parse( $cmp_i18n_dir . '/biscuitwp-' . $cmp_loc . '.mo' );
	cmp_assert( is_array( $cmp_map ) && isset( $cmp_map['Cookie-uri'] ) && $cmp_want === $cmp_map['Cookie-uri'], "i18n {$cmp_loc}: biscuitwp-{$cmp_loc}.mo translates \"Cookie-uri\"" );
}

// M2 (final review): the 1.1.0 folder-move strings carry no em dash in any language (.mo checked, so a stale compile fails too).
foreach ( array( 'ru_RU', 'en_US' ) as $cmp_loc ) {
	$cmp_map  = cmp_i18n_mo_parse( $cmp_i18n_dir . '/biscuitwp-' . $cmp_loc . '.mo' );
	$cmp_dash = array();
	foreach ( (array) $cmp_map as $cmp_id => $cmp_str ) {
		if ( false !== strpos( (string) $cmp_id, 'folderul biscuitwp' ) && false !== strpos( (string) $cmp_str, "\u{2014}" ) ) {
			$cmp_dash[] = substr( (string) $cmp_id, 0, 60 );
		}
	}
	cmp_assert_eq( $cmp_dash, array(), "i18n {$cmp_loc}: folder-move strings have no em dash" );
}
