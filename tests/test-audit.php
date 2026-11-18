<?php
require_once __DIR__ . '/../includes/modules/class-audit.php';

// IP hash is deterministic sha256 with salt.
$h1 = CMP_Audit::hash_ip( '1.2.3.4', 'salt' );
$h2 = CMP_Audit::hash_ip( '1.2.3.4', 'salt' );
cmp_assert_eq( $h1, $h2, 'ip hash deterministic' );
cmp_assert_eq( strlen( $h1 ), 64, 'ip hash is sha256 (64 hex chars)' );
cmp_assert( $h1 !== CMP_Audit::hash_ip( '1.2.3.4', 'other' ), 'different salt => different hash' );

// UA hash: salted sha256 (was unsalted md5), deterministic, salt-dependent.
$u1 = CMP_Audit::hash_ua( 'Mozilla/5.0', 'salt' );
cmp_assert_eq( $u1, CMP_Audit::hash_ua( 'Mozilla/5.0', 'salt' ), 'ua hash deterministic' );
cmp_assert_eq( strlen( $u1 ), 64, 'ua hash is sha256 (64 hex chars)' );
cmp_assert( $u1 !== md5( 'Mozilla/5.0' ), 'ua hash is not plain md5' );
cmp_assert( $u1 !== CMP_Audit::hash_ua( 'Mozilla/5.0', 'other' ), 'ua hash depends on salt' );

// CSV/JSON export and prune.
$rows = array(
	array( 'id' => 1, 'ts' => '2026-01-01 10:00:00', 'decision' => 'accepted_all', 'categories' => 'necessary,statistics', 'lang' => 'ro', 'ip_hash' => 'abc', 'ua_hash' => 'def', 'url' => 'https://x/', 'version' => '1' ),
);
$csv = CMP_Audit::to_csv( $rows );
cmp_assert( strpos( $csv, "\xEF\xBB\xBF" ) === 0, 'CSV starts with UTF-8 BOM' );
cmp_assert( strpos( $csv, 'decision' ) !== false, 'CSV has header row' );
cmp_assert( strpos( $csv, 'accepted_all' ) !== false, 'CSV has data row' );

$json = CMP_Audit::to_json( $rows );
$back = json_decode( $json, true );
cmp_assert_eq( $back[0]['decision'], 'accepted_all', 'JSON round-trips decision' );

// Prune threshold: 13 months before 2026-02-01 12:00:00 UTC.
$now = gmmktime( 12, 0, 0, 2, 1, 2026 );
$threshold = CMP_Audit::prune_before_date( 13, $now );
cmp_assert_eq( $threshold, '2025-01-01 12:00:00', '13-month prune threshold computed' );

// --- Logging without a nonce (cache-safe): strict request validation. ---
$slugs = array( 'necessary', 'statistics', 'marketing' );

$ok = CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '["necessary","statistics","bogus"]', 'version' => '3' ), $slugs, '3' );
cmp_assert( is_array( $ok ), 'valid request parsed' );
cmp_assert_eq( $ok['decision'], 'partial', 'decision kept' );
cmp_assert_eq( $ok['categories'], 'necessary,statistics', 'unknown category dropped' );
cmp_assert_eq( $ok['version'], '3', 'version kept' );

cmp_assert_eq( CMP_Audit::parse_request( array( 'decision' => 'hacked', 'categories' => '[]' ), $slugs, '1' ), 'bad_decision', 'unknown decision rejected' );
cmp_assert_eq( CMP_Audit::parse_request( array(), $slugs, '1' ), 'bad_decision', 'missing decision rejected' );

$junk = CMP_Audit::parse_request( array( 'decision' => 'rejected_all', 'categories' => 'not json' ), $slugs, '1' );
cmp_assert_eq( $junk['categories'], '', 'non-JSON categories become empty' );

$weird = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]', 'version' => '<script>' ), $slugs, '2' );
cmp_assert_eq( $weird['version'], '2', 'malformed version falls back to the current one' );
$long = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]', 'version' => str_repeat( '9', 50 ) ), $slugs, '2' );
cmp_assert_eq( $long['version'], '2', 'overlong version falls back to the current one' );

// Origin: only our own site (or no Origin header) may log.
cmp_assert_eq( CMP_Audit::same_origin( 'https://example.md', 'https://example.md/' ), true, 'same origin allowed' );
cmp_assert_eq( CMP_Audit::same_origin( 'https://www.example.md', 'https://example.md/' ), false, 'other host rejected' );
cmp_assert_eq( CMP_Audit::same_origin( 'https://evil.com', 'https://example.md/' ), false, 'foreign origin rejected' );
cmp_assert_eq( CMP_Audit::same_origin( '', 'https://example.md/' ), true, 'missing Origin allowed (older browsers)' );
cmp_assert_eq( CMP_Audit::same_origin( 'null', 'https://example.md/' ), false, 'opaque origin rejected' );

// Rate limit per hashed IP.
cmp_assert_eq( CMP_Audit::throttled( 30, 30 ), false, 'at the limit still allowed' );
cmp_assert_eq( CMP_Audit::throttled( 31, 30 ), true, 'over the limit throttled' );
cmp_assert_eq( CMP_Audit::same_origin( 'https://example.md', 'http://example.md/' ), true, 'http home URL served over https still allowed' );

// --- 0.4.1: per-visitor consent ID (GDPR / Law 195 art. 7(1): demonstrate consent). ---
$cid = '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f';
$withid = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]', 'consent_id' => strtoupper( $cid ) ), $slugs, '1' );
cmp_assert_eq( $withid['consent_id'], $cid, 'valid consent ID kept (lowercased)' );
$badid = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]', 'consent_id' => "x' OR 1=1" ), $slugs, '1' );
cmp_assert_eq( $badid['consent_id'], '', 'malformed consent ID dropped' );
$noid = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]' ), $slugs, '1' );
cmp_assert_eq( $noid['consent_id'], '', 'missing consent ID is empty (old cached script)' );

// Repeat of the same choice by the same visitor is skipped; any change is logged.
$last = array( 'consent_id' => $cid, 'decision' => 'partial', 'categories' => 'necessary,statistics', 'version' => '1' );
cmp_assert_eq( CMP_Audit::is_repeat( $last, $last ), true, 'identical choice from same ID is a repeat' );
cmp_assert_eq( CMP_Audit::is_repeat( $last, array_merge( $last, array( 'categories' => 'necessary' ) ) ), false, 'changed categories are logged' );
cmp_assert_eq( CMP_Audit::is_repeat( $last, array_merge( $last, array( 'version' => '2' ) ) ), false, 'new policy version is logged' );
cmp_assert_eq( CMP_Audit::is_repeat( null, $last ), false, 'first record is never a repeat' );
cmp_assert_eq( CMP_Audit::is_repeat( array_merge( $last, array( 'consent_id' => '' ) ), array_merge( $last, array( 'consent_id' => '' ) ) ), false, 'rows without ID are never merged' );

// Exports carry the consent ID.
$cmp_csv2 = CMP_Audit::to_csv( array( array( 'id' => 2, 'consent_id' => $cid, 'decision' => 'partial' ) ) );
cmp_assert( false !== strpos( $cmp_csv2, 'consent_id' ) && false !== strpos( $cmp_csv2, $cid ), 'CSV export includes consent_id' );
