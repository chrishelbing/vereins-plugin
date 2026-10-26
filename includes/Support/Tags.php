<?php

namespace VereinsPlugin\Support;

/**
 * Tags an Terminen, z. B. Altersklassen (U9, U11, … Veteranen).
 *
 * Welche Tags es gibt, legt der Termintyp fest; am Termin wird daraus nur
 * ausgewählt. Gespeichert wird beides als JSON-Liste in einer TEXT-Spalte
 * (vp_event_types.tags, vp_events.tags) – für eine Handvoll Kürzel lohnt
 * keine eigene Tabelle, und gefiltert wird bisher nirgends danach.
 */
class Tags {

    /** Längstes zulässiges Tag – Kürzel, keine Sätze. */
    public const MAX_LEN = 30;

    /** Höchstzahl je Termintyp, damit die Auswahl überschaubar bleibt. */
    public const MAX_COUNT = 30;

    /**
     * Freitext aus dem Typ-Formular zur Liste: getrennt durch Komma,
     * Semikolon oder Zeilenumbruch, ohne Leerraum und Doppelte (ohne
     * Rücksicht auf Groß-/Kleinschreibung). Die Reihenfolge der Eingabe
     * bleibt – sie ist auch die Reihenfolge auf der Kachel.
     */
    public static function parse( string $input ): array {
        $out  = [];
        $seen = [];
        foreach ( preg_split( '/[,;\r\n]+/', $input ) ?: [] as $tag ) {
            $tag = self::clean( $tag );
            if ( $tag === '' || isset( $seen[ mb_strtolower( $tag ) ] ) ) {
                continue;
            }
            $seen[ mb_strtolower( $tag ) ] = true;
            $out[] = $tag;
            if ( count( $out ) >= self::MAX_COUNT ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Auswahl am Termin auf das beschränken, was der Typ vorgibt – in der
     * Reihenfolge des Typs, nicht der Auswahl.
     */
    public static function restrict( array $chosen, array $allowed ): array {
        $wanted = [];
        foreach ( $chosen as $tag ) {
            $wanted[ mb_strtolower( self::clean( (string) $tag ) ) ] = true;
        }
        return array_values( array_filter(
            $allowed,
            fn( $tag ) => isset( $wanted[ mb_strtolower( $tag ) ] )
        ) );
    }

    /** Liste → Spaltenwert. Leere Liste = NULL. */
    public static function encode( array $tags ): ?string {
        $tags = array_values( array_filter( array_map( [ self::class, 'clean' ], $tags ), 'strlen' ) );
        return $tags ? (string) json_encode( $tags, JSON_UNESCAPED_UNICODE ) : null;
    }

    /** Spaltenwert → Liste. Verträgt NULL, Leerstring und Unsinn. */
    public static function decode( $value ): array {
        if ( is_array( $value ) ) {
            return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
        }
        if ( ! is_string( $value ) || $value === '' ) {
            return [];
        }
        $list = json_decode( $value, true );
        return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) ) : [];
    }

    private static function clean( string $tag ): string {
        $tag = trim( strip_tags( $tag ) );
        $tag = preg_replace( '/\s+/u', ' ', $tag ) ?? '';
        return mb_substr( $tag, 0, self::MAX_LEN );
    }
}
