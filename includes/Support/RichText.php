<?php

namespace VereinsPlugin\Support;

/**
 * Beschreibung eines Termins: Links und einfache Typografie, ohne Editor.
 * Eine kleine Teilmenge von Markdown – man braucht kein Menü, nur die
 * Schreibweise:
 *
 *   **fett**                                   fett
 *   *kursiv* oder _kursiv_                     kursiv
 *   - Punkt  (auch * oder •)                   Aufzählung, eine Zeile je Punkt
 *   1. Punkt                                   nummerierte Liste
 *   https://example.org/ausschreibung.pdf      wird zum Link (auch www.…)
 *   [Ausschreibung](https://example.org/…)     Link mit eigenem Text
 *
 * Alles andere bleibt Text: Er wird vollständig maskiert, HTML aus dem Feld
 * kommt also nie ungefiltert auf die Seite. Zeilenumbrüche bleiben erhalten.
 * Als Ziel zählen nur http- und https-Adressen.
 *
 * Sternchen mitten im Wort bleiben stehen – „Sportler*innen“ wird nicht
 * kursiv, ebenso wenig ein Unterstrich in einer Adresse oder in snake_case.
 */
class RichText {

    /** Erkennt [Text](Adresse), eine nackte Adresse oder www.… */
    private const MUSTER = '~\[([^\]\r\n]{1,200})\]\(\s*((?:https?://|www\.)[^\s)]+)\s*\)|((?:https?://|www\.)[^\s<>"]+)~iu';

    /** Längste angezeigte Adresse, wenn der Link keinen eigenen Text hat. */
    private const MAX_ANZEIGE = 45;

    /**
     * Die Schreibweisen, wie sie in der Hilfe am Feld und unter
     * Verein → Einstellungen erklärt werden. Eine Quelle für beide, damit
     * die Erklärung nicht hinter dem Code zurückbleibt; die Einstellungen
     * zeigen daneben, was RichText::html() tatsächlich daraus macht.
     *
     * @return array<int, array{0:string,1:string}> [ Eingabe, Bedeutung ]
     */
    public static function syntax(): array {
        return [
            [ '**fett**',                       __( 'fett', 'vereins-plugin' ) ],
            [ '*kursiv*',                       __( 'kursiv (auch _kursiv_)', 'vereins-plugin' ) ],
            [ "- Sportschuhe\n- Getränk",       __( 'Aufzählung – je Punkt eine Zeile, beginnend mit „- “', 'vereins-plugin' ) ],
            [ "1. Ankommen\n2. Umziehen",       __( 'nummerierte Liste', 'vereins-plugin' ) ],
            [ 'https://www.bjv-online.de',      __( 'Link – Adresse einfach einfügen', 'vereins-plugin' ) ],
            [ '[Ausschreibung](https://…)',     __( 'Link mit eigenem Text', 'vereins-plugin' ) ],
        ];
    }

    /** Aufzählungspunkt: „- “, „* “ oder „• “ am Zeilenanfang. */
    private const PUNKT = '/^\s*[-*\x{2022}]\s+(.*)$/u';

    /** Nummerierter Punkt: „1. “ oder „1) “ am Zeilenanfang. */
    private const NUMMER = '/^\s*\d{1,3}[.)]\s+(.*)$/u';

    /**
     * Für die Terminkachel: maskierter Text mit Links, Hervorhebungen,
     * Listen und <br>.
     */
    public static function html( string $text ): string {
        $text = trim( str_replace( "\r\n", "\n", $text ) );
        if ( $text === '' ) {
            return '';
        }

        // Zeilen zu Blöcken bündeln: aufeinanderfolgende Listenpunkte
        // gleicher Art werden eine Liste, alles andere bleibt Text mit <br>.
        $out   = '';
        $zeilen = [];   // laufender Textblock
        $liste  = null; // [ 'ul'|'ol', [ Punkte ] ]

        $text_raus = static function () use ( &$out, &$zeilen ): void {
            if ( $zeilen ) {
                $out   .= implode( "<br>\n", array_map( [ self::class, 'zeile' ], $zeilen ) );
                $zeilen = [];
            }
        };
        $liste_raus = static function () use ( &$out, &$liste ): void {
            if ( $liste ) {
                [ $tag, $punkte ] = $liste;
                $out  .= '<' . $tag . '>' . implode( '', array_map( fn( $p ) => '<li>' . self::zeile( $p ) . '</li>', $punkte ) ) . '</' . $tag . '>';
                $liste = null;
            }
        };

        foreach ( explode( "\n", $text ) as $zeile ) {
            $art = null;
            if ( preg_match( self::PUNKT, $zeile, $m ) ) {
                $art = 'ul';
            } elseif ( preg_match( self::NUMMER, $zeile, $m ) ) {
                $art = 'ol';
            }

            if ( $art ) {
                $text_raus();
                if ( $liste && $liste[0] !== $art ) {
                    $liste_raus();
                }
                $liste    = $liste ?: [ $art, [] ];
                $liste[1][] = $m[1];
                continue;
            }

            $liste_raus();
            $zeilen[] = $zeile;
        }
        $text_raus();
        $liste_raus();

        return $out;
    }

    /**
     * Eine Zeile: Links erkennen, Rest maskieren, dann **fett** und *kursiv*.
     * Links werden vorher durch Platzhalter ersetzt, damit ein Unterstrich
     * oder Sternchen in einer Adresse keine Hervorhebung auslöst.
     */
    private static function zeile( string $text ): string {
        $links = [];
        $platz = static function ( string $html ) use ( &$links ): string {
            $links[] = $html;
            return "\x01" . ( count( $links ) - 1 ) . "\x01";
        };

        $out  = '';
        $rest = 0;
        preg_match_all( self::MUSTER, $text, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

        foreach ( $treffer as $m ) {
            [ $ganz, $pos ] = $m[0];
            $out .= self::text( substr( $text, $rest, $pos - $rest ) );

            if ( isset( $m[3] ) && $m[3][1] !== -1 ) {
                // Nackte Adresse: Satzzeichen am Ende gehören zum Satz, nicht
                // zur Adresse („siehe https://… .“) – ebenso eine schließende
                // Klammer, zu der in der Adresse keine öffnende steht.
                [ $url, $nach ] = self::abschneiden( $m[3][0] );
                $out .= $platz( self::link( $url, self::kurz( $url ) ) ) . self::text( $nach );
            } else {
                $out .= $platz( self::link( $m[2][0], $m[1][0] ) );
            }
            $rest = $pos + strlen( $ganz );
        }
        $out .= self::text( substr( $text, $rest ) );

        $out = self::betonen( $out, '<strong>$1</strong>', '<em>$1</em>' );

        return preg_replace_callback( "/\x01(\d+)\x01/", fn( $p ) => $links[ (int) $p[1] ], $out ) ?? $out;
    }

    /**
     * **fett**, *kursiv*, _kursiv_. Ein Marker öffnet nur, wenn davor kein
     * Buchstabe steht, und schließt nur, wenn danach keiner folgt – so
     * bleiben „Sportler*innen“ und „snake_case“ unberührt.
     */
    private static function betonen( string $s, string $fett, string $kursiv ): string {
        $s = preg_replace( '/(?<![\p{L}\p{N}*])\*\*(?=\S)(.+?)(?<=\S)\*\*(?![\p{L}\p{N}*])/u', $fett, $s ) ?? $s;
        $s = preg_replace( '/(?<![\p{L}\p{N}*])\*(?=\S)(.+?)(?<=\S)\*(?![\p{L}\p{N}*])/u', $kursiv, $s ) ?? $s;
        $s = preg_replace( '/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', $kursiv, $s ) ?? $s;
        return $s;
    }

    /**
     * Für Banner und Flyer: nur Text. Aus [Text](Adresse) wird der Text;
     * nackte Adressen bleiben stehen, wenn $adressen gesetzt ist (Flyer zum
     * Abtippen), sonst fallen sie weg (Banner – der ist selbst ein Link).
     */
    public static function plain( string $text, bool $adressen = false ): string {
        // Adressen, die stehen bleiben, vorübergehend durch Platzhalter
        // ersetzen – sonst nähme die Hervorhebung unten einen Unterstrich
        // in der Adresse für „kursiv“ und striche ihn weg.
        $behalten = [];
        $text = preg_replace_callback(
            self::MUSTER,
            static function ( array $m ) use ( $adressen, &$behalten ): string {
                if ( isset( $m[3] ) && $m[3] !== '' ) {
                    [ $url, $nach ] = self::abschneiden( $m[3] );
                    if ( ! $adressen ) {
                        return $nach;
                    }
                    $behalten[] = $url;
                    return "\x01" . ( count( $behalten ) - 1 ) . "\x01" . $nach;
                }
                return $m[1];
            },
            $text
        ) ?? $text;

        // Listenpunkte als „• “, Hervorhebungen ohne Sternchen.
        $text = implode( "\n", array_map( static function ( string $z ): string {
            if ( preg_match( self::PUNKT, $z, $m ) || preg_match( self::NUMMER, $z, $m ) ) {
                return '• ' . $m[1];
            }
            return $z;
        }, explode( "\n", str_replace( "\r\n", "\n", $text ) ) ) );
        $text = self::betonen( $text, '$1', '$1' );

        $text = preg_replace_callback( "/\x01(\d+)\x01/", fn( $p ) => $behalten[ (int) $p[1] ], $text ) ?? $text;

        return trim( preg_replace( '/[ \t]+/', ' ', $text ) ?? $text );
    }

    // ------------------------------------------------------------------

    private static function text( string $s ): string {
        return nl2br( htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ), false );
    }

    private static function link( string $url, string $label ): string {
        if ( stripos( $url, 'www.' ) === 0 ) {
            $url = 'https://' . $url;
        }
        if ( ! preg_match( '~^https?://~i', $url ) ) {
            return self::text( $label );
        }
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ),
            htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
        );
    }

    /** @return array{0:string,1:string} Adresse und abgeschnittener Rest. */
    private static function abschneiden( string $url ): array {
        $nach = '';
        while ( $url !== '' ) {
            $letztes = substr( $url, -1 );
            $offen   = substr_count( $url, '(' ) < substr_count( $url, ')' );
            if ( strpos( '.,;:!?"\'', $letztes ) !== false || ( $letztes === ')' && $offen ) ) {
                $nach = $letztes . $nach;
                $url  = substr( $url, 0, -1 );
                continue;
            }
            break;
        }
        return [ $url, $nach ];
    }

    /** Anzeige einer Adresse ohne eigenen Text: ohne https://, gekürzt. */
    private static function kurz( string $url ): string {
        $kurz = preg_replace( '~^(https?://)?(www\.)?~i', '', $url ) ?? $url;
        $kurz = rtrim( $kurz, '/' );
        return mb_strlen( $kurz ) > self::MAX_ANZEIGE ? mb_substr( $kurz, 0, self::MAX_ANZEIGE - 1 ) . '…' : $kurz;
    }
}
