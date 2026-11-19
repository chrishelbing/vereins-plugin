<?php
/**
 * Modul Umfragen: Terminumfrage und Abstimmung.
 *
 * Aufruf im Plugin-Ordner:   php tools/umfragetest.php
 *
 * Geprüft wird, was auf der Website nicht schiefgehen darf: gültige und
 * ungültige Stimmen, beendete Umfragen, „nur Mitglieder“, wer das Ergebnis
 * sieht, geheime Umfragen ohne Namen – und dass beim Bearbeiten der
 * Antwortmöglichkeiten die Stimmen erhalten bleiben.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

if ( ! function_exists( 'wp_unslash' ) )              { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $v ) { return trim( (string) $v ); } }
if ( ! function_exists( 'wp_json_encode' ) )          { function wp_json_encode( $v ) { return json_encode( $v ); } }
if ( ! function_exists( 'wp_tempnam' ) )             { function wp_tempnam( $n = '' ) { return tempnam( sys_get_temp_dir(), 'vp' ); } }

stub_load( [
    'Core/Activator.php',
    'Support/Capabilities.php',
    'Members/MemberModel.php',
    'Members/PeopleModel.php',
    'Members/Person.php',
    'Members/MemberService.php',
    'Admin/Settings.php',
    'Polls/PollModel.php',
    'Polls/PollService.php',
    'Polls/PollsAdmin.php',
    'Members/SavedNames.php',
    'Polls/ClassTemplates.php',
    'Polls/ClassesAdmin.php',
    'Polls/EntryService.php',
    'Polls/EntryCleanup.php',
    'Polls/BjvExport.php',
    'Polls/EntryMail.php',
] );

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Polls\PollModel;
use VereinsPlugin\Polls\PollService;
use VereinsPlugin\Polls\PollsAdmin;
use VereinsPlugin\Polls\ClassTemplates;
use VereinsPlugin\Polls\ClassesAdmin;
use VereinsPlugin\Polls\EntryService;
use VereinsPlugin\Polls\EntryCleanup;
use VereinsPlugin\Polls\BjvExport;
use VereinsPlugin\Polls\EntryMail;
use VereinsPlugin\Members\SavedNames;

stub_default_roles();
Activator::register_roles();

global $wpdb;
$model   = new PollModel();
$service = new PollService( $model );

$anna  = stub_user( 'Anna Aktiv', [ Activator::ROLE_MEMBER ], 'anna@example.test' );
$orga  = stub_user( 'Olaf Orga', [ Activator::ROLE_ORGA ], 'olaf@example.test' );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $anna, 'display_name' => 'Anna Aktiv', 'status' => 'active' ] );
$wpdb->insert( 'wp_vp_members', [ 'wp_user_id' => $orga, 'display_name' => 'Olaf Orga', 'status' => 'active' ] );
$wpdb->insert( 'wp_vp_people', [ 'display_name' => 'Gabi Gast', 'source' => 'link' ] );
$gabi = -(int) end( $wpdb->t['wp_vp_people'] )['id'];

// =====================================================================
echo "Terminumfrage\n";

$tid = $model->save( [ 'kind' => 'date', 'title' => 'Weihnachtsfeier', 'status' => 'open', 'allow_maybe' => 1, 'results' => 'always' ] );
$model->save_options( $tid, PollsAdmin::parse_options( [
    [ 'starts_at' => '2026-12-19T15:00', 'ends_at' => '18:00', 'label' => '' ],
    [ 'starts_at' => '', 'label' => 'leere Zeile fällt weg' ],
    [ 'starts_at' => '2026-12-12T15:00', 'ends_at' => '', 'label' => 'mit Grillen' ],
], 'date' ) );
$opt = $model->options( $tid );
Pruefung::gleich( 'zwei Vorschläge (leere Zeile ignoriert)', count( $opt ), 2 );
Pruefung::gleich( 'nach Datum sortiert', $opt[0]['starts_at'] ?? '', '2026-12-12 15:00:00' );
Pruefung::gleich( 'Ende aus Uhrzeit gebildet', $opt[1]['ends_at'] ?? '', '2026-12-19 18:00:00' );
[ $o1, $o2 ] = [ (int) $opt[0]['id'], (int) $opt[1]['id'] ];

$poll = $model->find( $tid );
Pruefung::ok( 'Gast stimmt ab', $service->vote( $poll, $gabi, [ 'answers' => [ $o1 => 'yes', $o2 => 'maybe' ] ] ) === true );
Pruefung::ok( 'Mitglied stimmt ab, nur ein Vorschlag genannt', $service->vote( $poll, $anna, [ 'answers' => [ $o2 => 'yes' ] ] ) === true );
$erg = $service->results( $poll );
Pruefung::gleich( 'Vorschlag 1: eine Zusage', $erg[ $o1 ]['yes'], 1 );
Pruefung::gleich( 'nicht genannt zählt als „passt nicht“', $erg[ $o1 ]['no'], 1 );
Pruefung::gleich( 'Vorschlag 2: eine Zusage, ein Vielleicht', [ $erg[ $o2 ]['yes'], $erg[ $o2 ]['maybe'] ], [ 1, 1 ] );
Pruefung::gleich( 'Namen sichtbar', $erg[ $o2 ]['names']['maybe'], [ 'Gabi Gast' ] );
Pruefung::ok( 'erneut abstimmen ersetzt', $service->vote( $poll, $gabi, [ 'answers' => [ $o1 => 'no', $o2 => 'yes' ] ] ) === true );
Pruefung::gleich( 'keine doppelten Stimmen', $model->voter_count( $tid ), 2 );
Pruefung::gleich( 'Vorschlag 2 jetzt zwei Zusagen', $service->results( $poll )[ $o2 ]['yes'], 2 );

$ohne = $model->find( $model->save( [ 'kind' => 'date', 'title' => 'ohne vielleicht', 'status' => 'open', 'allow_maybe' => 0 ] ) );
$model->save_options( (int) $ohne['id'], [ [ 'label' => '', 'starts_at' => '2026-12-01 10:00:00' ] ] );
$oo = (int) $model->options( (int) $ohne['id'] )[0]['id'];
Pruefung::ok( '„vielleicht“ abgelehnt, wenn nicht erlaubt', is_wp_error( $service->vote( $ohne, $anna, [ 'answers' => [ $oo => 'maybe' ] ] ) ) );
Pruefung::ok( 'fremde Antwortmöglichkeit abgelehnt', is_wp_error( $service->vote( $ohne, $anna, [ 'answers' => [ $o1 => 'yes' ] ] ) ) );

// =====================================================================
echo "\nAbstimmung und Nominierung\n";

$nid = $model->save( [ 'kind' => 'choice', 'title' => 'Nachwuchspreis', 'status' => 'open', 'login_required' => 1, 'results' => 'nobody' ] );
$model->save_options( $nid, PollsAdmin::parse_options( [ [ 'label' => 'Mia' ], [ 'label' => 'Tom' ], [ 'label' => '' ] ], 'choice' ) );
$n = $model->find( $nid );
[ $mia, $tom ] = array_map( 'intval', array_column( $model->options( $nid ), 'id' ) );

Pruefung::ok( 'nur Mitglieder: Gast abgelehnt', is_wp_error( $service->vote( $n, $gabi, [ 'choices' => [ $mia ] ] ) ) );
Pruefung::ok( 'ohne Kennung abgelehnt', is_wp_error( $service->vote( $n, 0, [ 'choices' => [ $mia ] ] ) ) );
Pruefung::ok( 'leere Auswahl abgelehnt', is_wp_error( $service->vote( $n, $anna, [ 'choices' => [] ] ) ) );
Pruefung::ok( 'zwei Antworten bei Einfachauswahl abgelehnt', is_wp_error( $service->vote( $n, $anna, [ 'choices' => [ $mia, $tom ] ] ) ) );
Pruefung::ok( 'Mitglied stimmt ab', $service->vote( $n, $anna, [ 'choices' => [ $mia ] ] ) === true );

$e = $service->results( $n );
Pruefung::gleich( 'geheim: Zahl stimmt', $e[ $mia ]['yes'], 1 );
Pruefung::gleich( 'geheim: keine Namen, auch nicht für die Auswertung', $e[ $mia ]['names']['yes'], [] );

Pruefung::ok( '„niemand“ setzt geheim', (int) $n['anonymous'] === 1 );
Pruefung::ok( '„niemand“: Mitglied sieht nichts', ! $service->may_see_results( $n, $anna, true, false ) );
Pruefung::ok( 'Orgateam sieht die Zahlen', $service->may_see_results( $n, $orga, false, true ) );

$model->set_status( $nid, 'closed' );
$n = $model->find( $nid );
Pruefung::ok( 'beendete Umfrage nimmt nichts mehr an', is_wp_error( $service->vote( $n, $anna, [ 'choices' => [ $tom ] ] ) ) );
Pruefung::ok( '„niemand“: auch nach dem Ende nicht', ! $service->may_see_results( $n, $anna, true, false ) );

$spaeter = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'nach Abschluss', 'status' => 'open', 'results' => 'after_close' ] ) );
Pruefung::ok( 'nach Abschluss: vorher nicht', ! $service->may_see_results( $spaeter, $anna, true, false ) && (int) $spaeter['anonymous'] === 0 );
$model->set_status( (int) $spaeter['id'], 'closed' );
Pruefung::ok( 'nach Abschluss: danach für alle', $service->may_see_results( $model->find( (int) $spaeter['id'] ), $gabi, false, false ) );
$nurorga = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'nur Orga', 'status' => 'closed', 'results' => 'orga' ] ) );
Pruefung::ok( 'nur Orgateam: Mitglied nie', ! $service->may_see_results( $nurorga, $anna, true, false ) && $service->may_see_results( $nurorga, $orga, false, true ) );
$alt = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'Altwert', 'status' => 'open', 'results' => 'after_vote' ] ) );
Pruefung::gleich( 'Altwert „nach eigener Abstimmung“ wird „sofort“', $alt['results'], 'always' );
$altgeheim = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'Alt geheim', 'status' => 'open', 'anonymous' => 1 ] ) );
Pruefung::gleich( 'altes „geheim“ wird „niemand“', $altgeheim['results'], 'nobody' );

$frist = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'abgelaufen', 'status' => 'open', 'closes_at' => '2026-01-01 00:00:00' ] ) );
Pruefung::ok( 'Ende überschritten gilt als beendet', ! $service->is_open( $frist ) && $service->is_closed( $frist ) );

$mehr = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'Mehrfach', 'status' => 'open', 'multiple' => 1, 'results' => 'always' ] ) );
$model->save_options( (int) $mehr['id'], [ [ 'label' => 'A' ], [ 'label' => 'B' ] ] );
$ab = array_map( 'intval', array_column( $model->options( (int) $mehr['id'] ), 'id' ) );
Pruefung::ok( 'Mehrfachauswahl nimmt zwei an', $service->vote( $mehr, $gabi, [ 'choices' => $ab ] ) === true );
Pruefung::ok( 'sofort: für alle sichtbar, auch ohne eigene Stimme', $service->may_see_results( $mehr, $anna, false, false ) );

// =====================================================================
echo "\nBearbeiten behält Stimmen\n";

$opts = $model->options( $tid );
$opts[0]['label'] = 'mit Grillen und Glühwein';           // nur Text geändert
$model->save_options( $tid, [ $opts[0] ] );               // zweiter Vorschlag entfernt
$nach = $service->results( $model->find( $tid ) );
Pruefung::gleich( 'ein Vorschlag übrig', count( $model->options( $tid ) ), 1 );
Pruefung::ok( 'Stimmen am geänderten Vorschlag bleiben', isset( $nach[ $o1 ] ) && $nach[ $o1 ]['no'] === 2 );
Pruefung::gleich( 'Stimmen am entfernten Vorschlag sind weg', count( array_filter( $model->votes( $tid ), fn( $v ) => (int) $v['option_id'] === $o2 ) ), 0 );

$model->delete( $tid );
Pruefung::gleich( 'Löschen räumt Stimmen mit ab', count( $model->votes( $tid ) ), 0 );

// =====================================================================
echo "\nKlassen-Vorlagen\n";

Pruefung::gleich( 'Gewichte aus Text', ClassTemplates::parse_weights( '28, 31 -34 +55 Ü60 28' ), [ '-28', '-31', '-34', '+55', '+60' ] );
Pruefung::gleich( 'zwei Vorlagen ausgeliefert', array_keys( ClassTemplates::all() ), [ 'bjv', 'djb' ] );
$bjv = ClassTemplates::find( 'bjv' );
$u13 = array_values( array_filter( $bjv['classes'], fn( $c ) => $c['key'] === 'U13' ) )[0];
Pruefung::gleich( 'BJV U13 männlich: -28 bis +55', [ $u13['m'][0], end( $u13['m'] ) ], [ '-28', '+55' ] );
Pruefung::gleich( 'Jahrgänge U13 im Jahr 2027', ClassTemplates::years_label( $u13, 2027 ), '2015–2016' );
Pruefung::gleich( 'Erwachsene: „und älter“', ClassTemplates::years_label( [ 'from' => 17, 'to' => 99 ], 2027 ), '2010 und älter' );

$geaendert = $bjv;
$geaendert['classes'][0]['key'] = 'U9 Turnier';
ClassTemplates::save( 'bjv', $geaendert );
Pruefung::ok( 'Anpassung gespeichert und als angepasst erkannt', ClassTemplates::find( 'bjv' )['classes'][0]['key'] === 'U9 Turnier' && ClassTemplates::is_changed( 'bjv' ) );
ClassTemplates::reset( 'bjv' );
Pruefung::gleich( 'Zurücksetzen holt die Auslieferung', ClassTemplates::find( 'bjv' )['classes'][0]['key'], 'U9' );
ClassTemplates::remove( 'djb' );
Pruefung::ok( 'ausgelieferte Vorlage entfernbar …', ClassTemplates::find( 'djb' ) === null && ClassTemplates::removed() === [ 'djb' ] );
ClassTemplates::reset( 'djb' );
Pruefung::ok( '… und zurückholbar', ClassTemplates::find( 'djb' ) !== null );

$zeilen = ClassesAdmin::parse_rows( [
    [ 'on' => '1', 'key' => 'U11', 'from' => '2017', 'to' => '2016', 'sex' => 'mw', 'm' => '23 25 +46', 'w' => '24 +48' ],
    [ 'key' => 'U13', 'from' => '2015', 'to' => '2014', 'sex' => 'mw', 'm' => '28', 'w' => '27' ],
    [ 'on' => '1', 'key' => 'Erw', 'from' => '', 'to' => '2010', 'sex' => 'w', 'm' => '', 'w' => '48 +78' ],
    [ 'on' => '1', 'key' => 'U9', 'from' => '2019', 'to' => '2020', 'sex' => 'mw', 'near' => '1' ],
], 'year', 2027 );
Pruefung::gleich( 'Jahrgänge zurück in Alter (vertauscht eingegeben)', [ $zeilen[0]['from'], $zeilen[0]['to'] ], [ 10, 11 ] );
Pruefung::gleich( 'nicht angehakte Klasse fällt weg', array_column( $zeilen, 'key' ), [ 'U11', 'Erw', 'U9' ] );
Pruefung::gleich( 'leerer ältester Jahrgang = und älter', $zeilen[1]['to'], 99 );
Pruefung::ok( 'ohne Gewichte wird gewichtsnah', $zeilen[2]['near'] === true );

// =====================================================================
echo "\nWettkampfmeldung\n";

$wpdb->insert( 'wp_vp_people', [ 'display_name' => 'Gerd Gast', 'email' => 'gerd@example.test', 'source' => 'link' ] );
$gerd = -(int) end( $wpdb->t['wp_vp_people'] )['id'];

$wid = $model->save( [
    'kind' => 'entry', 'title' => 'Bezirkseinzel', 'status' => 'open', 'event_date' => '2027-02-06',
    'classes' => array_values( array_filter( $bjv['classes'], fn( $c ) => in_array( $c['key'], [ 'U9', 'U11', 'U13', 'U15' ], true ) ) ),
    'purge_days' => 30,
] );
$w     = $model->find( $wid );
$entry = new EntryService( $model );

Pruefung::gleich( 'Wettkampfjahr aus dem Datum', EntryService::year( $w ), 2027 );
Pruefung::gleich( 'Jahrgang 2016 (11 Jahre): nur U13', array_column( EntryService::eligible( $w, 2016, 'm' ), 'key' ), [ 'U13' ] );
Pruefung::gleich( 'Jahrgang 2015 (12 Jahre): U13 und U15 zur Wahl', array_column( EntryService::eligible( $w, 2015, 'w' ), 'key' ), [ 'U13', 'U15' ] );
Pruefung::gleich( 'Jahrgang 2014 (13 Jahre): nur U15', array_column( EntryService::eligible( $w, 2014, 'w' ), 'key' ), [ 'U15' ] );

$mia = [ 'first_name' => 'Mia', 'last_name' => 'Muster', 'birth_year' => 2017, 'sex' => 'w', 'class_key' => '', 'weight' => '-28' ];
$tom = [ 'first_name' => 'Tom', 'last_name' => 'Muster', 'birth_year' => 2019, 'sex' => 'm', 'class_key' => '', 'weight' => '-30' ];

Pruefung::ok( 'Gast ohne Kontakt: abgelehnt', ( $r = $entry->submit( $w, $gabi, [ $mia ] ) ) instanceof WP_Error && $r->get_error_code() === 'contact_required' );
Pruefung::ok( 'ohne Kennung abgelehnt', is_wp_error( $entry->submit( $w, 0, [ $mia ] ) ) );
Pruefung::ok( 'Gast mit E-Mail meldet Geschwister in zwei Klassen', $entry->submit( $w, $gerd, [ $mia, $tom ] ) === true );
$g = $model->entries( $wid, $gerd );
Pruefung::gleich( 'Klasse aus dem Jahrgang ergänzt', array_column( $g, 'class_key' ), [ 'U11', 'U9' ] );
Pruefung::gleich( 'gewichtsnah: Gewicht verworfen', $g[1]['weight'], '' );

Pruefung::ok( 'falsche Gewichtsklasse abgelehnt', is_wp_error( $entry->submit( $w, $gerd, [ array_merge( $mia, [ 'weight' => '-29' ] ) ] ) ) );
Pruefung::ok( 'Gewicht des anderen Geschlechts abgelehnt', is_wp_error( $entry->submit( $w, $gerd, [ array_merge( $mia, [ 'weight' => '-23' ] ) ] ) ) );
Pruefung::ok( 'Jahrgang ohne Klasse abgelehnt', is_wp_error( $entry->submit( $w, $gerd, [ array_merge( $mia, [ 'birth_year' => 2005 ] ) ] ) ) );
Pruefung::ok( 'zwei passende Klassen: Wahl verlangt', is_wp_error( $entry->submit( $w, $gerd, [ array_merge( $mia, [ 'birth_year' => 2015, 'weight' => '-27' ] ) ] ) ) );
Pruefung::ok( 'fremde Klasse abgelehnt', is_wp_error( $entry->submit( $w, $gerd, [ array_merge( $mia, [ 'class_key' => 'U15' ] ) ] ) ) );
Pruefung::ok( 'derselbe Starter zweimal in derselben Klasse abgelehnt', is_wp_error( $entry->submit( $w, $gerd, [ $mia, $mia ] ) ) );
$lea13 = [ 'first_name' => 'Lea', 'last_name' => 'Doppel', 'birth_year' => 2015, 'sex' => 'w', 'class_key' => 'U13', 'weight' => '-27' ];
$lea15 = [ 'first_name' => 'Lea', 'last_name' => 'Doppel', 'birth_year' => 2015, 'sex' => 'w', 'class_key' => 'U15', 'weight' => '-33' ];
Pruefung::ok( 'derselbe Starter in zwei Klassen erlaubt (U13 und U15)', $entry->submit( $w, $gerd, [ $mia, $tom, $lea13, $lea15 ] ) === true );
Pruefung::gleich( 'beide Klassen gespeichert', array_column( array_filter( $model->entries( $wid, $gerd ), fn( $e ) => $e['first_name'] === 'Lea' ), 'class_key' ), [ 'U13', 'U15' ] );
Pruefung::ok( 'Groß/klein und Leerzeichen zählen nicht – trotzdem doppelt', is_wp_error( $entry->submit( $w, $gerd, [ $lea13, array_merge( $lea13, [ 'first_name' => ' lea', 'last_name' => 'DOPPEL' ] ) ] ) ) );
$wpdb->insert( 'wp_vp_people', [ 'display_name' => 'Zweiter Elternteil', 'email' => 'zweit@example.test', 'source' => 'link' ] );
$zweit = -(int) end( $wpdb->t['wp_vp_people'] )['id'];
Pruefung::ok( 'anderer Melder: dieselbe Klasse abgelehnt', ( $r = $entry->submit( $w, $zweit, [ $lea13 ] ) ) instanceof WP_Error && str_contains( $r->get_error_message(), 'schon von jemand anderem' ) );
Pruefung::ok( 'eigene Meldung ändern: eigene Einträge zählen nicht als fremd', $entry->submit( $w, $gerd, [ $mia, $tom, $lea13, $lea15 ] ) === true );
Pruefung::ok( 'Ausgangslage für die folgenden Fälle', $entry->submit( $w, $gerd, [ $mia, $tom ] ) === true );
Pruefung::ok( 'Fehler nennt den Starter', ( $r = $entry->submit( $w, $gerd, [ $mia, array_merge( $tom, [ 'last_name' => '' ] ) ] ) ) instanceof WP_Error && str_starts_with( $r->get_error_message(), 'Starter 2' ) );
Pruefung::gleich( 'abgelehnte Meldung lässt die alte stehen', count( $model->entries( $wid, $gerd ) ), 2 );

$ben = [ 'first_name' => 'Ben', 'last_name' => 'Aktiv', 'birth_year' => 2014, 'sex' => 'm', 'class_key' => 'U15', 'weight' => '+66', 'note' => 'startet auch Mannschaft' ];
Pruefung::ok( 'Mitglied meldet', $entry->submit( $w, $anna, [ $ben ] ) === true );
Pruefung::gleich( 'Mitglied: Name gemerkt – nur der Name', SavedNames::for_person( $anna ), [ 'Ben Aktiv' ] );
Pruefung::gleich( 'Gast: nichts gemerkt', SavedNames::for_person( $gerd ), [] );
SavedNames::remember( $anna, 'Lea Aktiv' );
SavedNames::forget( $anna, 'Ben Aktiv' );
Pruefung::gleich( 'gemerkten Namen entfernen', SavedNames::for_person( $anna ), [ 'Lea Aktiv' ] );
Pruefung::gleich( 'Meldung bleibt beim Entfernen des Namens', count( $model->entries( $wid, $anna ) ), 1 );
Pruefung::gleich( 'zwei Melder', $model->voter_count( $wid ), 2 );

$liste = $entry->list_for_orga( $w );
Pruefung::gleich( 'Orga-Liste nach Klassen (Reihenfolge der Ausschreibung)', array_column( $liste, 'class_key' ), [ 'U9', 'U11', 'U15' ] );
Pruefung::ok( 'Kind ist nicht „selbst“ gemeldet', $liste[0]['is_self'] === false );
$selbst_id = $model->save( [ 'kind' => 'entry', 'title' => 'Ü30', 'status' => 'open', 'event_date' => '2027-03-01', 'classes' => [ [ 'key' => 'Erw', 'from' => 17, 'to' => 99, 'sex' => 'mw', 'near' => true, 'm' => [], 'w' => [] ] ] ] );
$selbst_poll = $model->find( $selbst_id );
$entry->submit( $selbst_poll, $gerd, [ [ 'first_name' => 'Gerd', 'last_name' => 'Gast', 'birth_year' => 1980, 'sex' => 'm', 'class_key' => 'Erw', 'weight' => '' ] ] );
Pruefung::ok( 'Selbstmeldung als „selbst“ erkannt', $entry->list_for_orga( $selbst_poll )[0]['is_self'] === true );
Pruefung::ok( 'CSV: Melder „selbst“', str_contains( $entry->csv( $selbst_poll, 'JVL' ), '"Gast";"Gerd";"1980";"m";"Erw";"gewichtsnah";"JVL";"selbst"' ) );
Pruefung::gleich( 'Website: eigener Name für „Mich selbst melden“', $service->payload( $selbst_poll, $gerd, false )['self_name'], 'Gerd Gast' );
$model->delete( $selbst_id );
Pruefung::gleich( 'Melder mit Kontakt an jedem Starter', [ $liste[0]['reporter'], $liste[0]['contact'] ], [ 'Gerd Gast', 'gerd@example.test' ] );
$csv = $entry->csv( $w, 'JVL' );
Pruefung::ok( 'CSV mit BOM, Semikolon und Kopfzeile', str_starts_with( $csv, "\xEF\xBB\xBF\"Nachname\";\"Vorname\";\"Jahrgang\"" ) );
Pruefung::ok( 'CSV: gewichtsnah ausgeschrieben, Verein gesetzt', str_contains( $csv, '"Muster";"Tom";"2019";"m";"U9";"gewichtsnah";"JVL"' ) );

$pay = $service->payload( $w, $gerd, false );
Pruefung::gleich( 'Website: nur die eigenen Starter', array_column( $pay['entries'], 'first_name' ), [ 'Mia', 'Tom' ] );
Pruefung::gleich( 'Website: Jahrgänge je Klasse', $pay['classes'][2]['years'], '2015–2016' );
Pruefung::ok( 'Website: keine fremden Ergebnisse', $pay['results'] === null );
Pruefung::ok( 'Stimmen an einer Meldung abgelehnt', is_wp_error( $service->vote( $w, $anna, [ 'choices' => [ 1 ] ] ) ) );

// --- Meldung beim Veranstalter: Abhakliste, abgeschickt, bestätigt ------
echo "\nMeldung beim Veranstalter\n";
$ids = array_map( fn( $e ) => (int) $e['id'], $model->entries( $wid ) );
$neu = $model->mark_entries_submitted( $wid, $ids );
sort( $neu );
$erw = [ $gerd, $anna ];
sort( $erw );
Pruefung::gleich( 'Abhaken liefert die betroffenen Melder', $neu, $erw );
Pruefung::gleich( 'zweites Abhaken: niemand neu', $model->mark_entries_submitted( $wid, $ids ), [] );
$model->mark_poll( $wid, 'submitted_at' );
$model->mark_poll( $wid, 'kaputt' );
Pruefung::ok( 'Umfrage als abgeschickt vermerkt', ! empty( $model->find( $wid )['submitted_at'] ) && empty( $model->find( $wid )['confirmed_at'] ) );
$pay = $service->payload( $model->find( $wid ), $gerd, false );
Pruefung::ok( 'Website: Starter als gemeldet markiert', $pay['entries'][0]['submitted'] === true && ! empty( $pay['submitted_at'] ) );

Pruefung::ok( 'Meldung mit neuem Starter erneut gespeichert', $entry->submit( $model->find( $wid ), $gerd, [ $mia, $tom, $lea13 ] ) === true );
$g = array_column( $model->entries( $wid, $gerd ), null, 'first_name' );
Pruefung::ok( 'unverändert: bleibt gemeldet', ! empty( $g['Mia']['submitted_at'] ) && ! empty( $g['Tom']['submitted_at'] ) );
Pruefung::ok( 'neuer Starter: Nachmeldung', empty( $g['Lea']['submitted_at'] ) );
Pruefung::gleich( 'nur die Nachmeldung meldet den Melder neu', $model->mark_entries_submitted( $wid, [ (int) $g['Lea']['id'] ] ), [ $gerd ] );
$g = array_values( $g );
$model->mark_notified( $wid, [ $anna ] );
Pruefung::gleich( 'Mail fällig nur für Melder mit gemeldeten, noch nicht informierten Startern', $model->melder_to_notify( $wid ), [ $gerd ] );
$model->mark_notified( $wid, [ $gerd ] );
Pruefung::gleich( 'nach der Mail: niemand mehr fällig', $model->melder_to_notify( $wid ), [] );
$entry->submit( $model->find( $wid ), $gerd, [ $mia, $tom, $lea13 ] );
Pruefung::gleich( 'unverändert neu gespeichert: bleibt informiert', $model->melder_to_notify( $wid ), [] );
Pruefung::gleich( 'Mail-Vorschau: Empfänger mit Adresse und Text', array_map( fn( $m ) => $m['email'], EntryMail::plan( $model->find( $wid ), 'sent', [ $gerd ] ) ), [ $gerd => 'gerd@example.test' ] );
$g = $model->entries( $wid, $gerd );
$model->mark_entries_submitted( $wid, [ (int) $g[0]['id'] ], false );
Pruefung::ok( 'Haken wieder lösen', empty( $model->entries( $wid, $gerd )[0]['submitted_at'] ) );
Pruefung::ok( 'CSV: Spalte „Beim Veranstalter gemeldet“', str_contains( $entry->csv( $model->find( $wid ), 'JVL' ), 'Beim Veranstalter gemeldet' ) );

// DokuMe: Judopass je Starter
$dok = $model->find( $model->save( [ 'kind' => 'entry', 'title' => 'DokuMe-Turnier', 'status' => 'open', 'event_date' => '2027-02-06', 'classes' => PollModel::classes( $w ), 'dokume' => true ] ) );
Pruefung::ok( 'DokuMe-Häkchen gespeichert', (int) $dok['dokume'] === 1 );
Pruefung::ok( 'DokuMe: ohne Judopass-Angabe abgelehnt', ( $r = $entry->submit( $dok, $gerd, [ $mia ] ) ) instanceof WP_Error && str_contains( $r->get_error_message(), 'Judopass' ) );
Pruefung::ok( 'DokuMe: mit Angabe gespeichert', $entry->submit( $dok, $gerd, [ $mia + [ 'judopass' => '1' ], array_merge( $tom, [ 'weight' => '', 'judopass' => '0' ] ) ] ) === true );
Pruefung::gleich( 'Judopass ja/nein gespeichert', array_map( 'intval', array_column( $model->entries( $dok['id'], $gerd ), 'judopass' ) ), [ 1, 0 ] );
Pruefung::ok( 'CSV: Spalte Judopass', str_contains( $entry->csv( $dok, 'JVL' ), '"Judopass"' ) );
Pruefung::ok( 'ohne DokuMe: keine Spalte Judopass', ! str_contains( $entry->csv( $model->find( $wid ), 'JVL' ), 'Judopass' ) );
Pruefung::ok( 'Website: Judopass als ja/nein', $service->payload( $dok, $gerd, false )['entries'][1]['judopass'] === false );
$model->delete( (int) $dok['id'] );

// BJV-Meldeliste als Excel
Pruefung::gleich( 'BJV-Zeile: Name, Jahrgang, Geschlecht, AK, Gewicht', BjvExport::row_values( array_merge( $mia, [ 'class_key' => 'U11' ] ) ), [ 'Muster', 'Mia', 2017, 'w', 'U11', '-28 kg' ] );
Pruefung::gleich( 'BJV-Zeile: gewichtsnah', BjvExport::row_values( array_merge( $tom, [ 'class_key' => 'U9', 'weight' => '' ] ) )[5], 'gewichtsnah' );
if ( class_exists( 'ZipArchive' ) ) {
    $xlsx = BjvExport::build( [ 'name' => 'Bezirkseinzel & Co', 'date' => '06.02.2027', 'club' => 'JVL', 'contact_person' => 'Olaf Orga', 'contact_mail' => 'olaf@example.test' ], $entry->list_for_orga( $model->find( $wid ) ) );
    $datei = tempnam( sys_get_temp_dir(), 'bjv' );
    file_put_contents( $datei, (string) $xlsx );
    $zip = new ZipArchive();
    $auf = $zip->open( $datei ) === true;
    $blatt = $auf ? (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) : '';
    $mappe = $auf ? (string) $zip->getFromName( 'xl/workbook.xml' ) : '';
    if ( $auf ) { $zip->close(); }
    unlink( $datei );
    Pruefung::ok( 'Excel: gültiges xlsx mit Blatt „Meldung“', $auf && str_contains( $mappe, 'name="Meldung"' ) );
    Pruefung::ok( 'Excel: Kopf wie das BJV-Formular', str_contains( $blatt, 'Name der Veranstaltung:' ) && str_contains( $blatt, 'Kampfrichter Sonntag:' ) && str_contains( $blatt, 'Gewichtsklasse' ) );
    Pruefung::ok( 'Excel: Veranstaltung maskiert eingetragen', str_contains( $blatt, 'Bezirkseinzel &amp; Co' ) );
    Pruefung::ok( 'Excel: Starter eingetragen', str_contains( $blatt, '>Muster<' ) && str_contains( $blatt, '>Aktiv<' ) );
    Pruefung::ok( 'Excel: 40 nummerierte Zeilen', str_contains( $blatt, 'r="A50"' ) );
    $xml = new DOMDocument();
    Pruefung::ok( 'Excel: Blatt ist wohlgeformtes XML', @$xml->loadXML( $blatt ) );
} else {
    echo "  (ZipArchive fehlt – Excel-Test übersprungen)\n";
}

// E-Mails an die Melder
$werte = EntryMail::values( $model->find( $wid ), 'Gerd Gast', $model->entries( $wid, $gerd ), 'Olaf Orga' );
Pruefung::ok( 'Mail: Platzhalter gefüllt', str_contains( EntryMail::fill( 'Hallo {name}, {wettkampf} am {datum}: {starter} – {absender}', $werte ), 'Hallo Gerd Gast, Bezirkseinzel am 06.02.2027: – Mia Muster (2017)' ) );
Pruefung::ok( 'Mail: Vorlagen aus den Einstellungen', str_contains( EntryMail::defaults()['entry_mail_sent_body'], '{starter}' ) && str_contains( EntryMail::defaults()['entry_mail_confirmed_subject'], '{wettkampf}' ) );
$wpdb->insert( 'wp_vp_people', [ 'display_name' => 'Ohne Mail', 'source' => 'link' ] );
$ohne = -(int) end( $wpdb->t['wp_vp_people'] )['id'];
$model->replace_entries( $wid, $ohne, [ array_merge( $tom, [ 'first_name' => 'Kai', 'weight' => '' ] ) ] );
$ergebnis = EntryMail::send( $model->find( $wid ), 'sent', [ $gerd, $ohne ], 'Olaf Orga' );
Pruefung::gleich( 'Mail „abgeschickt“: an Melder mit Adresse, ohne Adresse genannt', [ $ergebnis['sent'], implode( ',', $ergebnis['skipped'] ) ], [ 1, 'Ohne Mail' ] );
Pruefung::gleich( 'Mail „bestätigt“: an alle Melder', EntryMail::send( $model->find( $wid ), 'confirmed' )['sent'], 2 );
$model->delete_entries( $wid, $ohne );

$model->set_status( $wid, 'closed' );
Pruefung::ok( 'nach Meldeschluss nichts mehr', is_wp_error( $entry->submit( $model->find( $wid ), $gerd, [ $mia ] ) ) );

Pruefung::gleich( 'Löschtermin = Wettkampf + 30 Tage', EntryCleanup::due_date( $w ), '2027-03-08' );
$alt = $model->save( [ 'kind' => 'entry', 'title' => 'vorbei', 'status' => 'closed', 'event_date' => '2026-06-01', 'classes' => $bjv['classes'], 'purge_days' => 30 ] );
$model->replace_entries( $alt, $gerd, [ $mia + [ 'class_key' => 'U11' ] ] );
Pruefung::gleich( 'Aufräumjob löscht fällige Meldungen', ( new EntryCleanup() )->run(), 1 );
Pruefung::ok( 'vergangene Meldung leer, kommende unberührt', ! $model->entries( $alt ) && count( $model->entries( $wid ) ) === 4 );
Pruefung::ok( 'als gelöscht vermerkt', ! empty( $model->find( $alt )['purged_at'] ) );

$model->delete( $wid );
Pruefung::gleich( 'Löschen der Umfrage räumt Meldungen ab', count( $model->entries( $wid ) ), 0 );

// =====================================================================
echo "\nIdeensammlung\n";

$iid = $model->save( [ 'kind' => 'text', 'title' => 'Slogan fürs Vereinsshirt', 'status' => 'open', 'max_texts' => 2, 'results' => 'after_close' ] );
$i   = $model->find( $iid );
Pruefung::ok( 'Gast schreibt zwei Vorschläge', $service->vote( $i, $gabi, [ 'texts' => [ 'Fallen, aufstehen, weitermachen', '  ', 'Ippon im Herzen' ] ] ) === true );
Pruefung::ok( 'drei Vorschläge bei Höchstzahl 2 abgelehnt', is_wp_error( $service->vote( $i, $anna, [ 'texts' => [ 'A', 'B', 'C' ] ] ) ) );
Pruefung::ok( 'leere Vorschläge abgelehnt', is_wp_error( $service->vote( $i, $anna, [ 'texts' => [ '', ' ' ] ] ) ) );
Pruefung::ok( 'zu lange Vorschläge abgelehnt', is_wp_error( $service->vote( $i, $anna, [ 'texts' => [ str_repeat( 'x', PollService::TEXT_MAX + 1 ) ] ] ) ) );
Pruefung::ok( 'Mitglied schreibt, doppelt zählt einmal', $service->vote( $i, $anna, [ 'texts' => [ 'Ippon im Herzen', 'ippon im herzen' ] ] ) === true );
Pruefung::gleich( 'Vorschläge der Mitglieder', array_column( $model->texts( $iid, $anna ), 'text' ), [ 'Ippon im Herzen' ] );

$alt_id = (int) $model->texts( $iid, $gabi )[0]['id'];
$service->vote( $i, $gabi, [ 'texts' => [ 'Fallen, aufstehen, weitermachen', 'Judo verbindet' ] ] );
$g = $model->texts( $iid, $gabi );
Pruefung::ok( 'Ändern: unveränderter Vorschlag behält seine Zeile', (int) $g[0]['id'] === $alt_id );
Pruefung::gleich( 'Ändern: ersetzter Vorschlag ist weg', array_column( $g, 'text' ), [ 'Fallen, aufstehen, weitermachen', 'Judo verbindet' ] );
Pruefung::gleich( 'zwei Personen', $model->voter_count( $iid ), 2 );

$pay = $service->payload( $i, $anna, false );
Pruefung::ok( 'nach Abschluss: während der Sammlung keine fremden sichtbar', $pay['results'] === null && $pay['mine'] === [ 'Ippon im Herzen' ] );
$pay = $service->payload( $i, $anna, true );
Pruefung::gleich( 'nach eigenem Vorschlag alle sichtbar, mit Namen, älteste zuerst', array_column( $pay['results'], 'name' ), [ 'Gabi Gast', 'Anna Aktiv', 'Gabi Gast' ] );
Pruefung::ok( 'eigener Vorschlag markiert', $pay['results'][1]['mine'] === true && $pay['results'][0]['mine'] === false );

$geheim = $model->find( $model->save( [ 'kind' => 'text', 'title' => 'geheim', 'status' => 'open', 'results' => 'nobody' ] ) );
$service->vote( $geheim, $anna, [ 'texts' => [ 'Idee' ] ] );
Pruefung::ok( 'niemand: auf der Website unsichtbar', $service->payload( $geheim, $gabi, false )['results'] === null );
Pruefung::ok( 'niemand: Orgateam sieht Vorschläge ohne Namen', $service->payload( $geheim, $orga, true )['results'][0]['name'] === null );

$model->delete_text( (int) $g[1]['id'] );
Pruefung::gleich( 'Orgateam löscht einen Vorschlag', count( $model->texts( $iid ) ), 2 );

$abst = PollsAdmin::choice_from_texts( $model, $service, $model->find( $iid ) );
$ab   = $model->find( $abst );
Pruefung::ok( 'als Abstimmung übernommen: Entwurf, Auswahl', $ab['kind'] === 'choice' && $ab['status'] === 'draft' );
Pruefung::gleich( 'Antworten = verschiedene Vorschläge', array_column( $model->options( $abst ), 'label' ), [ 'Fallen, aufstehen, weitermachen', 'Ippon im Herzen' ] );

$model->delete_votes( $iid, $anna );
$model->delete_texts( $iid, $anna );
Pruefung::gleich( 'Zurückziehen entfernt die eigenen Vorschläge', count( $model->texts( $iid, $anna ) ), 0 );
$model->delete( $iid );
Pruefung::gleich( 'Löschen der Umfrage räumt Vorschläge ab', count( $model->texts( $iid ) ), 0 );

Pruefung::abschluss( 'Umfragen' );
