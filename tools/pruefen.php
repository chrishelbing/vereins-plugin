<?php
/**
 * Alle Prüfungen des Plugins auf einmal.
 *
 * Aufruf im Plugin-Ordner:   php tools/pruefen.php
 *
 * Das ist der eine Befehl, der vor jedem Abschluss laufen sollte. Jede
 * Teilprüfung läuft in einem eigenen Prozess – so stören sich die
 * WordPress-Ersätze der Tests nicht gegenseitig, und ein Absturz in einem
 * Test verhindert nicht, dass die übrigen laufen.
 *
 * Rückgabewert 1, sobald eine Prüfung anschlägt.
 */

declare( strict_types=1 );

$pruefungen = [
    'check.php'          => 'Struktur, Symbole, Shortcodes, Einstellungen',
    'zugriffstest.php'   => 'Anmeldung, Mitgliederbereich, Backend, Rollen',
    'personentest.php'   => 'Gast, Aufnahme, Ablehnung, Bestandsübernahme',
    'startdatentest.php' => 'Startdaten: einmalig, nachlegbar, am Termin wirksam',
    'qrtest.php'         => 'QR-Encoder gegen hinterlegte Sollwerte',
];

$exec_frei = function_exists( 'exec' )
    && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true );

if ( ! $exec_frei ) {
    echo "exec() ist gesperrt – die Prüfungen bitte einzeln aufrufen:\n";
    foreach ( array_keys( $pruefungen ) as $datei ) {
        echo "  php tools/$datei\n";
    }
    exit( 1 );
}

$fehlgeschlagen = [];

foreach ( $pruefungen as $datei => $beschreibung ) {
    echo "\n", str_repeat( '=', 74 ), "\n";
    echo strtoupper( str_replace( '.php', '', $datei ) ), "  –  $beschreibung\n";
    echo str_repeat( '=', 74 ), "\n";

    $ausgabe = [];
    exec(
        escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $datei ) . ' 2>&1',
        $ausgabe,
        $code
    );

    echo implode( "\n", $ausgabe ), "\n";

    if ( $code !== 0 ) {
        $fehlgeschlagen[] = $datei;
    }
}

echo "\n", str_repeat( '=', 74 ), "\n";

if ( $fehlgeschlagen ) {
    echo "Nicht bestanden: " . implode( ', ', $fehlgeschlagen ) . "\n";
    exit( 1 );
}

echo "Alle " . count( $pruefungen ) . " Prüfungen bestanden.\n";
exit( 0 );
