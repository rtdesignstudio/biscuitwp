<?php
require_once __DIR__ . '/../includes/modules/class-thumbs.php';

$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat( 'a', 100 );
cmp_assert( CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', $jpeg ), 'valid_jpeg: good response accepted' );
cmp_assert( CMP_Thumbs::valid_jpeg( 200, 'image/jpeg; charset=binary', $jpeg ), 'valid_jpeg: content-type parameters allowed' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 404, 'image/jpeg', $jpeg ), 'valid_jpeg: non-200 rejected' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/png', $jpeg ), 'valid_jpeg: wrong content type rejected' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'text/html', $jpeg ), 'valid_jpeg: html rejected' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', '<html>' . str_repeat( 'a', 50 ) ), 'valid_jpeg: wrong magic bytes rejected' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', "\xFF\xD8" ), 'valid_jpeg: truncated magic rejected' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', '' ), 'valid_jpeg: empty body rejected' );
cmp_assert( CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', "\xFF\xD8\xFF" . str_repeat( 'a', CMP_Thumbs::MAX_BYTES - 3 ) ), 'valid_jpeg: exactly the size limit accepted' );
cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', "\xFF\xD8\xFF" . str_repeat( 'a', CMP_Thumbs::MAX_BYTES - 2 ) ), 'valid_jpeg: over the size limit rejected' );

cmp_assert( CMP_Thumbs::valid_id( 'dQw4w9WgXcQ' ), 'valid_id: good id' );
cmp_assert( CMP_Thumbs::valid_id( 'a-_B1234567' ), 'valid_id: dash and underscore' );
cmp_assert( ! CMP_Thumbs::valid_id( '../../etc/pa' ), 'valid_id: traversal rejected' );
cmp_assert( ! CMP_Thumbs::valid_id( 'dQw4w9WgXc' ), 'valid_id: short rejected' );
cmp_assert( ! CMP_Thumbs::valid_id( "dQw4w9WgXcQ\n" ), 'valid_id: trailing newline rejected' );
cmp_assert( ! CMP_Thumbs::valid_id( 'dQw4w9WgX/Q' ), 'valid_id: slash rejected' );
cmp_assert_eq( CMP_Thumbs::url( '../../x' ), '', 'url: invalid id returns empty without touching WP' );

cmp_assert( ! CMP_Thumbs::valid_jpeg( 200, 'image/jpeg', "\xFF\xD8\xFF" . str_repeat( 'a', CMP_Thumbs::MAX_BYTES - 2 ) ) && strlen( "\xFF\xD8\xFF" . str_repeat( 'a', CMP_Thumbs::MAX_BYTES - 2 ) ) === CMP_Thumbs::MAX_BYTES + 1, 'valid_jpeg: MAX_BYTES + 1 body with JPEG magic rejected (I1)' );

// Queue logic (pure).
$q = CMP_Thumbs::queue_add( array(), 'dQw4w9WgXcQ', 20 );
cmp_assert_eq( $q, array( 'dQw4w9WgXcQ' ), 'queue_add: adds a valid id' );
cmp_assert_eq( CMP_Thumbs::queue_add( $q, 'dQw4w9WgXcQ', 20 ), $q, 'queue_add: dedupes' );
cmp_assert_eq( CMP_Thumbs::queue_add( $q, '../../x', 20 ), $q, 'queue_add: invalid id ignored' );
cmp_assert_eq( CMP_Thumbs::queue_add( $q, "aaaaaaaaaaa\n", 20 ), $q, 'queue_add: id with newline ignored' );
$big = array();
for ( $i = 0; $i < 25; $i++ ) {
	$big = CMP_Thumbs::queue_add( $big, sprintf( 'id%09d', $i ), 20 );
}
cmp_assert_eq( count( $big ), 20, 'queue_add: capped at 20' );
cmp_assert_eq( $big[19], 'id000000019', 'queue_add: newest beyond the cap dropped' );
cmp_assert_eq( CMP_Thumbs::queue_take( array( 'a', 'b', 'c' ), 2 ), array( array( 'a', 'b' ), array( 'c' ) ), 'queue_take: taken and rest' );
cmp_assert_eq( CMP_Thumbs::queue_take( array( 'a' ), 5 ), array( array( 'a' ), array() ), 'queue_take: fewer than n' );
cmp_assert_eq( CMP_Thumbs::queue_take( array(), 5 ), array( array(), array() ), 'queue_take: empty' );
