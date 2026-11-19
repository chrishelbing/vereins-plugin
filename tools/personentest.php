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
    'Members/SavedNames.php',
    'Members/MemberService.php',
    'Members/PeopleMigration.php',
    'Members/PeopleCleanup.php',
    'Admin/Settings.php',
    'Events/EventModel.php',
    'Events/CompanionModel.php',
    'Events/ItemModel.php',
    'Photos/PhotoModel.php',
    'Photos/PhotoService.php',
    'Members/PersonActivity.php',
    'Auth/OtpModel.php',
    'Privacy/PersonalData.php',
    'Members/PersonMerge.php',
] );

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Members\GuestService;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\PeopleCleanup;
use VereinsPlugin\Members\PeopleMigration;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\PeopleModel;
use VereinsPlugin\Members\PersonMerge;
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
// Der gemeldete Fehler: Nach dem Identifizieren erkannte das Cookie den Gast
// nicht wieder (Prüfung nur gegen WordPress-Konten) – Zusagen über den
// Terminlink scheiterten immer an „Bitte gib zuerst an, wer du bist“.
Pruefung::gleich( 'Cookie erkennt den Gast wieder', \VereinsPlugin\Support\PublicLink::current_guest(), $person_id );

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
unset( $_COOKIE['vp_guest'] );
$rita = $gast->identify( [ 'name' => 'Rita Roth', 'email' => 'rita@example.test' ] );

Pruefung::gleich( 'kein zweiter Eintrag für sie', $personen(), 0 );
Pruefung::gleich( 'kein zweites Konto', $nutzerzahl(), 2 );

// Die Lücke, um die es hier geht: Früher genügte die E-Mail-Adresse eines
// Kontos, um am Terminlink als dieses Konto erkannt zu werden – mit dessen
// Zusagen, Begleitpersonen und Rechten. Die Adresse allein darf nichts
// hinterlegen; erkannt wird ein Konto erst nach dem Zugangscode.
echo "\nE-Mail eines Kontos am Terminlink: erst der Zugangscode zählt\n";

Pruefung::gleich( 'Konto wird gemeldet, nicht übernommen', (int) ( $rita['member_id'] ?? 0 ), $redakteur );
Pruefung::gleich( 'keine Personen-ID vergeben', (int) $rita['person_id'], 0 );
Pruefung::ok( 'kein Cookie gesetzt', empty( $_COOKIE['vp_guest'] ) );
Pruefung::gleich( 'niemand erkannt', \VereinsPlugin\Support\PublicLink::current_guest(), 0 );

// Ein Cookie, das eine frühere Version noch auf ein Konto ausgestellt hat,
// ist echt signiert – und darf trotzdem nicht mehr gelten.
\VereinsPlugin\Support\PublicLink::remember_guest( $redakteur );
Pruefung::ok( 'altes Cookie auf ein Konto liegt vor', ! empty( $_COOKIE['vp_guest'] ) );
Pruefung::gleich( 'altes Cookie auf ein Konto gilt nicht mehr', \VereinsPlugin\Support\PublicLink::current_guest(), 0 );
unset( $_COOKIE['vp_guest'] );

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

// Übersicht je Person im Backend: Was hängt an diesem Konto? Anlass war ein
// Mitglied mit drei Konten – welches „das richtige“ ist, zeigt erst das.
echo "\nÜbersicht: Termine und Beiträge einer Person\n";
$wpdb->insert( 'wp_vp_event_items', [ 'event_id' => $event_id, 'instance_date' => '2026-10-10', 'kind' => 'help', 'label' => 'Aufbau', 'created_by' => $petra_id ] );
$hilfe_id = (int) end( $wpdb->t['wp_vp_event_items'] )['id'];
$wpdb->insert( 'wp_vp_event_item_claims', [ 'item_id' => $hilfe_id, 'person_id' => $petra_id, 'companion_id' => 0, 'quantity' => 2, 'note' => 'ab 16 Uhr' ] );
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => $event_id, 'instance_date' => '2026-11-05', 'person_id' => $petra_id, 'response' => 'no', 'responded_at' => '2026-11-01 10:00:00' ] );

$aktiv = ( new \VereinsPlugin\Members\PersonActivity() )->for_person( $petra_id );
Pruefung::gleich( 'zwei Termininstanzen', count( $aktiv ), 2 );
Pruefung::gleich( 'neueste zuerst', $aktiv[0]['date'] ?? '', '2026-11-05' );
Pruefung::gleich( 'Absage erkannt', $aktiv[0]['response'] ?? '', 'no' );
$okt = $aktiv[1] ?? [];
Pruefung::gleich( 'Zusage erkannt', $okt['response'] ?? '', 'yes' );
Pruefung::gleich( 'Mitgebrachtes', $okt['bring'] ?? [], [ 'Kuchen' ] );
Pruefung::gleich( 'Helferaufgabe mit Menge und Notiz', $okt['help'] ?? [], [ '2× Aufbau („ab 16 Uhr“)' ] );
Pruefung::gleich( 'Foto gezählt', $okt['photos'] ?? 0, 1 );

$kurz = ( new \VereinsPlugin\Members\PersonActivity() )->summaries( [ $petra_id, 99999 ] );
Pruefung::gleich( 'Kurzfassung: Zusagen', $kurz[ $petra_id ]['yes'] ?? -1, 1 );
Pruefung::gleich( 'Kurzfassung: Absagen', $kurz[ $petra_id ]['no'] ?? -1, 1 );
Pruefung::gleich( 'Kurzfassung: Posten', $kurz[ $petra_id ]['items'] ?? -1, 2 );
Pruefung::gleich( 'Kurzfassung: ohne Aktivität', $kurz[99999] ?? null, [ 'yes' => 0, 'no' => 0, 'items' => 0, 'last' => null ] );

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

// =====================================================================
echo "\nMitglied bearbeiten (Tippfehler korrigieren)\n";

$mitglieder = new VereinsPlugin\Members\MemberModel();
$admin_id = stub_user( 'Anna Admin', [ 'administrator' ], 'vorstand@example.test' );
$GLOBALS['stub_current'] = $admin_id;

$neu = $service->create_by_admin( [ 'display_name' => 'Hanz Müler', 'email' => 'hanz@exmaple.test', 'phone' => '' ] );
Pruefung::ok( 'Mitglied mit Tippfehlern angelegt', ! is_wp_error( $neu ) );
$mid = (int) $neu['member']['id'];
$uid = (int) $neu['member']['wp_user_id'];

$erg = $service->update_profile( $mid, [ 'display_name' => 'Hans Müller', 'email' => 'hans@example.test', 'phone' => '0171 123' ] );
Pruefung::ok( 'Speichern gelingt', ! is_wp_error( $erg ) );
Pruefung::gleich( 'Name im Mitgliedseintrag', $mitglieder->find( $mid )['display_name'] ?? '', 'Hans Müller' );
Pruefung::gleich( 'Name im WordPress-Profil', get_userdata( $uid )->display_name ?? '', 'Hans Müller' );
Pruefung::gleich( 'E-Mail geändert', get_userdata( $uid )->user_email ?? '', 'hans@example.test' );
Pruefung::gleich( 'Telefon gespeichert', $mitglieder->find( $mid )['phone'] ?? '', '0171 123' );
Pruefung::ok( 'Änderung der E-Mail wird gemeldet', ! is_wp_error( $erg ) && $erg['email_changed'] );

$leer = $service->update_profile( $mid, [ 'display_name' => '   ' ] );
Pruefung::gleich( 'leerer Name wird abgelehnt', is_wp_error( $leer ) ? $leer->get_error_code() : '', 'empty_name' );

$besetzt = $service->update_profile( $mid, [ 'display_name' => 'Hans Müller', 'email' => 'dora@example.test' ] );
Pruefung::gleich( 'fremde E-Mail wird abgelehnt', is_wp_error( $besetzt ) ? $besetzt->get_error_code() : '', 'email_exists' );
Pruefung::gleich( 'E-Mail bleibt nach Ablehnung', get_userdata( $uid )->user_email ?? '', 'hans@example.test' );

$ungueltig = $service->update_profile( $mid, [ 'display_name' => 'Hans Müller', 'email' => 'kein-at-zeichen' ] );
Pruefung::gleich( 'ungültige E-Mail wird abgelehnt', is_wp_error( $ungueltig ) ? $ungueltig->get_error_code() : '', 'invalid_email' );

// Das eigene Konto: Name ja, E-Mail nein – die gehört ins WordPress-Profil.
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $admin_id, 'display_name' => 'Anna Admin', 'status' => 'active' ] );
$admin_mid = (int) end( $wpdb->t['wp_vp_members'] )['id'];
$eigen = $service->update_profile( $admin_mid, [ 'display_name' => 'Anna Adminova', 'email' => 'anders@example.test' ] );
Pruefung::gleich( 'eigene E-Mail hier nicht änderbar', is_wp_error( $eigen ) ? $eigen->get_error_code() : '', 'protected' );
$eigen_name = $service->update_profile( $admin_mid, [ 'display_name' => 'Anna Adminova', 'email' => 'vorstand@example.test' ] );
Pruefung::ok( 'eigener Name schon', ! is_wp_error( $eigen_name ) && get_userdata( $admin_id )->display_name === 'Anna Adminova' );

// =====================================================================
echo "\nBegleitpersonen: mehrere Namen auf einmal\n";
// „Mia, Tom“ im Feld „Weitere Personen mitbringen“ sind zwei Personen,
// nicht eine namens „Mia, Tom“. Jede zählt einzeln.
Pruefung::gleich( 'Komma trennt',             CompanionModel::split_names( [ 'Mia, Tom' ] ), [ 'Mia', 'Tom' ] );
Pruefung::gleich( '„und“ trennt',             CompanionModel::split_names( [ 'Mia und Tom' ] ), [ 'Mia', 'Tom' ] );
Pruefung::gleich( '& ; + trennen',            CompanionModel::split_names( [ 'Mia & Tom; Lea + Ben' ] ), [ 'Mia', 'Tom', 'Lea', 'Ben' ] );
Pruefung::gleich( 'Bindestrich bleibt',       CompanionModel::split_names( [ 'Hans-Peter' ] ), [ 'Hans-Peter' ] );
Pruefung::gleich( '„und“ im Namen bleibt',    CompanionModel::split_names( [ 'Undine, Sigmund' ] ), [ 'Undine', 'Sigmund' ] );
Pruefung::gleich( 'Leeres und Doppeltes weg', CompanionModel::split_names( [ 'Mia, , mia', 'Tom' ] ), [ 'Mia', 'Tom' ] );

$begleit = new CompanionModel();
$familie = stub_user( 'Fam Becker', [ Activator::ROLE_MEMBER ], 'becker@example.test' );
$begleit->set_for_attendance( 9, '2026-12-01', $familie, [], [ 'Mia, Tom' ] );
$dabei = $begleit->for_attendance( 9, '2026-12-01', $familie );
Pruefung::gleich( 'zwei Begleitpersonen am Termin', count( $dabei ), 2 );
Pruefung::gleich( 'mit eigenen Namen',             array_column( $dabei, 'name' ), [ 'Mia', 'Tom' ] );

// =====================================================================
echo "\nAusrufezeichen „unbestätigt“ nur bei Gästen\n";
// Ein Mitglied, das ein Administrator angelegt hat, bekommt nie einen
// Bestätigungslink – es darf deshalb nicht als unbestätigt gelten.
$angelegt = $service->create_by_admin( [ 'display_name' => 'Chris Vorstand', 'email' => 'chris-v@example.test' ] );
$chris_id = (int) $angelegt['member']['wp_user_id'];
Pruefung::ok( 'angelegtes Mitglied ohne Ausrufezeichen', ! Person::unconfirmed_map( [ $chris_id ] ) );

$neugast = $gast->identify( [ 'name' => 'Nina Neu', 'email' => 'nina@example.test' ], 'https://verein.test/termine' );
$nina_id = (int) ( $neugast['person_id'] ?? 0 );
Pruefung::ok( 'Gast ohne Bestätigung mit Ausrufezeichen', $nina_id < 0 && ! empty( Person::unconfirmed_map( [ $nina_id ] )[ $nina_id ] ) );

// =====================================================================
echo "\nOhne E-Mail aufnehmen und Doppelte zusammenführen\n";
// Gäste ohne E-Mail werden über den Terminlink nicht wiedererkannt; wer sie
// aufnimmt, hat dieselbe Person bald zweimal. Zusammenführen muss alles
// mitnehmen, ohne doppelt zu zählen.
$wpdb->uniques['wp_vp_event_attendance']  = [ [ 'event_id', 'instance_date', 'person_id' ] ];
$wpdb->uniques['wp_vp_member_companions'] = [ [ 'person_id', 'name' ] ];

Pruefung::ok( 'Ersatzadresse erkannt', MemberService::is_placeholder_email( 'vp-abc' . MemberService::PLACEHOLDER_DOMAIN ) && ! MemberService::is_placeholder_email( 'a@example.test' ) );

$f1 = $gast->identify( [ 'name' => 'Franzi' ], 'https://verein.test/termine' );
$f1_id = (int) $f1['person_id'];
$a1 = $service->accept_person( $f1_id );
$franzi = (int) $a1['wp_user_id'];
Pruefung::ok( 'ohne E-Mail aufgenommen: Ersatzadresse', MemberService::is_placeholder_email( get_userdata( $franzi )->user_email ) );

$f2 = $gast->identify( [ 'name' => 'Franzi' ], 'https://verein.test/termine' );
$f2_id = (int) $f2['person_id'];
Pruefung::ok( 'beim nächsten Terminlink ein neuer Gast', $f2_id < 0 && $f2_id !== $f1_id );
$a2 = $service->accept_person( $f2_id, 'franzi@example.test' );
$franzi2 = (int) $a2['wp_user_id'];
Pruefung::gleich( 'beim Aufnehmen E-Mail mitgegeben', get_userdata( $franzi2 )->user_email, 'franzi@example.test' );
$f3 = $gast->identify( [ 'name' => 'Ohne Adresse' ], 'https://verein.test/termine' );
Pruefung::ok( 'falsche E-Mail beim Aufnehmen abgelehnt', is_wp_error( $service->accept_person( (int) $f3['person_id'], 'kein-at-zeichen' ) ) );

// Beide Franzis haben Spuren: gleicher Termin doppelt, je eigener Termin,
// gleiche und verschiedene Begleitpersonen, ein Mitbringposten.
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 40, 'instance_date' => '2026-12-12', 'person_id' => $franzi,  'response' => 'yes' ] );
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 40, 'instance_date' => '2026-12-12', 'person_id' => $franzi2, 'response' => 'no' ] );
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 41, 'instance_date' => '2026-12-19', 'person_id' => $franzi2, 'response' => 'yes' ] );
$wpdb->insert( 'wp_vp_member_companions', [ 'person_id' => $franzi,  'name' => 'Mia' ] );
$mia_alt = (int) $wpdb->insert_id;
$wpdb->insert( 'wp_vp_member_companions', [ 'person_id' => $franzi2, 'name' => 'mia' ] );
$mia_weg = (int) $wpdb->insert_id;
$wpdb->insert( 'wp_vp_member_companions', [ 'person_id' => $franzi2, 'name' => 'Tom' ] );
$wpdb->insert( 'wp_vp_event_companions', [ 'event_id' => 41, 'instance_date' => '2026-12-19', 'person_id' => $franzi2, 'companion_id' => $mia_weg ] );
$wpdb->insert( 'wp_vp_event_item_claims', [ 'item_id' => 333, 'person_id' => $franzi2, 'companion_id' => 0, 'quantity' => 1 ] );
$wpdb->update( 'wp_vp_members', [ 'phone' => '0171 123' ], [ 'wp_user_id' => $franzi2 ] );
get_userdata( $franzi2 )->add_role( Activator::ROLE_ORGA );

$merge = new PersonMerge( $service );
Pruefung::ok( 'nicht mit sich selbst', is_wp_error( $merge->merge( $franzi, $franzi ) ) );
Pruefung::ok( 'behalten wird nur ein Mitglied', is_wp_error( $merge->merge( (int) $f3['person_id'], $franzi ) ) );

$r = $merge->merge( $franzi, $franzi2 );
Pruefung::ok( 'zusammengeführt', ! is_wp_error( $r ) );
$zeilen = array_values( array_filter( $wpdb->t['wp_vp_event_attendance'], fn( $z ) => in_array( (int) $z['event_id'], [ 40, 41 ], true ) ) );
Pruefung::gleich( 'gleicher Termin nicht doppelt – behaltene Antwort gilt', array_map( fn( $z ) => $z['event_id'] . ':' . $z['response'] . ':' . $z['person_id'], $zeilen ), [ "40:yes:$franzi", "41:yes:$franzi" ] );
Pruefung::gleich( 'Begleitpersonen: gleicher Name einmal, neuer dazu', array_column( array_filter( $wpdb->t['wp_vp_member_companions'], fn( $z ) => (int) $z['person_id'] === $franzi ), 'name' ), [ 'Mia', 'Tom' ] );
$am_termin = array_values( array_filter( $wpdb->t['wp_vp_event_companions'], fn( $z ) => (int) $z['event_id'] === 41 ) );
Pruefung::gleich( 'Begleitung am Termin zeigt auf die behaltene „Mia“', [ (int) $am_termin[0]['person_id'], (int) $am_termin[0]['companion_id'] ], [ $franzi, $mia_alt ] );
Pruefung::gleich( 'Mitbringposten umgezogen', (int) array_values( array_filter( $wpdb->t['wp_vp_event_item_claims'], fn( $z ) => (int) $z['item_id'] === 333 ) )[0]['person_id'], $franzi );
Pruefung::gleich( 'echte E-Mail übernommen', get_userdata( $franzi )->user_email, 'franzi@example.test' );
Pruefung::gleich( 'Telefon ergänzt', (string) ( new \VereinsPlugin\Members\MemberModel() )->find_by_user_id( $franzi )['phone'], '0171 123' );
Pruefung::ok( 'Orgateam übernommen', in_array( Activator::ROLE_ORGA, get_userdata( $franzi )->roles, true ) && $r['orga'] );
Pruefung::ok( 'anderes Konto weg', ! get_userdata( $franzi2 ) && ! ( new \VereinsPlugin\Members\MemberModel() )->find_by_user_id( $franzi2 ) );

// Offene Person in ein Mitglied übernehmen
$g = $gast->identify( [ 'name' => 'Franzi B.', 'phone' => '0300' ], 'https://verein.test/termine' );
$gid = (int) $g['person_id'];
$wpdb->insert( 'wp_vp_event_attendance', [ 'event_id' => 42, 'instance_date' => '2027-01-09', 'person_id' => $gid, 'response' => 'yes' ] );
$r2 = $merge->merge( $franzi, $gid );
Pruefung::ok( 'offene Person übernommen', ! is_wp_error( $r2 ) && ! Person::find( $gid ) );
Pruefung::gleich( 'ihre Zusage hängt am Mitglied', (int) array_values( array_filter( $wpdb->t['wp_vp_event_attendance'], fn( $z ) => (int) $z['event_id'] === 42 ) )[0]['person_id'], $franzi );
Pruefung::ok( 'Administrator kann nicht aufgehen', is_wp_error( $merge->merge( $franzi, $admin_id ) ) );
unset( $wpdb->uniques['wp_vp_event_attendance'], $wpdb->uniques['wp_vp_member_companions'] );

// =====================================================================
echo "\nEigener Name ist keine Begleitperson\n";
// Wer sich bei einem Wettkampf selbst einträgt, stand danach bei Terminen
// als seine eigene Begleitung da. Der eigene Name kommt nie in die Liste.
$selbst = stub_user( 'Chris Helbing', [ Activator::ROLE_MEMBER ], 'chris-h@example.test' );
$liste  = new CompanionModel();
Pruefung::gleich( 'Termine: eigener Name wird nicht gemerkt', $liste->remember( $selbst, ' chris  HELBING ' ), 0 );
\VereinsPlugin\Members\SavedNames::remember( $selbst, 'Chris Helbing' );
\VereinsPlugin\Members\SavedNames::remember( $selbst, 'Mia Helbing' );
Pruefung::gleich( 'Meldung: nur das Kind gemerkt', \VereinsPlugin\Members\SavedNames::for_person( $selbst ), [ 'Mia Helbing' ] );
$dabei = $liste->set_for_attendance( 50, '2027-01-01', $selbst, [], [ 'Chris Helbing, Mia Helbing' ] );
Pruefung::gleich( 'am Termin nur die echte Begleitung', array_column( $dabei, 'name' ), [ 'Mia Helbing' ] );

// Altbestand: eigener Name steht schon in der Liste und hängt an Terminen.
$wpdb->insert( 'wp_vp_member_companions', [ 'person_id' => $selbst, 'name' => 'Chris Helbing' ] );
$alt_id = (int) $wpdb->insert_id;
$wpdb->insert( 'wp_vp_event_companions', [ 'event_id' => 51, 'instance_date' => '2027-02-01', 'person_id' => $selbst, 'companion_id' => $alt_id, 'name' => 'Chris Helbing' ] );
$wpdb->insert( 'wp_vp_event_item_claims', [ 'item_id' => 444, 'person_id' => $selbst, 'companion_id' => $alt_id, 'quantity' => 1 ] );
Pruefung::gleich( 'Aufräumen beim Update: ein Name entfernt', \VereinsPlugin\Members\SavedNames::remove_own_names(), 1 );
Pruefung::ok( 'als Begleitung am Termin weg', ! array_filter( $wpdb->t['wp_vp_event_companions'], fn( $z ) => (int) $z['companion_id'] === $alt_id ) );
Pruefung::gleich( 'Mitbringposten zählt als eigener', (int) array_values( array_filter( $wpdb->t['wp_vp_event_item_claims'], fn( $z ) => (int) $z['item_id'] === 444 ) )[0]['companion_id'], 0 );
Pruefung::gleich( 'Kind bleibt in der Liste', \VereinsPlugin\Members\SavedNames::for_person( $selbst ), [ 'Mia Helbing' ] );

Pruefung::abschluss( 'Personen' );
