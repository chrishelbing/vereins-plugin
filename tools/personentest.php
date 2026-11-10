<?php
/**
 * Der Lebenslauf einer Person prüfen.
 *
 * Aufruf im Plugin-Ordner:   php tools/personentest.php
 *
 * Vom ersten Klick auf einen geteilten Terminlink bis zur Aufnahme in den
 * Verein. Entscheidend ist dabei zweierlei:
 *
 *   – Solange jemand nicht aufgenommen ist, entsteht KEIN WordPress-Profil.
 *     Sonst füllt sich die Nutzerverwaltung mit Leuten, die einmal zu einem
 *     Sommerfest zugesagt haben.
 *   – Bei der Aufnahme darf nichts verlorengehen. Zusagen und übernommene
 *     Aufgaben hängen an einer negativen Personen-ID und müssen auf die neue
 *     Nutzer-ID umziehen.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

stub_load( [
    'Core/Activator.php',
    'Support/Capabilities.php',
    'Support/PublicLink.php',
    'Members/MemberModel.php',
    'Members/PeopleModel.php',
    'Members/Person.php',
    'Members/MemberService.php',
    'Members/PeopleMigration.php',
    'Members/PeopleCleanup.php',
    'Admin/Settings.php',
    'Events/EventModel.php',
    'Events/CompanionModel.php',
    'Events/ItemModel.php',
    'Photos/PhotoModel.php',
    'Photos/PhotoService.php',
    'Auth/OtpModel.php',
    'Privacy/PersonalData.php',
] );

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Members\GuestService;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\PeopleCleanup;
use VereinsPlugin\Members\PeopleMigration;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\PeopleModel;
use VereinsPlugin\Photos\PhotoService;
use VereinsPlugin\Privacy\PersonalData;

require_once dirname( __DIR__ ) . '/includes/Members/GuestService.php';

stub_default_roles();
Activator::register_roles();

$wpdb    = $GLOBALS['wpdb'];
$gast    = new GuestService();
$service = new MemberService();

$nutzerzahl = static fn(): int => count( $GLOBALS['stub_users'] );
$personen   = static fn(): int => count( $GLOBALS['wpdb']->t['wp_vp_people'] ?? [] );
$zusagen    = static fn(): int => count( $GLOBALS['wpdb']->t['wp_vp_event_attendance'] ?? [] );

// =====================================================================
echo "Gast über einen Terminlink\n";

$ergebnis  = $gast->identify( [ 'name' => 'Anna Berger', 'email' => 'anna@example.test' ], 'https://verein.test/termine' );
$person_id = $ergebnis['person_id'];

Pruefung::ok( 'bekommt eine negative Personen-ID', Person::is_guest( $person_id ) );
Pruefung::gleich( 'kein WordPress-Profil angelegt', $nutzerzahl(), 0 );
Pruefung::gleich( 'ein Eintrag in vp_people', $personen(), 1 );
Pruefung::gleich( 'Name wird aufgelöst', Person::name( $person_id ), 'Anna Berger' );

// Zusage und übernommene Aufgabe hängen an dieser ID
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 7, 'instance_date' => '2026-09-11', 'person_id' => $person_id, 'response' => 'yes' ] );
$wpdb->insert( 'wp_vp_event_item_claims', [ 'item_id' => 3, 'person_id' => $person_id, 'companion_id' => 0, 'quantity' => 1 ] );

echo "\nZweiter Besuch mit derselben Adresse\n";
$wieder = $gast->identify( [ 'name' => 'Anna Berger', 'email' => 'anna@example.test' ] );
Pruefung::gleich( 'dieselbe Person, kein Doppeleintrag', $wieder['person_id'], $person_id );
Pruefung::gleich( 'weiterhin ein Eintrag', $personen(), 1 );

echo "\nKontakt bestätigen\n";
$gast->confirm( $person_id );
Pruefung::ok( 'gilt als bestätigt', ! Person::unconfirmed_map( [ $person_id ] ) );

// =====================================================================
echo "\nAufnahme in den Verein\n";

Person::flush_cache();
$aufnahme = $service->accept_person( $person_id );
Pruefung::ok( 'Aufnahme gelingt', ! is_wp_error( $aufnahme ) );

if ( ! is_wp_error( $aufnahme ) ) {
    $neu = $aufnahme['wp_user_id'];

    Pruefung::gleich( 'jetzt genau ein WordPress-Profil', $nutzerzahl(), 1 );
    Pruefung::gleich( 'vp_people ist leer', $personen(), 0 );
    Pruefung::gleich( 'Zusage zeigt auf die neue ID', (int) $wpdb->t['wp_vp_event_attendance'][0]['person_id'], $neu );
    Pruefung::gleich( 'Übernahme zeigt auf die neue ID', (int) $wpdb->t['wp_vp_event_item_claims'][0]['person_id'], $neu );
    Pruefung::ok( 'Bestätigung wurde übernommen', ! empty( $wpdb->t['wp_vp_members'][0]['confirmed_at'] ) );
    Pruefung::gleich( 'Mitglied ist aktiv', (string) $wpdb->t['wp_vp_members'][0]['status'], 'active' );
}

// =====================================================================
echo "\nAblehnung\n";

$zweiter = $gast->identify( [ 'name' => 'Bernd Klein', 'email' => 'bernd@example.test' ] )['person_id'];
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 7, 'instance_date' => '2026-09-11', 'person_id' => $zweiter, 'response' => 'yes' ] );

$vorher = $zusagen();
$service->reject_person( $zweiter );

Pruefung::gleich( 'Eintrag entfernt', $personen(), 0 );
Pruefung::gleich( 'seine Zusage entfernt', $zusagen(), $vorher - 1 );
Pruefung::gleich( 'kein WordPress-Profil entstanden', $nutzerzahl(), 1 );

// =====================================================================
echo "\nVorhandener Redakteur sagt zu und wird aufgenommen\n";

$redakteur = stub_user( 'Rita Roth', [ 'editor' ], 'rita@example.test' );
$gast->identify( [ 'name' => 'Rita Roth', 'email' => 'rita@example.test' ] );

Pruefung::gleich( 'kein zweiter Eintrag für sie', $personen(), 0 );
Pruefung::gleich( 'kein zweites Konto', $nutzerzahl(), 2 );

// =====================================================================
echo "\nBestandsübernahme aus früheren Fassungen\n";

// Nutzer, wie ihn frühere Fassungen für jede Zusage angelegt haben
$alt = stub_user( 'Alter Gast', [ Activator::ROLE_MEMBER ], 'alt@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $alt, 'display_name' => 'Alter Gast', 'status' => 'pending', 'confirmed_at' => null ] );
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 8, 'instance_date' => '2026-10-01', 'person_id' => $alt, 'response' => 'yes' ] );

// Ein Autor mit Beitrag – der darf niemals angefasst werden
$autor = stub_user( 'Carla Autorin', [ 'author' ], 'carla@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $autor, 'display_name' => 'Carla Autorin', 'status' => 'pending', 'confirmed_at' => null ] );
$wpdb->insert( 'wp_posts', [ 'post_author' => $autor, 'post_title' => 'Vereinsfest' ] );

// Ein aktives Mitglied bleibt ebenfalls unberührt
$aktiv = stub_user( 'Dora Feld', [ Activator::ROLE_MEMBER ], 'dora@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $aktiv, 'display_name' => 'Dora Feld', 'status' => 'active' ] );

$erg = PeopleMigration::run();

Pruefung::gleich( 'ein Zugang übernommen', $erg['übernommen'], 1 );
Pruefung::ok( 'Autorin mit Beitrag übersprungen', isset( $GLOBALS['stub_users'][ $autor ] ) );
Pruefung::ok( 'aktives Mitglied unberührt', isset( $GLOBALS['stub_users'][ $aktiv ] ) );
Pruefung::ok( 'alter Gastzugang entfernt', ! isset( $GLOBALS['stub_users'][ $alt ] ) );

$umgehängt = null;
foreach ( $wpdb->t['wp_vp_event_attendance'] as $row ) {
    if ( (int) $row['event_id'] === 8 ) {
        $umgehängt = (int) $row['person_id'];
    }
}
Pruefung::ok( 'seine Zusage hängt jetzt an einer Personen-ID', $umgehängt !== null && Person::is_guest( $umgehängt ) );

// =====================================================================
echo "\nFoto-Upload ohne Zustimmung\n";

// Die Zustimmung ist Pflicht und wird vor jeder weiteren Verarbeitung
// geprüft – ein leeres Datei-Array reicht hier, weil die Ablehnung erfolgt,
// bevor überhaupt an die Datei gerührt wird.
$fotoservice      = new PhotoService();
$ohne_zustimmung  = $fotoservice->store( [], 8, '2026-10-01', $aktiv, false );

Pruefung::ok( 'Upload ohne Häkchen wird abgelehnt', is_wp_error( $ohne_zustimmung ) );
Pruefung::gleich(
    'Fehlercode benennt die fehlende Zustimmung',
    is_wp_error( $ohne_zustimmung ) ? $ohne_zustimmung->get_error_code() : '',
    'consent_required'
);

// =====================================================================
echo "\nLöschung eines Mitglieds (Recht auf Löschung)\n";

// Reines Vereinsmitglied ohne Website-Rolle und ohne eigene Beiträge:
// hier darf auch das WordPress-Konto mit weg.
$loeschbar = stub_user( 'Erika Musterfrau', [ Activator::ROLE_MEMBER ], 'erika@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $loeschbar, 'display_name' => 'Erika Musterfrau', 'status' => 'active' ] );
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 9, 'instance_date' => '2026-11-01', 'person_id' => $loeschbar, 'response' => 'yes' ] );

$loeschergebnis = $service->erase_member( $loeschbar );
Pruefung::ok( 'Löschung gelingt', ! is_wp_error( $loeschergebnis ) );
if ( ! is_wp_error( $loeschergebnis ) ) {
    Pruefung::ok( 'Konto ohne Website-Inhalte wird mitgelöscht', $loeschergebnis['account_deleted'] );
}
Pruefung::ok( 'WordPress-Profil ist weg', ! isset( $GLOBALS['stub_users'][ $loeschbar ] ) );

$verbleibend = array_filter(
    $wpdb->t['wp_vp_event_attendance'] ?? [],
    static fn( $r ) => (int) $r['person_id'] === $loeschbar
);
Pruefung::gleich( 'Zusage entfernt', count( $verbleibend ), 0 );

// Redakteur mit eigenem Beitrag: das Konto muss stehen bleiben – sonst
// verschwände ein Beitrag der Website, nur weil jemand auch im Verein war.
$geschuetzt = stub_user( 'Frank Redaktion', [ 'editor', Activator::ROLE_MEMBER ], 'frank@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $geschuetzt, 'display_name' => 'Frank Redaktion', 'status' => 'active' ] );
$wpdb->insert( 'wp_posts', [ 'post_author' => $geschuetzt, 'post_title' => 'Vereinsnachrichten' ] );

$geschuetzt_ergebnis = $service->erase_member( $geschuetzt );
Pruefung::ok( 'Löschung der Vereinsdaten gelingt', ! is_wp_error( $geschuetzt_ergebnis ) );
if ( ! is_wp_error( $geschuetzt_ergebnis ) ) {
    Pruefung::ok( 'Konto mit eigenem Beitrag bleibt bestehen', ! $geschuetzt_ergebnis['account_deleted'] );
}
Pruefung::ok( 'WordPress-Profil bleibt erhalten', isset( $GLOBALS['stub_users'][ $geschuetzt ] ) );
Pruefung::ok(
    'Vereinsrolle wurde entzogen',
    ! in_array( Activator::ROLE_MEMBER, (array) $GLOBALS['stub_users'][ $geschuetzt ]->roles, true )
);
Pruefung::ok(
    'Redakteursrolle bleibt',
    in_array( 'editor', (array) $GLOBALS['stub_users'][ $geschuetzt ]->roles, true )
);

// =====================================================================
echo "\nDatenschutz-Werkzeuge: Auskunft und Löschung über WordPress-Bordmittel\n";

$privacy = new PersonalData();

// Mitglied mit Zusage, Übernahme, Begleitperson und Foto.
$petra_id = stub_user( 'Petra Privacy', [ Activator::ROLE_MEMBER ], 'petra@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $petra_id, 'display_name' => 'Petra Privacy', 'status' => 'active' ] );
$wpdb->insert( 'wp_vp_events', [ 'title' => 'Herbstfest', 'start_at' => '2026-10-10 15:00:00', 'end_at' => '2026-10-10 20:00:00' ] );
$event_id = (int) end( $wpdb->t['wp_vp_events'] )['id'];

$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => $event_id, 'instance_date' => '2026-10-10', 'person_id' => $petra_id, 'response' => 'yes' ] );
$wpdb->insert( 'wp_vp_event_items', [ 'event_id' => $event_id, 'instance_date' => '2026-10-10', 'kind' => 'bring', 'label' => 'Kuchen', 'created_by' => $petra_id ] );
$item_id = (int) end( $wpdb->t['wp_vp_event_items'] )['id'];
$wpdb->insert( 'wp_vp_event_item_claims', [ 'item_id' => $item_id, 'person_id' => $petra_id, 'companion_id' => 0, 'quantity' => 1 ] );
$wpdb->insert( 'wp_vp_member_companions', [ 'person_id' => $petra_id, 'name' => 'Tom Privacy' ] );
$wpdb->insert( 'wp_vp_event_photos', [ 'event_id' => $event_id, 'instance_date' => '2026-10-10', 'wp_user_id' => $petra_id, 'attachment_id' => 501, 'file_size' => 1024 ] );

$export = $privacy->export( 'petra@example.test' );
Pruefung::ok( 'Export liefert Gruppen', ! empty( $export['data'] ) );
Pruefung::ok( 'Export enthält die Zusage', (bool) array_filter( $export['data'], fn( $g ) => $g['group_id'] === 'vp_zusagen' ) );
Pruefung::ok( 'Export enthält die Übernahme', (bool) array_filter( $export['data'], fn( $g ) => $g['group_id'] === 'vp_uebernahmen' ) );
Pruefung::ok( 'Export enthält die Begleitperson', (bool) array_filter( $export['data'], fn( $g ) => $g['group_id'] === 'vp_begleitpersonen' ) );
Pruefung::ok( 'Export enthält das Foto', (bool) array_filter( $export['data'], fn( $g ) => $g['group_id'] === 'vp_fotos' ) );

$loeschung = $privacy->erase( 'petra@example.test' );
Pruefung::ok( 'Löschung meldet entfernte Daten', $loeschung['items_removed'] );
Pruefung::ok( 'WordPress-Profil ist weg (keine Website-Inhalte)', ! isset( $GLOBALS['stub_users'][ $petra_id ] ) );

$export_danach = $privacy->export( 'petra@example.test' );
Pruefung::gleich( 'Nach Löschung nichts mehr zu exportieren', count( $export_danach['data'] ), 0 );

// Gast über Terminlink, ohne WordPress-Profil.
$gast->identify( [ 'name' => 'Gustav Gast', 'email' => 'gustav@example.test' ], 'https://verein.test/termine' );

$export_gast = $privacy->export( 'gustav@example.test' );
Pruefung::ok( 'Export findet auch offenen Kontakt', (bool) array_filter( $export_gast['data'], fn( $g ) => $g['group_id'] === 'vp_kontakt' ) );

$loeschung_gast = $privacy->erase( 'gustav@example.test' );
Pruefung::ok( 'Löschung des offenen Kontakts gelingt', $loeschung_gast['items_removed'] );
// Gezielt nach Gustav fragen statt die Tabelle zu zählen: Der „Alte Gast“
// aus der Bestandsübernahme steht zu Recht noch in vp_people.
Pruefung::ok( 'kein Eintrag mehr in vp_people', ( new PeopleModel() )->find_by_email( 'gustav@example.test' ) === null );

// =====================================================================
echo "\nAufbewahrungsfrist für offene Gast-Kontakte\n";

// Alter, inaktiver Kontakt: älter als die Frist, keine Zusage.
$wpdb->insert( 'wp_vp_people', [
    'display_name' => 'Alter Kontakt',
    'email'        => 'alt-kontakt@example.test',
    'source'       => 'link',
    'created_at'   => '2025-01-01 00:00:00',
] );

// Frischer Kontakt: liegt innerhalb der Frist.
$wpdb->insert( 'wp_vp_people', [
    'display_name' => 'Frischer Kontakt',
    'email'        => 'frisch@example.test',
    'source'       => 'link',
    'created_at'   => '2026-07-01 00:00:00',
] );

// Alter Eintrag, aber mit kürzlicher Zusage – zählt als aktiv.
$wpdb->insert( 'wp_vp_people', [
    'display_name' => 'Wiederkehrender Gast',
    'email'        => 'wiederkehrer@example.test',
    'source'       => 'link',
    'created_at'   => '2024-01-01 00:00:00',
] );
$wiederkehrer_id = (int) end( $wpdb->t['wp_vp_people'] )['id'];
$wpdb->insert( 'wp_vp_event_attendance', [
    'event_id'      => 20,
    'instance_date' => '2026-07-15',
    'person_id'     => Person::from_guest( $wiederkehrer_id ),
    'response'      => 'yes',
    'responded_at'  => '2026-07-15 10:00:00',
] );

$alte = ( new PeopleModel() )->stale( 180 );
Pruefung::gleich( 'genau ein Kontakt gilt als veraltet', count( $alte ), 1 );
Pruefung::gleich( 'es ist der alte, inaktive Kontakt', $alte[0]['email'] ?? '', 'alt-kontakt@example.test' );

$vorher = $personen();
( new PeopleCleanup() )->run();
Pruefung::gleich( 'genau ein Eintrag wurde entfernt', $personen(), $vorher - 1 );
Pruefung::ok( 'frischer Kontakt bleibt erhalten', (bool) ( new PeopleModel() )->find_by_email( 'frisch@example.test' ) );
Pruefung::ok( 'wiederkehrender Gast bleibt erhalten', (bool) ( new PeopleModel() )->find_by_email( 'wiederkehrer@example.test' ) );
Pruefung::ok( 'alter Kontakt ist weg', ! ( new PeopleModel() )->find_by_email( 'alt-kontakt@example.test' ) );

Pruefung::abschluss( 'Personen' );
