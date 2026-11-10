<?php

namespace VereinsPlugin\Support;

/**
 * Beschreibung eines Termins mit Links.
 *
 * Zwei Schreibweisen, beide ohne Editor:
 *
 *   https://example.org/ausschreibung.pdf      wird zum Link (auch www.…)
 *   [Ausschreibung](https://example.org/…)     Link mit eigenem Text
 *
 * Alles andere bleibt Text: Er wird vollständig maskiert, HTML aus dem Feld
 * kommt also nie ungefiltert auf die Seite. Zeilenumbrüche bleiben erhalten.
 * Als Ziel zählen nur http- und https-Adressen.
 */
class RichText {

    /** Erkennt [Text](Adresse), eine nackte Adresse oder www.… */
    private const MUSTER = '~\[([^\]\r\n]{1,200})\]\(\s*((?:https?://|www\.)[^\s)]+)\s*\)|((?:https?://|www\.)[^\s<>"]+)~iu';

    /** Längste angezeigte Adresse, wenn der Link keinen eigenen Text hat. */
    private const MAX_ANZEIGE = 45;

    /**
     * Für die Terminkachel: maskierter Text mit <a>-Links und <br>.
     */
    public static function html( string $text ): string {
        $text = trim( str_replace( "\r\n", "\n", $text ) );
        if ( $text === '' ) {
            return '';
        }

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
                $out .= self::link( $url, self::kurz( $url ) ) . self::text( $nach );
            } else {
                $out .= self::link( $m[2][0], $m[1][0] );
            }
            $rest = $pos + strlen( $ganz );
        }

        $out .= self::text( substr( $text, $rest ) );
        return $out;
    }

    /**
     * Für Banner und Flyer: nur Text. Aus [Text](Adresse) wird der Text;
     * nackte Adressen bleiben stehen, wenn $adressen gesetzt ist (Flyer zum
     * Abtippen), sonst fallen sie weg (Banner – der ist selbst ein Link).
     */
    public static function plain( string $text, bool $adressen = false ): string {
        $text = preg_replace_callback(
            self::MUSTER,
            static function ( array $m ) use ( $adressen ): string {
                if ( isset( $m[3] ) && $m[3] !== '' ) {
                    [ $url, $nach ] = self::abschneiden( $m[3] );
                    return ( $adressen ? $url : '' ) . $nach;
                }
                return $m[1];
            },
            $text
        ) ?? $text;
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
