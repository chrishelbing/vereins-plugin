<?php
/**
 * Terminbanner [vp_banner]: welcher Termin wann erscheint.
 *
 * Aufruf im Plugin-Ordner:   php tools/bannertest.php
 *
 * Der Banner steht auf der Startseite und ist für jeden sichtbar. Drei Dinge
 * dürfen deshalb nicht schiefgehen:
 *
 *   – Er erscheint nur, wenn ein Administrator ihn am Termin eingeschaltet
 *     hat, und nur für freigegebene Termine. Ein vorgeschlagener, noch nicht
 *     geprüfter Termin darf nicht öffentlich auf der Startseite landen.
 *   – Er kommt pünktlich und verschwindet von selbst – niemand soll nach dem
 *     Sommerfest daran denken müssen, ihn abzuschalten.
 *   – Eine Serie belegt höchstens einen Platz, und zwar mit dem nächsten
 *     Termin.
 *
 * Die Zeit steht im Ersatz fest auf 2026-08-02 12:00 (current_time()).
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

stub_load( [
    'Support/Tags.php',
    'Events/EventTypeModel.php',
    'Events/EventModel.php',
    'Events/RecurrenceExpander.php',
    'Events/BannerSelector.php',
] );

use VereinsPlugin\Events\BannerSelector;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\RecurrenceExpander;

$jetzt = '2026-08-02 12:00:00';

/** Eine einzelne Instanz, wie sie der Expander liefert. */
function instanz( int $id, string $start, string $ende, ?int $vorlauf ): array {
    return [
        'event_id'           => $id,
        'title'              => "Termin $id",
        'start_at'           => $start,
        'end_at'             => $ende,
        'banner_days_before' => $vorlauf,
    ];
}

/** Nur die Termin-IDs einer Auswahl – übersichtlicher im Vergleich. */
function ids( array $auswahl ): array {
    return array_map( fn( $i ) => (int) $i['event_id'], $auswahl );
}

echo "Terminbanner\n";
echo str_repeat( '-', 74 ), "\n";

// ---------------------------------------------------------------------
echo "\nSichtbarkeitsfenster\n";
// ---------------------------------------------------------------------
Pruefung::gleich( 'Fenster beginnt am Tagesanfang',
    BannerSelector::opens_at( '2026-08-16 18:30:00', 14 ), '2026-08-02 00:00:00' );
Pruefung::gleich( 'Vorlauf 0 = ab dem Termintag',
    BannerSelector::opens_at( '2026-08-16 18:30:00', 0 ), '2026-08-16 00:00:00' );
Pruefung::gleich( 'Vorlauf über Monatsgrenze',
    BannerSelector::opens_at( '2026-09-03 10:00:00', 7 ), '2026-08-27 00:00:00' );

Pruefung::gleich( 'genau 14 Tage vorher: sichtbar',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-16 10:00:00', '2026-08-16 18:00:00', 14 ) ], $jetzt ) ), [ 1 ] );
Pruefung::gleich( '15 Tage vorher: noch nicht',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-17 10:00:00', '2026-08-17 18:00:00', 14 ) ], $jetzt ) ), [] );
Pruefung::gleich( 'ohne Banner-Schalter: nie',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-03 10:00:00', '2026-08-03 18:00:00', null ) ], $jetzt ) ), [] );
Pruefung::gleich( 'läuft gerade: sichtbar',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-01 16:00:00', '2026-08-03 17:00:00', 7 ) ], $jetzt ) ), [ 1 ] );
Pruefung::gleich( 'vorbei: verschwunden',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-02 08:00:00', '2026-08-02 11:59:00', 7 ) ], $jetzt ) ), [] );
Pruefung::gleich( 'endet genau jetzt: verschwunden',
    ids( BannerSelector::pick( [ instanz( 1, '2026-08-02 08:00:00', $jetzt, 7 ) ], $jetzt ) ), [] );

// ---------------------------------------------------------------------
echo "\nReihenfolge und Anzahl\n";
// ---------------------------------------------------------------------
$mehrere = [
    instanz( 3, '2026-08-20 10:00:00', '2026-08-20 12:00:00', 30 ),
    instanz( 2, '2026-08-05 10:00:00', '2026-08-05 12:00:00', 30 ),
    instanz( 4, '2026-08-10 10:00:00', '2026-08-10 12:00:00', 30 ),
];
Pruefung::gleich( 'Standard: nur der nächste',      ids( BannerSelector::pick( $mehrere, $jetzt ) ), [ 2 ] );
Pruefung::gleich( 'limit 2: die zwei nächsten',     ids( BannerSelector::pick( $mehrere, $jetzt, 2 ) ), [ 2, 4 ] );
Pruefung::gleich( 'limit größer als Angebot',       ids( BannerSelector::pick( $mehrere, $jetzt, 5 ) ), [ 2, 4, 3 ] );

// ---------------------------------------------------------------------
echo "\nSerien\n";
// ---------------------------------------------------------------------
$serie = [
    'id'                 => 7,
    'event_type_id'      => 1,
    'title'              => 'Training',
    'description'        => '',
    'location'           => '',
    'start_at'           => '2026-07-01 18:00:00',   // mittwochs
    'end_at'             => '2026-07-01 19:30:00',
    'is_full_day'        => 0,
    'is_recurring'       => 1,
    'created_by'         => 1,
    'banner_days_before' => 3,
    'recurrence'         => [ 'frequency' => 'WEEKLY', 'interval_n' => 1, 'weekdays' => null, 'until' => null, 'count' => null ],
    'exceptions'         => [],
];
$instanzen = RecurrenceExpander::expand( [ $serie ], $jetzt, '2026-09-30 00:00:00' );
$auswahl   = BannerSelector::pick( $instanzen, $jetzt, 5 );

Pruefung::ok( 'Expander reicht den Banner-Vorlauf durch',
    ( $instanzen[0]['banner_days_before'] ?? null ) === 3 );
Pruefung::gleich( 'Serie belegt nur einen Platz', count( $auswahl ), 1 );
Pruefung::gleich( 'und zwar mit dem nächsten Termin', $auswahl[0]['original_date'] ?? '', '2026-08-05' );

// Fällt der nächste Termin aus, rückt der übernächste nach – sobald sein
// Fenster offen ist.
$serie['exceptions'] = [ [ 'original_date' => '2026-08-05', 'exception_type' => 'cancelled' ] ];
$instanzen = RecurrenceExpander::expand( [ $serie ], $jetzt, '2026-09-30 00:00:00' );
Pruefung::gleich( 'abgesagt, Nachfolger noch nicht im Fenster',
    count( BannerSelector::pick( $instanzen, $jetzt, 5 ) ), 0 );
Pruefung::gleich( 'drei Tage vorher erscheint der Nachfolger',
    BannerSelector::pick( $instanzen, '2026-08-09 07:00:00', 5 )[0]['original_date'] ?? '', '2026-08-12' );

// ---------------------------------------------------------------------
echo "\nNur freigegebene Termine\n";
// ---------------------------------------------------------------------
$model = new EventModel();
$basis = [
    'event_type_id' => 1,
    'start_at'      => '2026-08-08 14:00:00',
    'end_at'        => '2026-08-08 20:00:00',
    'created_by'    => 1,
];
$frei    = $model->create( $basis + [ 'title' => 'Sommerfest',   'banner_days_before' => 14 ] );
$wartend = $model->create( $basis + [ 'title' => 'Vorschlag',    'banner_days_before' => 14, 'approval_status' => 'pending' ] );
$ohne    = $model->create( $basis + [ 'title' => 'Ohne Banner' ] );
$entwurf = $model->create( $basis + [ 'title' => 'Entwurf',      'banner_days_before' => 14, 'approval_status' => 'draft' ] );

Pruefung::gleich( 'nur der freigegebene mit Schalter', ids( BannerSelector::current( 5 ) ), [ $frei ] );

// Entwurf ↔ live: Der Schalter wirkt sofort, ein Vorschlag lässt sich
// darüber nicht an der Freigabe vorbei live stellen.
Pruefung::gleich( 'Entwurf live geschaltet', $model->set_live( $entwurf, true ), true );
Pruefung::gleich( 'live: erscheint als Banner', ids( BannerSelector::current( 5 ) ), [ $frei, $entwurf ] );
$model->set_live( $entwurf, false );
Pruefung::gleich( 'zurück auf Entwurf: weg', ids( BannerSelector::current( 5 ) ), [ $frei ] );
Pruefung::gleich( 'Vorschlag nicht per Schalter live', $model->set_live( $wartend, true ), false );
Pruefung::gleich( 'Vorschlag bleibt ausstehend', $model->find( $wartend )['approval_status'], 'pending' );

$model->update( $frei, [ 'banner_days_before' => null ] );
Pruefung::gleich( 'abgeschaltet: kein Banner mehr', ids( BannerSelector::current( 5 ) ), [] );

// ---------------------------------------------------------------------
echo "\nCountdown\n";
// ---------------------------------------------------------------------
Pruefung::gleich( 'läuft',   BannerSelector::countdown( instanz( 1, '2026-08-02 10:00:00', '2026-08-02 14:00:00', 0 ), $jetzt ), 'Läuft gerade' );
Pruefung::gleich( 'heute',   BannerSelector::countdown( instanz( 1, '2026-08-02 18:00:00', '2026-08-02 20:00:00', 0 ), $jetzt ), 'Heute' );
Pruefung::gleich( 'morgen',  BannerSelector::countdown( instanz( 1, '2026-08-03 08:00:00', '2026-08-03 20:00:00', 0 ), $jetzt ), 'Morgen' );
Pruefung::gleich( 'in Tagen', BannerSelector::countdown( instanz( 1, '2026-08-12 08:00:00', '2026-08-12 20:00:00', 0 ), $jetzt ), 'In 10 Tagen' );

Pruefung::abschluss( 'Terminbanner' );
