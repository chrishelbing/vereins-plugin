<?php

namespace VereinsPlugin\Polls;

/**
 * Meldeliste als Excel-Datei im Aufbau des BJV-Formulars.
 *
 * Vorlage: „Meldeformular für alle Wettkämpfe“ des Brandenburgischen
 * Judo-Verbands (bjv-judo.de, Termine → Wettkampf-Meldeliste-allg.xlsx,
 * Stand Januar 2025). Ein Blatt „Meldung“:
 *
 *   A2–A8  Name der Veranstaltung, Datum, meldender Verein/LV,
 *          Ansprechpartner, Kontakt (Mail), Kampfrichter Samstag/Sonntag
 *          (Beschriftung A:B verbunden, Wert C:G verbunden)
 *   Zeile 10  Nr. | Name | Vorname | Jahrgang | Geschlecht | AK | Gewichtsklasse
 *   ab 11  je Starter eine Zeile (die Vorlage hat 40; mehr werden angehängt)
 *
 * Erzeugt wird eine schlichte .xlsx (Office Open XML) ohne Bibliothek – nur
 * ZipArchive, das auf praktisch jedem Server vorhanden ist.
 */
class BjvExport {

    public const ROWS_MIN = 40;

    /**
     * @param array $kopf   name, date, club, contact_person, contact_mail, referee_sat, referee_sun
     * @param array $zeilen Starter aus EntryService::list_for_orga()
     * @return string|null  Inhalt der .xlsx; null, wenn ZipArchive fehlt
     */
    public static function build( array $kopf, array $zeilen ): ?string {
        if ( ! class_exists( '\ZipArchive' ) ) {
            return null;
        }
        $datei = wp_tempnam( 'meldeliste.xlsx' );
        $zip   = new \ZipArchive();
        if ( $zip->open( $datei, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            return null;
        }
        $zip->addFromString( '[Content_Types].xml', self::content_types() );
        $zip->addFromString( '_rels/.rels', self::rels() );
        $zip->addFromString( 'xl/workbook.xml', self::workbook() );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() );
        $zip->addFromString( 'xl/styles.xml', self::styles() );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet( $kopf, $zeilen ) );
        $zip->close();
        $inhalt = (string) file_get_contents( $datei );
        @unlink( $datei );
        return $inhalt;
    }

    /** Die Werte einer Tabellenzeile – Gewicht wie im Formular, gewichtsnah ausgeschrieben. */
    public static function row_values( array $z ): array {
        return [
            (string) $z['last_name'],
            (string) $z['first_name'],
            (int) $z['birth_year'],
            $z['sex'] === 'w' ? 'w' : 'm',
            (string) $z['class_key'],
            $z['weight'] !== '' ? ClassTemplates::weight_label( (string) $z['weight'] ) : 'gewichtsnah',
        ];
    }

    private static function sheet( array $kopf, array $zeilen ): string {
        $x = static fn( $t ) => htmlspecialchars( (string) $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
        $text = static fn( string $ref, $wert, int $stil = 0 ) => $wert === '' || $wert === null
            ? "<c r=\"{$ref}\" s=\"{$stil}\"/>"
            : "<c r=\"{$ref}\" t=\"inlineStr\" s=\"{$stil}\"><is><t xml:space=\"preserve\">" . $x( $wert ) . '</t></is></c>';
        $zahl = static fn( string $ref, int $wert, int $stil = 0 ) => "<c r=\"{$ref}\" s=\"{$stil}\"><v>{$wert}</v></c>";

        $rows = [];
        $felder = [
            2 => [ 'Name der Veranstaltung:', $kopf['name'] ?? '' ],
            3 => [ 'Datum der Veranstaltung:', $kopf['date'] ?? '' ],
            4 => [ 'meldender Verein/LV:', $kopf['club'] ?? '' ],
            5 => [ 'Ansprechpartner:', $kopf['contact_person'] ?? '' ],
            6 => [ 'Kontakt (Mail-Adresse):', $kopf['contact_mail'] ?? '' ],
            7 => [ 'Kampfrichter Samstag:', $kopf['referee_sat'] ?? '' ],
            8 => [ 'Kampfrichter Sonntag:', $kopf['referee_sun'] ?? '' ],
        ];
        foreach ( $felder as $r => [ $label, $wert ] ) {
            $rows[] = "<row r=\"{$r}\">" . $text( "A{$r}", $label, 1 ) . $text( "C{$r}", $wert, 2 ) . '</row>';
        }
        $kopfzeile = [ 'Nr.', 'Name', 'Vorname', 'Jahrgang', 'Geschlecht', 'AK', 'Gewichtsklasse' ];
        $zellen = '';
        foreach ( $kopfzeile as $i => $t ) {
            $zellen .= $text( chr( 65 + $i ) . '10', $t, 3 );
        }
        $rows[] = '<row r="10">' . $zellen . '</row>';

        $anzahl = max( self::ROWS_MIN, count( $zeilen ) );
        for ( $i = 0; $i < $anzahl; $i++ ) {
            $r = 11 + $i;
            $z = $zeilen[ $i ] ?? null;
            $zellen = $zahl( "A{$r}", $i + 1, 4 );
            if ( $z ) {
                [ $name, $vorname, $jg, $sex, $ak, $gew ] = self::row_values( $z );
                $zellen .= $text( "B{$r}", $name, 4 ) . $text( "C{$r}", $vorname, 4 ) . $zahl( "D{$r}", $jg, 4 )
                    . $text( "E{$r}", $sex, 4 ) . $text( "F{$r}", $ak, 4 ) . $text( "G{$r}", $gew, 4 );
            } else {
                foreach ( [ 'B', 'C', 'D', 'E', 'F', 'G' ] as $sp ) {
                    $zellen .= "<c r=\"{$sp}{$r}\" s=\"4\"/>";
                }
            }
            $rows[] = "<row r=\"{$r}\">{$zellen}</row>";
        }
        $ende   = 10 + $anzahl;
        $merges = '';
        foreach ( range( 2, 8 ) as $r ) {
            $merges .= "<mergeCell ref=\"A{$r}:B{$r}\"/><mergeCell ref=\"C{$r}:G{$r}\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . "<dimension ref=\"A1:G{$ende}\"/>"
            . '<cols><col min="1" max="1" width="4.3" customWidth="1"/><col min="2" max="3" width="20.14" customWidth="1"/>'
            . '<col min="4" max="5" width="10.14" customWidth="1"/><col min="6" max="6" width="7.43" customWidth="1"/>'
            . '<col min="7" max="7" width="15" customWidth="1"/></cols>'
            . '<sheetData>' . implode( '', $rows ) . '</sheetData>'
            . '<mergeCells count="14">' . $merges . '</mergeCells>'
            . '<pageSetup paperSize="9" orientation="portrait"/>'
            . '</worksheet>';
    }

    private static function styles(): string {
        // 0 normal · 1 Beschriftung fett · 2 Wert mit Linie unten · 3 Tabellenkopf fett mit Rahmen · 4 Zelle mit Rahmen
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill></fills>'
            . '<borders count="3"><border/>'
            . '<border><bottom style="thin"><color auto="1"/></bottom></border>'
            . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="5"><xf/>'
            . '<xf fontId="1" applyFont="1"/>'
            . '<xf borderId="1" applyBorder="1"/>'
            . '<xf fontId="1" fillId="2" borderId="2" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf borderId="2" applyBorder="1"/>'
            . '</cellXfs></styleSheet>';
    }

    private static function content_types(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Meldung" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbook_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }
}
