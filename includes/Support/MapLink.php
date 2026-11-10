<?php

namespace VereinsPlugin\Support;

/**
 * Ortsangabe als Link ins Kartenprogramm, mit dem Ort als Ziel.
 *
 * Zwei Adressen, weil es kein gemeinsames Format gibt, das überall die
 * Karten-App öffnet:
 *
 *   google()  öffnet auf Android Google Maps, sonst Google Maps im Browser
 *             – der Link, der im HTML steht und immer funktioniert.
 *   apple()   öffnet auf iPhone, iPad und Mac die Karten-App. Das Skript der
 *             Terminliste (tile.js) tauscht den Link auf Apple-Geräten aus.
 *
 * Beide starten die Routenplanung mit dem Ort als Ziel; der Start ist der
 * eigene Standort. Wie genau das Ziel getroffen wird, hängt an der Angabe
 * am Termin – „Stadtsporthalle, Potsdamer Str. 51, Ludwigsfelde“ findet
 * jedes Kartenprogramm, „Halle“ allein nicht.
 */
class MapLink {

    public static function google( string $ort ): string {
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( self::clean( $ort ) );
    }

    public static function apple( string $ort ): string {
        return 'https://maps.apple.com/?daddr=' . rawurlencode( self::clean( $ort ) );
    }

    private static function clean( string $ort ): string {
        return trim( preg_replace( '/\s+/u', ' ', strip_tags( $ort ) ) ?? '' );
    }
}
