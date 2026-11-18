<?php

namespace VereinsPlugin\Members;

/**
 * Gemerkte Namen eines Mitglieds (vp_member_companions) – Basis.
 *
 * Familienmitglieder und Kinder, die ein Mitglied immer wieder einträgt:
 * bei Terminen als Begleitung, bei Wettkampfmeldungen als Starter. Gemerkt
 * wird nur der Name – kein Alter, kein Gewicht, nichts weiter.
 *
 * Das Modul Termine hat für Begleitpersonen zusätzlich eigene Methoden
 * (Events\CompanionModel); beide lesen und schreiben dieselbe Tabelle.
 */
class SavedNames {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'vp_member_companions';
    }

    /** @return string[] alphabetisch */
    public static function for_person( int $person_id ): array {
        global $wpdb;
        if ( $person_id <= 0 ) {
            return [];
        }
        $namen = $wpdb->get_col( $wpdb->prepare(
            'SELECT name FROM ' . self::table() . ' WHERE person_id = %d ORDER BY name ASC', $person_id
        ) ) ?: [];
        $namen = array_map( 'strval', $namen );
        sort( $namen, SORT_NATURAL | SORT_FLAG_CASE );
        return $namen;
    }

    /** Namen merken, sofern noch nicht vorhanden. Nur für Mitglieder (ID > 0). */
    public static function remember( int $person_id, string $name ): void {
        global $wpdb;
        $name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 100 );
        if ( $person_id <= 0 || $name === '' ) {
            return;
        }
        $da = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE person_id = %d AND name = %s', $person_id, $name
        ) );
        if ( ! $da ) {
            $wpdb->insert( self::table(), [ 'person_id' => $person_id, 'name' => $name, 'created_at' => current_time( 'mysql' ) ] );
        }
    }
}
