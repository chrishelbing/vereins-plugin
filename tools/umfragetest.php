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
] );

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Polls\PollModel;
use VereinsPlugin\Polls\PollService;
use VereinsPlugin\Polls\PollsAdmin;

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

$tid = $model->save( [ 'kind' => 'date', 'title' => 'Weihnachtsfeier', 'status' => 'open', 'allow_maybe' => 1, 'results' => 'after_vote' ] );
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

$nid = $model->save( [ 'kind' => 'choice', 'title' => 'Nachwuchspreis', 'status' => 'open', 'anonymous' => 1, 'login_required' => 1, 'results' => 'after_close' ] );
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

Pruefung::ok( 'Ergebnis nach Ende: Mitglied sieht es vorher nicht', ! $service->may_see_results( $n, $anna, true, false ) );
Pruefung::ok( 'Orgateam sieht es immer', $service->may_see_results( $n, $orga, false, true ) );

$model->set_status( $nid, 'closed' );
$n = $model->find( $nid );
Pruefung::ok( 'beendete Umfrage nimmt nichts mehr an', is_wp_error( $service->vote( $n, $anna, [ 'choices' => [ $tom ] ] ) ) );
Pruefung::ok( 'nach dem Ende sichtbar', $service->may_see_results( $n, $anna, true, false ) );

$frist = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'abgelaufen', 'status' => 'open', 'closes_at' => '2026-01-01 00:00:00' ] ) );
Pruefung::ok( 'Ende überschritten gilt als beendet', ! $service->is_open( $frist ) && $service->is_closed( $frist ) );

$mehr = $model->find( $model->save( [ 'kind' => 'choice', 'title' => 'Mehrfach', 'status' => 'open', 'multiple' => 1, 'results' => 'after_vote' ] ) );
$model->save_options( (int) $mehr['id'], [ [ 'label' => 'A' ], [ 'label' => 'B' ] ] );
$ab = array_map( 'intval', array_column( $model->options( (int) $mehr['id'] ), 'id' ) );
Pruefung::ok( 'Mehrfachauswahl nimmt zwei an', $service->vote( $mehr, $gabi, [ 'choices' => $ab ] ) === true );
Pruefung::ok( 'nach eigener Abstimmung sichtbar', $service->may_see_results( $mehr, $gabi, true, false ) );
Pruefung::ok( 'vor eigener Abstimmung nicht', ! $service->may_see_results( $mehr, $anna, false, false ) );

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

Pruefung::abschluss( 'Umfragen' );
