<?php
/**
 * QR-Encoder gegen hinterlegte Sollwerte prüfen.
 *
 * Aufruf im Plugin-Ordner:   php tools/qrtest.php
 *
 * Der Encoder in Support\QrCode ist selbst gebaut. Seine Richtigkeit wurde
 * einmalig gegen eine Referenzimplementierung nachgewiesen und die
 * erzeugten Codes mit einem echten Scanner zurückgelesen; die Ergebnisse
 * dieser Prüfung liegen als Prüfsummen in lib/qr-sollwerte.json.
 *
 * Dieser Test wiederholt den Nachweis nicht – dafür bräuchte es einen
 * Decoder. Er stellt sicher, dass sich am Ergebnis nichts ändert. Bei einem
 * QR-Code ist das die entscheidende Eigenschaft: Ein Fehler darin fällt erst
 * auf, wenn vierzig Flyer gedruckt sind und niemand den Code scannen kann.
 *
 * Weicht ein Wert ab, ist das kein Grund, die Sollwerte anzupassen. Erst
 * klären, warum sich das Ergebnis geändert hat.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';
stub_load( [ 'Support/QrCode.php' ] );

use VereinsPlugin\Support\QrCode;

$sollwerte = json_decode( (string) file_get_contents( __DIR__ . '/lib/qr-sollwerte.json' ), true );

if ( ! $sollwerte ) {
    echo "Sollwerte fehlen oder sind unlesbar: tools/lib/qr-sollwerte.json\n";
    exit( 1 );
}

echo "QR-Codes gegen Sollwerte\n";

foreach ( $sollwerte as $name => $soll ) {
    $m     = QrCode::matrix( $soll['text'] );
    $pruef = sha1( implode( '', array_map( fn( $r ) => implode( '', $r ), $m['modules'] ) ) );

    Pruefung::gleich( "$name – Version",   $m['version'], (int) $soll['version'] );
    Pruefung::gleich( "$name – Größe",     $m['size'],    (int) $soll['size'] );
    Pruefung::gleich( "$name – Maske",     $m['mask'],    (int) $soll['mask'] );
    Pruefung::gleich( "$name – Modulbild", $pruef,        (string) $soll['pruef'] );
}

echo "\nGrenzfälle\n";

// Überlänge muss abbrechen statt still einen zu kleinen Code zu liefern.
Pruefung::gleich(
    'zu langer Inhalt liefert leeres SVG',
    QrCode::svg( str_repeat( 'x', 800 ) ),
    ''
);

$svg = QrCode::svg( 'https://verein.test/t/a1' );
Pruefung::ok( 'SVG hat einen viewBox', str_contains( $svg, 'viewBox=' ) );
Pruefung::ok( 'SVG hat weißen Grund', str_contains( $svg, 'fill="#fff"' ) );
Pruefung::ok( 'SVG enthält einen Pfad', str_contains( $svg, '<path d="M' ) );

Pruefung::abschluss( 'QR-Encoder' );
