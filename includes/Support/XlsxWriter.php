<?php

namespace VereinsPlugin\Support;

/**
 * Schlichte Excel-Datei (.xlsx, Office Open XML) mit mehreren Tabellen –
 * ohne Bibliothek, nur ZipArchive.
 *
 * Gedacht zum Lesen auf dem Handy: je Tabelle oben eine Titelzeile, darunter
 * die fett hinterlegte Kopfzeile, die beim Scrollen stehen bleibt; Spalten
 * mit sinnvoller Breite, Text bricht um, Filter in der Kopfzeile.
 */
class XlsxWriter {

    /**
     * @param array<int, array{name:string, title?:string, header:string[], rows:array<int, array>, widths?:float[]}> $tabellen
     * @return string|null Inhalt der .xlsx; null, wenn ZipArchive fehlt
     */
    public static function build( array $tabellen ): ?string {
        if ( ! class_exists( '\ZipArchive' ) || ! $tabellen ) {
            return null;
        }
        $datei = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'liste.xlsx' ) : tempnam( sys_get_temp_dir(), 'vp' );
        $zip   = new \ZipArchive();
        if ( $zip->open( $datei, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            return null;
        }
        $namen = self::sheet_names( array_column( $tabellen, 'name' ) );
        $n     = count( $tabellen );

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb_sheets = '';
        $wb_rels   = '';
        $defined   = '';
        for ( $i = 1; $i <= $n; $i++ ) {
            $ct        .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb_sheets .= '<sheet name="' . self::x( $namen[ $i - 1 ] ) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $wb_rels   .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $t          = $tabellen[ $i - 1 ];
            $letzte     = 2 + count( $t['rows'] );
            if ( $t['rows'] ) {
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . ( $i - 1 ) . '" hidden="1">\'' . str_replace( "'", "''", $namen[ $i - 1 ] ) . '\'!$A$2:$' . self::col( count( $t['header'] ) ) . '$' . $letzte . '</definedName>';
            }
        }
        $ct .= '</Types>';
        $wb_rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString( '[Content_Types].xml', $ct );
        $zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>' );
        $zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wb_sheets . '</sheets>'
            . ( $defined ? '<definedNames>' . $defined . '</definedNames>' : '' )
            . '</workbook>' );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wb_rels . '</Relationships>' );
        $zip->addFromString( 'xl/styles.xml', self::styles() );
        foreach ( $tabellen as $i => $t ) {
            $zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', self::sheet( $t ) );
        }
        $zip->close();
        $inhalt = (string) file_get_contents( $datei );
        @unlink( $datei );
        return $inhalt;
    }

    /** Excel-taugliche, eindeutige Blattnamen (max. 31 Zeichen, ohne []:*?/\). */
    public static function sheet_names( array $namen ): array {
        $out = [];
        foreach ( $namen as $name ) {
            $n = trim( preg_replace( '/[\[\]:*?\/\\\\]/', ' ', (string) $name ) ) ?: 'Tabelle';
            $n = mb_substr( $n, 0, 31 );
            $basis = $n;
            $k = 2;
            while ( in_array( mb_strtolower( $n ), array_map( 'mb_strtolower', $out ), true ) ) {
                $n = mb_substr( $basis, 0, 28 ) . ' ' . $k++;
            }
            $out[] = $n;
        }
        return $out;
    }

    /** Spaltenbuchstabe: 1 → A, 27 → AA. */
    public static function col( int $n ): string {
        $s = '';
        while ( $n > 0 ) {
            $m = ( $n - 1 ) % 26;
            $s = chr( 65 + $m ) . $s;
            $n = intdiv( $n - 1, 26 );
        }
        return $s;
    }

    private static function x( $t ): string {
        // Steuerzeichen, die XML nicht erlaubt, fallen weg
        $t = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $t );
        return htmlspecialchars( $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    private static function cell( string $ref, $wert, int $stil ): string {
        if ( $wert === null || $wert === '' ) {
            return '<c r="' . $ref . '" s="' . $stil . '"/>';
        }
        if ( is_int( $wert ) || is_float( $wert ) ) {
            return '<c r="' . $ref . '" s="' . $stil . '"><v>' . $wert . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr" s="' . $stil . '"><is><t xml:space="preserve">' . self::x( $wert ) . '</t></is></c>';
    }

    private static function sheet( array $t ): string {
        $spalten = count( $t['header'] );
        $letzte  = self::col( $spalten );
        $breiten = $t['widths'] ?? [];
        $cols    = '';
        for ( $i = 1; $i <= $spalten; $i++ ) {
            $b     = (float) ( $breiten[ $i - 1 ] ?? 18 );
            $cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $b . '" customWidth="1"/>';
        }
        $rows = '<row r="1" ht="24" customHeight="1">' . self::cell( 'A1', $t['title'] ?? $t['name'], 2 ) . '</row>';
        $kopf = '<row r="2">';
        foreach ( array_values( $t['header'] ) as $i => $h ) {
            $kopf .= self::cell( self::col( $i + 1 ) . '2', $h, 1 );
        }
        $rows .= $kopf . '</row>';
        $r = 3;
        foreach ( $t['rows'] as $zeile ) {
            $rows .= '<row r="' . $r . '">';
            foreach ( array_values( $zeile ) as $i => $wert ) {
                $rows .= self::cell( self::col( $i + 1 ) . $r, $wert, 3 );
            }
            $rows .= '</row>';
            $r++;
        }
        if ( ! $t['rows'] ) {
            $rows .= '<row r="3">' . self::cell( 'A3', $t['empty'] ?? '–', 4 ) . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $rows . '</sheetData>'
            . ( $t['rows'] ? '<autoFilter ref="A2:' . $letzte . ( 2 + count( $t['rows'] ) ) . '"/>' : '' )
            . '<pageSetup orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
    }

    /** 0 Standard, 1 Kopfzeile (fett, grau, Rahmen), 2 Titel (fett, groß), 3 Zelle (umbrechend, Rahmen), 4 Hinweis (grau). */
    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><i/><sz val="11"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
