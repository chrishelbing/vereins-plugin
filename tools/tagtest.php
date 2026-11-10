<?php
/**
 * Infotermine und Tags an Terminen.
 *
 * Aufruf im Plugin-Ordner:   php tools/tagtest.php
 *
 *   – Ein Termintyp ohne Zusagen macht seine Termine zu reiner Information.
 *     Fehlt das Feld (ältere Daten, Zeile ohne Join), bleibt alles wie vor
 *     dem Schalter: Zusagen sind möglich.
 *   – Tags gibt der Termintyp vor; am Termin bleibt nur, was der Typ kennt,
 *     und zwar in der Reihenfolge des Typs.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

stub_load( [
    'Support/DateRange.php',
    'Support/Tags.php',
    'Events/EventTypeModel.php',
    'Events/EventModel.php',
    'Events/RecurrenceExpander.php',
] );

use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\RecurrenceExpander;
use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Support\Tags;

// ---------------------------------------------------------------------
echo "\nInfotermine\n";
// ---------------------------------------------------------------------
Pruefung::gleich( 'ohne Feld: Zusagen möglich',   EventTypeModel::rsvp_allowed( [] ), true );
Pruefung::gleich( 'NULL: Zusagen möglich',        EventTypeModel::rsvp_allowed( [ 'allows_rsvp' => null ] ), true );
Pruefung::gleich( '1: Zusagen möglich',           EventTypeModel::rsvp_allowed( [ 'allows_rsvp' => '1' ] ), true );
Pruefung::gleich( '0: nur Information',           EventTypeModel::rsvp_allowed( [ 'allows_rsvp' => '0' ] ), false );

$info = [
    'id' => 7, 'event_type_id' => 1, 'title' => 'Hallenschließung', 'description' => '', 'location' => '',
    'start_at' => '2026-08-10 00:00:00', 'end_at' => '2026-08-10 23:59:00', 'is_full_day' => 1,
    'is_recurring' => 0, 'created_by' => 1, 'allows_rsvp' => 0, 'tags' => '["U9","U11"]',
];
$inst = RecurrenceExpander::expand( [ $info ], '2026-08-01 00:00:00', '2026-08-31 00:00:00' );
Pruefung::gleich( 'Instanz trägt den Infoschalter', $inst[0]['allows_rsvp'] ?? null, false );
Pruefung::gleich( 'Instanz trägt die Tags',          implode( ',', $inst[0]['tags'] ?? [] ), 'U9,U11' );

// ---------------------------------------------------------------------
echo "\nTags am Termintyp\n";
// ---------------------------------------------------------------------
$vorgabe = Tags::parse( "U9, U11, U12; U13\nU15, U16, U18, M&F, Veteranen, u9, , " );
Pruefung::gleich( 'Eingabe zerlegt, Doppelte weg', implode( '|', $vorgabe ), 'U9|U11|U12|U13|U15|U16|U18|M&F|Veteranen' );
Pruefung::gleich( 'leere Eingabe: keine Tags',      Tags::encode( Tags::parse( ' , ' ) ), null );
Pruefung::gleich( 'Hin und zurück',                 Tags::decode( Tags::encode( $vorgabe ) ) === $vorgabe, true );
Pruefung::gleich( 'Unsinn in der Spalte: leer',     Tags::decode( '{kaputt' ), [] );

// ---------------------------------------------------------------------
echo "\nTags am Termin\n";
// ---------------------------------------------------------------------
Pruefung::gleich( 'nur Vorgegebenes, Reihenfolge des Typs',
    implode( '|', Tags::restrict( [ 'Veteranen', 'U13', 'Erfunden', 'u9' ], $vorgabe ) ), 'U9|U13|Veteranen' );
Pruefung::gleich( 'Typ ohne Tags: nichts bleibt', Tags::restrict( [ 'U9' ], [] ), [] );

$model = new EventModel();
$id = $model->create( [
    'event_type_id' => 1, 'title' => 'Kreismeisterschaft', 'start_at' => '2026-11-14 09:00:00',
    'end_at' => '2026-11-14 16:00:00', 'created_by' => 1, 'tags' => Tags::encode( [ 'U11', 'U13' ] ),
] );
Pruefung::gleich( 'gespeichert',  Tags::decode( $model->find( $id )['tags'] ?? null ), [ 'U11', 'U13' ] );
$model->update( $id, [ 'tags' => null ] );
Pruefung::gleich( 'geleert',      Tags::decode( $model->find( $id )['tags'] ?? null ), [] );

// ---------------------------------------------------------------------
echo "\nGanztägige Termine\n";
// ---------------------------------------------------------------------
// Der Fehler, um den es geht: Sa–So ganztägig, Ende als „So 00:00“
// gespeichert, zählte als eintägiger Termin am Samstag.
[ $von, $bis ] = DateRange::full_day_bounds( '2026-11-14', '2026-11-15' );
Pruefung::gleich( 'reine Datumsangaben',       "$von / $bis", '2026-11-14 00:00:00 / 2026-11-15 23:59:00' );
Pruefung::gleich( 'zwei Tage',                 DateRange::days( $von, $bis ), 2 );
Pruefung::gleich( 'Kopf zeigt beide Tage',     DateRange::badge( $von, $bis )['day'], '14.–15.' );
[ $von, $bis ] = DateRange::full_day_bounds( '2026-11-14T00:00', '2026-11-15T00:00' );
Pruefung::gleich( 'Uhrzeit wird ignoriert',    "$von / $bis", '2026-11-14 00:00:00 / 2026-11-15 23:59:00' );
[ $von, $bis ] = DateRange::full_day_bounds( '2026-11-14', '' );
Pruefung::gleich( 'ohne Ende: ein Tag',        "$von / $bis", '2026-11-14 00:00:00 / 2026-11-14 23:59:00' );
[ $von, $bis ] = DateRange::full_day_bounds( '2026-11-15', '2026-11-14' );
Pruefung::gleich( 'Ende vor Start: ein Tag',   $bis, '2026-11-15 23:59:00' );
Pruefung::gleich( 'eintägig: ein Tag im Kopf', DateRange::badge( ...DateRange::full_day_bounds( '2026-11-14', '2026-11-14' ) )['day'], '14.' );

Pruefung::abschluss( 'Infotermine und Tags' );
