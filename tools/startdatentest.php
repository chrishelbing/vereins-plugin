<?php
/**
 * Die mitgelieferten Startdaten prüfen.
 *
 * Aufruf im Plugin-Ordner:   php tools/startdatentest.php
 *
 * Drei Dinge dürfen hier nicht schiefgehen:
 *
 *   – Die Startdaten entstehen genau einmal. Ein zweiter Durchlauf, ein
 *     Update oder eine gewachsene Installation dürfen keine zweite Garnitur
 *     Termintypen anlegen; sonst steht der Verein irgendwann vor drei
 *     Einträgen namens „Fest“ und weiß nicht, welcher gilt.
 *   – Auf Anforderung kommt nach, was fehlt – und nur das. Was ein Verein
 *     angepasst hat, bleibt, wie es ist.
 *   – Die Vorlagen greifen am Termin. Eine hinterlegte Vorlage, die beim
 *     Anlegen nicht ankommt, fiele erst auf, wenn die Liste leer bleibt.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

stub_load( [
    'Core/Activator.php',
    'Support/Capabilities.php',
    'Members/MemberModel.php',
    'Members/PeopleModel.php',
    'Members/Person.php',
    'Admin/Settings.php',
    'Events/EventModel.php',
    'Events/CompanionModel.php',
    'Events/TemplateModel.php',
    'Events/EventTypeModel.php',
    'Events/ItemModel.php',
    'Support/Tags.php',
    'Events/RecurrenceExpander.php',
    'Core/Seeder.php',
] );

use VereinsPlugin\Core\Seeder;
use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\TemplateModel;

define( 'VP_VERSION', '1.7.1' );

stub_default_roles();

$wpdb  = $GLOBALS['wpdb'];
$admin = stub_user( 'Admin', [ 'administrator' ] );
$GLOBALS['stub_current'] = $admin;

$typen    = new EventTypeModel();
$vorlagen = new TemplateModel();

/** Datenbank und Optionen leeren – für den nächsten Ausgangszustand. */
function zuruecksetzen(): void {
    $GLOBALS['wpdb']->t          = [];
    $GLOBALS['stub_options']     = [];
}

echo "Startdaten\n";
echo str_repeat( '-', 74 ), "\n";

// ---------------------------------------------------------------------
echo "\nGewachsene Installation\n";
// ---------------------------------------------------------------------
// Wer schon eigene Termintypen hat, bekommt beim Update keine Beispiele
// übergestülpt.
$typen->create( [ 'name' => 'Eigener Typ', 'created_by' => $admin ] );

Pruefung::gleich( 'Startdaten werden übersprungen', Seeder::maybe_seed(), false );
Pruefung::gleich( 'Termintypen unverändert',        count( $typen->all() ), 1 );
Pruefung::gleich( 'Marker verhindert erneute Prüfung', (string) get_option( Seeder::OPTION_DONE ), 'skipped' );

// ---------------------------------------------------------------------
echo "\nFrische Installation\n";
// ---------------------------------------------------------------------
zuruecksetzen();

Pruefung::gleich( 'Startdaten werden angelegt', Seeder::maybe_seed(), true );

$angelegte_typen    = $typen->all();
$angelegte_vorlagen = $vorlagen->all();

Pruefung::gleich( 'Termintypen angelegt', count( $angelegte_typen ),    count( Seeder::event_types() ) );
Pruefung::gleich( 'Vorlagen angelegt',    count( $angelegte_vorlagen ), count( Seeder::templates() ) );

// Der Umfang ist absichtlich klein: vier Termintypen, je drei Listen. Wer
// hier etwas hinzufügt, soll die Zahl bewusst ändern müssen – sonst wächst
// die Vorschlagsliste wieder auf eine Länge, die jeder Verein erst einmal
// aufräumt.
Pruefung::gleich( 'vier Termintypen', count( Seeder::event_types() ), 4 );

// „Spiel und Wettkampf“ hat Helferaufgaben, aber bewusst keine Vorlage. Der
// Test hält das fest, weil ein fehlender Verweis sonst wie ein Versehen
// aussieht und beim nächsten Mal „repariert“ würde.
$wettkampf = Seeder::event_types()['wettkampf'];
Pruefung::gleich( 'Wettkampf braucht Helfer',        $wettkampf['needs_helpers'], 1 );
Pruefung::ok( 'Wettkampf ohne Standardvorlage', ! isset( $wettkampf['help'] ) && ! isset( $wettkampf['bring'] ) );

$nach_art = [ 'bring' => 0, 'help' => 0 ];
foreach ( Seeder::templates() as $tpl ) {
    $nach_art[ $tpl['kind'] ]++;
}
Pruefung::gleich( 'drei Mitbringlisten', $nach_art['bring'], 3 );
Pruefung::gleich( 'drei Helferlisten',   $nach_art['help'],  3 );

// Jede Vorlage hat Posten – eine leere Vorlage wäre als Startpunkt wertlos.
$leere = array_filter( $angelegte_vorlagen, fn( $v ) => empty( $v['items'] ) );
Pruefung::gleich( 'keine Vorlage ohne Posten', count( $leere ), 0 );

// Die Verweise am Termintyp müssen auf eine Vorlage der RICHTIGEN Art zeigen.
// Eine Mitbringliste im Helferfeld fiele erst auf, wenn ein Verein sie
// benutzt – und dann steht „Kuchen“ unter den Helferaufgaben.
$art_passt = true;
$hat_verweis = 0;

foreach ( $angelegte_typen as $typ ) {
    foreach ( [ 'default_item_template_id' => 'bring', 'default_help_template_id' => 'help' ] as $spalte => $art ) {
        $id = (int) ( $typ[ $spalte ] ?? 0 );
        if ( ! $id ) {
            continue;
        }
        $hat_verweis++;
        $vorlage = $vorlagen->find( $id );
        if ( ! $vorlage || $vorlage['kind'] !== $art ) {
            $art_passt = false;
        }
    }
}

Pruefung::ok( 'Standardvorlagen sind hinterlegt', $hat_verweis > 0 );
Pruefung::ok( 'Standardvorlagen haben die passende Art', $art_passt );

// Ein Typ ohne Mitbringliste darf auch keine Mitbringvorlage vorgeben.
$widerspruch = [];
foreach ( $angelegte_typen as $typ ) {
    if ( empty( $typ['allows_items'] ) && ! empty( $typ['default_item_template_id'] ) ) {
        $widerspruch[] = $typ['name'] . ' (Mitbringliste)';
    }
    if ( empty( $typ['needs_helpers'] ) && ! empty( $typ['default_help_template_id'] ) ) {
        $widerspruch[] = $typ['name'] . ' (Helferaufgaben)';
    }
}
Pruefung::gleich( 'keine Vorlage an einem Typ, der sie nicht zeigt', $widerspruch, [] );

// ---------------------------------------------------------------------
echo "\nZweiter Durchlauf\n";
// ---------------------------------------------------------------------
Pruefung::gleich( 'legt nichts nach',          Seeder::maybe_seed(), false );
Pruefung::gleich( 'Termintypen nicht doppelt', count( $typen->all() ), count( Seeder::event_types() ) );

// ---------------------------------------------------------------------
echo "\nStartdaten auf Anforderung\n";
// ---------------------------------------------------------------------
// Der Marker überlebt das Deaktivieren des Plugins. Wer die Startdaten
// später doch braucht, fordert sie über den Knopf im Backend an – und darf
// dabei weder Dubletten noch überschriebene Anpassungen bekommen.
Pruefung::gleich( 'zunächst fehlt nichts', Seeder::missing(), [ 'types' => 0, 'templates' => 0 ] );

$alle = $typen->all();
$fest = null;
foreach ( $alle as $typ ) {
    if ( $typ['name'] === Seeder::event_types()['fest']['name'] ) {
        $fest = $typ;
    }
}

// Ein Verein hat „Fest“ gelöscht und „Training und Übung“ umgefärbt.
$typen->delete( (int) $fest['id'] );
$training_id = 0;
foreach ( $alle as $typ ) {
    if ( $typ['name'] === Seeder::event_types()['training']['name'] ) {
        $training_id = (int) $typ['id'];
    }
}
$typen->update( $training_id, [ 'color' => '#000000' ] );

Pruefung::gleich( 'gelöschter Typ wird vermisst', Seeder::missing()['types'], 1 );

$nachgelegt = Seeder::seed_on_demand();

Pruefung::gleich( 'genau der fehlende Typ kommt zurück', $nachgelegt['types'],     1 );
Pruefung::gleich( 'keine Vorlage doppelt',               $nachgelegt['templates'], 0 );
Pruefung::gleich( 'Gesamtzahl wieder vollständig',       count( $typen->all() ), count( Seeder::event_types() ) );
Pruefung::gleich( 'Vorlagen unverändert',                count( $vorlagen->all() ), count( Seeder::templates() ) );
Pruefung::gleich( 'angepasster Typ bleibt angepasst',    $typen->find( $training_id )['color'] ?? '', '#000000' );

// ---------------------------------------------------------------------
echo "\nVorlagen greifen am Termin\n";
// ---------------------------------------------------------------------
// Die Startdaten sind nur dann etwas wert, wenn ein neuer Termin ihre
// Listen auch bekommt. Geprüft wird deshalb der Weg bis zum fertigen
// Termin – mit demselben Aufruf, den die Terminseite benutzt.
$fest_typ = 0;
foreach ( $typen->all() as $typ ) {
    if ( $typ['name'] === Seeder::event_types()['fest']['name'] ) {
        $fest_typ = (int) $typ['id'];
    }
}

Pruefung::ok( 'Termintyp „Fest“ vorhanden', $fest_typ > 0 );

$fest = $typen->find( $fest_typ );

Pruefung::ok( 'Mitbringvorlage hinterlegt',  (int) ( $fest['default_item_template_id'] ?? 0 ) > 0 );
Pruefung::ok( 'Helfervorlage hinterlegt',    (int) ( $fest['default_help_template_id'] ?? 0 ) > 0 );

$events   = new VereinsPlugin\Events\EventModel();
$event_id = $events->create( [
    'event_type_id' => $fest_typ,
    'title'         => 'Sommerfest',
    'start_at'      => '2027-07-05 15:00:00',
    'end_at'        => '2027-07-05 23:00:00',
    'created_by'    => $admin,
] );

$items = new VereinsPlugin\Events\ItemModel();
$items->apply_template( $event_id, '2027-07-05', (int) $fest['default_item_template_id'], $admin );
$items->apply_template( $event_id, '2027-07-05', (int) $fest['default_help_template_id'], $admin );

$bring = $items->for_instance( $event_id, '2027-07-05', 0, 'bring' );
$help  = $items->for_instance( $event_id, '2027-07-05', 0, 'help' );

Pruefung::gleich( 'Mitbringliste aus der Vorlage',  count( $bring ), count( Seeder::templates()['bring_fest']['items'] ) );
Pruefung::gleich( 'Helferaufgaben aus der Vorlage', count( $help ),  count( Seeder::templates()['help_fest']['items'] ) );

// Zweimal dieselbe Vorlage darf die Liste nicht verdoppeln – das Orgateam
// wendet sie schon mal versehentlich erneut an.
$items->apply_template( $event_id, '2027-07-05', (int) $fest['default_item_template_id'], $admin );
Pruefung::gleich( 'erneutes Anwenden verdoppelt nichts', count( $items->for_instance( $event_id, '2027-07-05', 0, 'bring' ) ), count( $bring ) );

// Die Posten stehen als Bedarf des Orgateams da, nicht als fremder
// Freitexteintrag – sonst ließe sich nichts davon übernehmen.
$nur_bedarf = array_filter( $bring, fn( $p ) => empty( $p['is_request'] ) );
Pruefung::gleich( 'Posten sind übernehmbarer Bedarf', count( $nur_bedarf ), 0 );

Pruefung::abschluss( 'Startdaten' );
