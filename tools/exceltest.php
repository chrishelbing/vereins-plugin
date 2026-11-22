<?php
/**
 * Excel-Export: Support\XlsxWriter erzeugt eine gültige .xlsx mit mehreren
 * Tabellen (Blattnamen, Titel, Kopfzeile, Umlaute, Sonderzeichen, leere Liste).
 *
 *   php tools/exceltest.php
 */
require_once __DIR__ . '/lib/wp-stub.php';
stub_load( [ 'Support/XlsxWriter.php' ] );

use VereinsPlugin\Support\XlsxWriter;

echo "Excel-Export\n";
Pruefung::gleich( 'Spaltenbuchstaben', [ XlsxWriter::col( 1 ), XlsxWriter::col( 26 ), XlsxWriter::col( 27 ) ], [ 'A', 'Z', 'AA' ] );
Pruefung::gleich( 'Blattnamen: Sonderzeichen raus, max. 31, eindeutig', XlsxWriter::sheet_names( [ 'Helfer/Aufbau', str_repeat( 'x', 40 ), 'Helfer Aufbau' ] ), [ 'Helfer Aufbau', str_repeat( 'x', 31 ), 'Helfer Aufbau 2' ] );

if ( ! class_exists( 'ZipArchive' ) ) {
    echo "  (ZipArchive fehlt – Datei-Test übersprungen)\n";
} else {
    $inhalt = XlsxWriter::build( [
        [ 'name' => 'Teilnehmer', 'title' => 'Teilnehmer: Weihnachtsfeier – 05.12.2026', 'header' => [ 'Name', 'Art' ], 'rows' => [ [ 'Jürgen Tollpatsch', 'Mitglied' ], [ 'A & B <C>', "Zeile\x01" ] ] ],
        [ 'name' => 'Mitbringliste', 'header' => [ 'Posten', 'Benötigt' ], 'rows' => [ [ 'Kuchen', 3 ] ] ],
        [ 'name' => 'Helfer', 'header' => [ 'Name' ], 'rows' => [], 'empty' => 'Noch keine Helfer.' ],
    ] );
    $datei = tempnam( sys_get_temp_dir(), 'xl' );
    file_put_contents( $datei, (string) $inhalt );
    $zip = new ZipArchive();
    $auf = $zip->open( $datei ) === true;
    $wb  = $auf ? (string) $zip->getFromName( 'xl/workbook.xml' ) : '';
    $s1  = $auf ? (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) : '';
    $s2  = $auf ? (string) $zip->getFromName( 'xl/worksheets/sheet2.xml' ) : '';
    $s3  = $auf ? (string) $zip->getFromName( 'xl/worksheets/sheet3.xml' ) : '';
    if ( $auf ) { $zip->close(); }
    unlink( $datei );
    Pruefung::ok( 'drei Tabellen mit Namen', $auf && substr_count( $wb, '<sheet ' ) === 3 && str_contains( $wb, 'name="Mitbringliste"' ) );
    Pruefung::ok( 'Titel und Kopfzeile', str_contains( $s1, 'Teilnehmer: Weihnachtsfeier' ) && str_contains( $s1, '>Name<' ) );
    Pruefung::ok( 'Umlaute und Sonderzeichen sicher', str_contains( $s1, 'Jürgen' ) && str_contains( $s1, 'A &amp; B &lt;C&gt;' ) && ! str_contains( $s1, "\x01" ) );
    Pruefung::ok( 'Kopfzeile bleibt beim Scrollen stehen', str_contains( $s1, 'state="frozen"' ) );
    Pruefung::ok( 'Zahl als Zahl', str_contains( $s2, '<v>3</v>' ) );
    Pruefung::ok( 'leere Liste mit Hinweis', str_contains( $s3, 'Noch keine Helfer.' ) && ! str_contains( $s3, 'autoFilter' ) );
    $ok = true;
    foreach ( [ $wb, $s1, $s2, $s3 ] as $xml ) {
        $d = new DOMDocument();
        $ok = $ok && @$d->loadXML( $xml );
    }
    Pruefung::ok( 'alles wohlgeformtes XML', $ok );
}
Pruefung::abschluss( 'Excel-Export' );
