<?php
/**
 * Termin-Fotos nur für Mitglieder – und die Code-Anforderung, die nichts
 * über Konten verrät.
 *
 * Aufruf im Plugin-Ordner:   php tools/fototest.php
 *
 * Bis 1.15.6 lagen Fotos als gewöhnliche Medien in uploads/: abrufbar für
 * jeden mit der Adresse, gelistet in /wp/v2/media samt „Quelle: <Name>“.
 * Und /auth/request-otp sagte „kein Konto“ oder „nicht freigeschaltet“ –
 * eine Auskunft über jede beliebige Adresse.
 *
 * Geprüft wird: Ablageort und Sperrdatei, die Adressen, die die Terminliste
 * bekommt, das Heraushalten aus der Mediathek und die einheitliche Antwort
 * beim Code-Anfordern. Die Auslieferung selbst (readfile, Header) läuft nur
 * in WordPress und ist hier nicht abgedeckt.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

// ---------------------------------------------------------------------
// Was der Ersatz für diesen Test zusätzlich braucht
// ---------------------------------------------------------------------
$GLOBALS['foto_basis'] = sys_get_temp_dir() . '/vp-fototest-' . getmypid();
@mkdir( $GLOBALS['foto_basis'], 0777, true );

if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; } }
if ( ! function_exists( 'wp_mkdir_p' ) )      { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); } }
if ( ! function_exists( 'wp_upload_dir' ) )   { function wp_upload_dir( ...$a ) { return [ 'basedir' => $GLOBALS['foto_basis'], 'baseurl' => 'https://verein.test/wp-content/uploads', 'error' => false ]; } }
if ( ! function_exists( 'admin_url' ) )       { function admin_url( $p = '' ) { return 'https://verein.test/wp-admin/' . $p; } }

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( public $data = null, public int $status = 200 ) {}
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        public function __construct( private array $p = [] ) {}
        public function get_param( $k ) { return $this->p[ $k ] ?? null; }
        public function get_header( $k ) { return null; }
    }
}

stub_load( [
    'Core/Activator.php',
    'Support/Capabilities.php',
    'Members/MemberModel.php',
    'Members/PeopleModel.php',
    'Members/Person.php',
    'Members/MemberService.php',
    'Admin/Settings.php',
    'Auth/OtpModel.php',
    'Auth/SessionModel.php',
    'Auth/AuthService.php',
    'Auth/AuthController.php',
    'Photos/PhotoModel.php',
    'Photos/ProtectedPhotos.php',
] );

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Core\Activator;
use VereinsPlugin\Photos\PhotoModel;
use VereinsPlugin\Photos\ProtectedPhotos;

stub_default_roles();
Activator::register_roles();

// =====================================================================
echo "Ablage im gesperrten Ordner\n";

$dirs = ProtectedPhotos::upload_dir( [
    'basedir' => '/srv/uploads', 'baseurl' => 'https://verein.test/wp-content/uploads',
    'path'    => '/srv/uploads/2026/11', 'url' => 'https://verein.test/wp-content/uploads/2026/11',
    'subdir'  => '/2026/11',
] );
Pruefung::gleich( 'Pfad liegt unter vereins-fotos', $dirs['path'], '/srv/uploads/vereins-fotos/2026/11' );
Pruefung::gleich( 'Unterordner fürs Attachment', $dirs['subdir'], '/vereins-fotos/2026/11' );

Pruefung::ok( 'Sperre lässt sich anlegen', ProtectedPhotos::ensure_guard() );
$sperre = $GLOBALS['foto_basis'] . '/vereins-fotos/.htaccess';
Pruefung::ok( '.htaccess vorhanden', is_file( $sperre ) );
Pruefung::ok( '.htaccess weist alles ab', str_contains( (string) @file_get_contents( $sperre ), 'Require all denied' ) );
Pruefung::ok( 'zweiter Aufruf schadet nicht', ProtectedPhotos::ensure_guard() );

// =====================================================================
echo "\nTerminliste bekommt keine Datei-Adressen mehr\n";

global $wpdb;
$petra = stub_user( 'Petra', [ Activator::ROLE_MEMBER ], 'petra@example.test' );
$wpdb->insert( 'wp_vp_event_photos', [ 'event_id' => 4, 'instance_date' => '2026-11-20', 'wp_user_id' => $petra, 'attachment_id' => 777, 'file_size' => 2048 ] );

$fotos = ( new PhotoModel() )->for_instance( 4, '2026-11-20', $petra );
Pruefung::gleich( 'ein Foto gelistet', count( $fotos ), 1 );
if ( $fotos ) {
    Pruefung::ok( 'Vollbild nicht aus uploads/', ! str_contains( $fotos[0]['full'], '/uploads/' ) );
    Pruefung::ok( 'Vorschau nicht aus uploads/', ! str_contains( $fotos[0]['thumb'], '/uploads/' ) );
    Pruefung::ok( 'Auslieferung über admin-post.php', str_contains( $fotos[0]['full'], 'admin-post.php' ) || str_contains( $fotos[0]['full'], 'verein.test/' ) );
}

// =====================================================================
echo "\nMediathek und Schnittstelle\n";

$gast = ( new ProtectedPhotos() )->hide_in_media_query( [ 'post_type' => 'attachment' ] );
$nicht = array_filter( $gast['meta_query'] ?? [], static fn( $q ) => ( $q['key'] ?? '' ) === ProtectedPhotos::META && ( $q['compare'] ?? '' ) === 'NOT EXISTS' );
Pruefung::ok( 'ohne Upload-Recht: Fotos ausgefiltert', count( $nicht ) === 1 );

$redakteur = stub_user( 'Rita', [ 'editor' ], 'rita@example.test' );
$GLOBALS['stub_current'] = $redakteur;
$red = ( new ProtectedPhotos() )->hide_in_media_query( [ 'post_type' => 'attachment' ] );
Pruefung::ok( 'Redakteur sieht die Fotos (Weiterverwendung)', empty( $red['meta_query'] ) );
$GLOBALS['stub_current'] = 0;

// =====================================================================
echo "\nCode anfordern: dieselbe Antwort für jede Adresse\n";

$auth = new AuthController();
$mit  = $auth->request_otp( new WP_REST_Request( [ 'identifier' => 'petra@example.test', 'channel' => 'email' ] ) );
$ohne = $auth->request_otp( new WP_REST_Request( [ 'identifier' => 'niemand@example.test', 'channel' => 'email' ] ) );

Pruefung::ok( 'mit Konto: Antwort ohne Fehler', $mit instanceof WP_REST_Response );
Pruefung::ok( 'ohne Konto: auch keine Fehlermeldung', $ohne instanceof WP_REST_Response );
if ( $mit instanceof WP_REST_Response && $ohne instanceof WP_REST_Response ) {
    Pruefung::gleich( 'gleicher Status', $ohne->get_status(), $mit->get_status() );
    Pruefung::gleich( 'gleicher Text', $ohne->get_data(), $mit->get_data() );
}

// Aufräumen
@unlink( $sperre );
@unlink( $GLOBALS['foto_basis'] . '/vereins-fotos/index.php' );
@rmdir( $GLOBALS['foto_basis'] . '/vereins-fotos' );
@rmdir( $GLOBALS['foto_basis'] );

Pruefung::abschluss( 'Fotos und Code-Anforderung' );
