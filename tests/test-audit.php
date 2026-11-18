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

// --- B1: "Always load" service decisions and the services column. ---
$svc_ok = CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '["marketing"]', 'services' => '["youtube","bogus"]' ), $slugs, '1' );
cmp_assert( is_array( $svc_ok ), 'service_allow is an accepted decision' );
cmp_assert_eq( $svc_ok['services'], 'youtube', 'services keeps only known ids, as CSV' );
cmp_assert( is_array( CMP_Audit::parse_request( array( 'decision' => 'service_withdraw', 'categories' => '[]', 'services' => '["youtube"]' ), $slugs, '1' ) ), 'service_withdraw is an accepted decision' );
$svc_none = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '[]' ), $slugs, '1' );
cmp_assert_eq( $svc_none['services'], '', 'missing services is empty' );
$svc_bad = CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '[]', 'services' => 'not json' ), $slugs, '1' );
cmp_assert_eq( $svc_bad['services'], '', 'malformed services is empty' );
$svc_dup = CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '[]', 'services' => '["youtube","youtube","vimeo"]' ), $slugs, '1' );
cmp_assert_eq( $svc_dup['services'], 'youtube,vimeo', 'duplicate service ids collapsed' );
$svc_inj = CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '[]', 'services' => '[["youtube"],"x\' OR 1=1"]' ), $slugs, '1' );
cmp_assert_eq( $svc_inj['services'], '', 'non-string and unknown services dropped' );
$svc_ex = CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '[]', 'services' => '["a","youtube"]' ), $slugs, '1', array( 'a', 'youtube' ) );
cmp_assert_eq( $svc_ex['services'], 'a,youtube', 'explicit valid service list honored' );
$svc_long = CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '[]', 'services' => json_encode( array_fill( 0, 40, 'abcdefghij' ) ) ), $slugs, '1', array( 'abcdefghij' ) );
cmp_assert( strlen( $svc_long['services'] ) <= 255, 'services truncated to 255' );

$last_s = array( 'consent_id' => $cid, 'decision' => 'service_allow', 'categories' => '', 'version' => '1', 'services' => 'youtube' );
cmp_assert_eq( CMP_Audit::is_repeat( $last_s, $last_s ), true, 'same services is a repeat' );
cmp_assert_eq( CMP_Audit::is_repeat( $last_s, array_merge( $last_s, array( 'services' => 'youtube,vimeo' ) ) ), false, 'a differing services list is not a repeat' );
cmp_assert_eq( CMP_Audit::is_repeat( $last, array_merge( $last, array( 'services' => '' ) ) ), true, 'absent services equals empty services' );

$cmp_csv3 = CMP_Audit::to_csv( array( array( 'id' => 3, 'consent_id' => $cid, 'decision' => 'service_allow', 'services' => 'youtube' ) ) );
cmp_assert( false !== strpos( $cmp_csv3, 'services' ) && false !== strpos( $cmp_csv3, '"youtube"' ), 'CSV export includes services' );
cmp_assert( false !== strpos( CMP_Audit::to_json( array( array( 'id' => 3, 'services' => 'youtube' ) ) ), '"services":"youtube"' ), 'JSON export includes services' );

// Fix round 1: M2 (truncate at a comma) and M3 (service decisions need services).
$mid = array();
for ( $i = 0; $i < 30; $i++ ) {
	$mid[] = 'svc' . str_pad( (string) $i, 7, '0', STR_PAD_LEFT ); // 10 chars
}
$trunc = CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '[]', 'services' => json_encode( $mid ) ), $slugs, '1', $mid );
cmp_assert( strlen( $trunc['services'] ) <= 255, 'services within 255 after truncation' );
cmp_assert( in_array( substr( $trunc['services'], strrpos( ',' . $trunc['services'], ',' ) ), $mid, true ), 'truncation never cuts an id in the middle' );
cmp_assert_eq( CMP_Audit::parse_request( array( 'decision' => 'service_allow', 'categories' => '[]', 'services' => '["bogus"]' ), $slugs, '1' ), 'bad_services', 'service_allow with no known service is rejected' );
cmp_assert_eq( CMP_Audit::parse_request( array( 'decision' => 'service_withdraw', 'categories' => '[]' ), $slugs, '1' ), 'bad_services', 'service_withdraw without services is rejected' );
cmp_assert( is_array( CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '[]' ), $slugs, '1' ) ), 'category decisions need no services' );

// --- B4: category decisions log the services of the accepted categories. ---
$b4_all = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '["necessary","marketing"]', 'services' => '["meta-pixel","bogus","tiktok-pixel"]' ), $slugs, '1', array( 'meta-pixel', 'tiktok-pixel' ) );
cmp_assert( is_array( $b4_all ), 'B4 accepted_all with services is accepted' );
cmp_assert_eq( $b4_all['services'], 'meta-pixel,tiktok-pixel', 'B4 accepted_all keeps only the active service ids, as CSV' );
$b4_part = CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '["necessary","statistics"]', 'services' => '["google-analytics","gone"]' ), $slugs, '1', array( 'google-analytics' ) );
cmp_assert_eq( $b4_part['services'], 'google-analytics', 'B4 partial keeps only the active service ids' );
$b4_empty = CMP_Audit::parse_request( array( 'decision' => 'accepted_all', 'categories' => '["necessary"]', 'services' => '[]' ), $slugs, '1', array( 'meta-pixel' ) );
cmp_assert( is_array( $b4_empty ), 'B4 accepted_all with an empty services list is allowed' );
cmp_assert_eq( $b4_empty['services'], '', 'B4 empty services list logs an empty column' );
$b4_unknown = CMP_Audit::parse_request( array( 'decision' => 'partial', 'categories' => '["necessary"]', 'services' => '["bogus"]' ), $slugs, '1', array( 'meta-pixel' ) );
cmp_assert( is_array( $b4_unknown ) && '' === $b4_unknown['services'], 'B4 partial with only unknown services is allowed, logged empty' );
$b4_rej = CMP_Audit::parse_request( array( 'decision' => 'rejected_all', 'categories' => '["necessary"]', 'services' => '[]' ), $slugs, '1', array( 'meta-pixel' ) );
cmp_assert( is_array( $b4_rej ), 'B4 rejected_all with an empty services list is allowed' );
