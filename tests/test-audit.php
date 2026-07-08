<?php
require_once __DIR__ . '/../includes/modules/class-audit.php';

// Dedup: same decision within 24h window => dedup true.
cmp_assert_eq( CMP_Audit::should_dedup( 1000, 1000 + 3600, 86400 ), true, 'same decision within 24h dedups' );
cmp_assert_eq( CMP_Audit::should_dedup( 1000, 1000 + 90000, 86400 ), false, 'beyond 24h does not dedup' );
cmp_assert_eq( CMP_Audit::should_dedup( null, 1000, 86400 ), false, 'no prior entry never dedups' );

// IP hash is deterministic sha256 with salt.
$h1 = CMP_Audit::hash_ip( '1.2.3.4', 'salt' );
$h2 = CMP_Audit::hash_ip( '1.2.3.4', 'salt' );
cmp_assert_eq( $h1, $h2, 'ip hash deterministic' );
cmp_assert_eq( strlen( $h1 ), 64, 'ip hash is sha256 (64 hex chars)' );
cmp_assert( $h1 !== CMP_Audit::hash_ip( '1.2.3.4', 'other' ), 'different salt => different hash' );
