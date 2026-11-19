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

    /** Namen vergleichbar machen: Groß/klein und Leerzeichen zählen nicht. */
    public static function normalize( string $name ): string {
        return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $name ) ) );
    }

    /**
     * Ist das der eigene Name der Person? Der gehört nie in die Liste: Man
     * ist bei Terminen über „Ich“ dabei und meldet sich bei Wettkämpfen über
     * „Mich selbst melden“ – als gemerkter Name tauchte man sonst als seine
     * eigene Begleitung auf.
     */
    public static function is_own_name( int $person_id, string $name ): bool {
        if ( ! $person_id ) {
            return false;
        }
        $eigen = self::normalize( (string) Person::name( $person_id ) );
        return $eigen !== '' && $eigen === self::normalize( $name );
    }

    /**
     * Einmalig beim Update (1.22.0): eigene Namen aus den Listen nehmen.
     * Wo eine solche „Begleitperson“ schon an Terminen hing, fällt die
     * Begleitung weg (man ist ja selbst über die eigene Zusage dabei);
     * Mitbringposten für sie zählen danach als eigene.
     *
     * @return int Zahl entfernter Namen
     */
    public static function remove_own_names(): int {
        global $wpdb;
        $t      = self::table();
        $zeilen = $wpdb->get_results( "SELECT id, person_id, name FROM {$t}", ARRAY_A ) ?: [];
        $weg    = 0;
        $leise  = $wpdb->suppress_errors( true );
        foreach ( $zeilen as $z ) {
            if ( ! self::is_own_name( (int) $z['person_id'], (string) $z['name'] ) ) {
                continue;
            }
            $id = (int) $z['id'];
            $wpdb->delete( $wpdb->prefix . 'vp_event_companions', [ 'companion_id' => $id ] );
            foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}vp_event_item_claims WHERE companion_id = %d", $id ) ) ?: [] as $claim ) {
                // Gibt es denselben Posten schon als eigenen, bleibt der.
                if ( $wpdb->update( $wpdb->prefix . 'vp_event_item_claims', [ 'companion_id' => 0 ], [ 'id' => (int) $claim ] ) === false ) {
                    $wpdb->delete( $wpdb->prefix . 'vp_event_item_claims', [ 'id' => (int) $claim ] );
                }
            }
            $wpdb->delete( $t, [ 'id' => $id ] );
            $weg++;
        }
        $wpdb->suppress_errors( $leise );
        return $weg;
    }

    /** Einen gemerkten Namen vergessen – bei Terminen und Meldungen gleichermaßen. */
    public static function forget( int $person_id, string $name ): void {
        global $wpdb;
        if ( $person_id <= 0 ) {
            return;
        }
        $wpdb->delete( self::table(), [ 'person_id' => $person_id, 'name' => trim( (string) $name ) ] );
    }

    /** Namen merken, sofern noch nicht vorhanden. Nur für Mitglieder (ID > 0). */
    public static function remember( int $person_id, string $name ): void {
        global $wpdb;
        $name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 100 );
        if ( $person_id <= 0 || $name === '' || self::is_own_name( $person_id, $name ) ) {
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
