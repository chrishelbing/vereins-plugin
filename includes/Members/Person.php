<?php

namespace VereinsPlugin\Members;

/**
 * Auflösung von Personen-IDs zu Namen und Kontaktdaten.
 *
 * Hinter einer Personen-ID stecken zwei Sorten Menschen:
 *
 *   positiv → wp_users-ID eines aufgenommenen Mitglieds
 *   negativ → vp_people-Eintrag: Gast aus einem Terminlink oder eine
 *             Registrierung, die noch auf Freigabe wartet
 *
 * Diese Klasse ist die einzige Stelle, die den Unterschied kennt. Alles
 * andere – Zusagen, Mitbringliste, Helferaufgaben, Begleitpersonen – rechnet
 * schlicht mit einer Personen-ID weiter.
 *
 * Warum das Vorzeichen und keine zweite Spalte: Eine Person wird bei der
 * Aufnahme in den Verein zum Mitglied. Mit einer einzigen Spalte ist das ein
 * Umschreiben der ID, mit zwei Spalten wären es fünf Tabellen samt Indizes.
 */
class Person {

    /** Sammelt bereits aufgelöste Namen für die Dauer eines Aufrufs. */
    private static array $cache = [];

    public static function is_guest( int $person_id ): bool {
        return $person_id < 0;
    }

    /** vp_people.id aus einer Personen-ID. 0, wenn es ein Mitglied ist. */
    public static function guest_id( int $person_id ): int {
        return $person_id < 0 ? -$person_id : 0;
    }

    /** Personen-ID zu einem vp_people-Eintrag. */
    public static function from_guest( int $guest_id ): int {
        return -abs( $guest_id );
    }

    /**
     * Anzeigename einer einzelnen Person.
     */
    public static function name( int $person_id ): string {
        return self::names( [ $person_id ] )[ $person_id ]
            ?? __( '(entfernte Person)', 'vereins-plugin' );
    }

    /**
     * Namen für mehrere Personen auf einen Schlag.
     *
     * Listen zeigen schnell dreißig Namen; einzeln nachzuschlagen ergäbe
     * dreißig Abfragen. Deshalb je Sorte genau eine.
     *
     * @param int[] $person_ids
     * @return array<int,string> Personen-ID => Name
     */
    public static function names( array $person_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $person_ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }

        $offen = array_values( array_diff( $ids, array_keys( self::$cache ) ) );

        if ( $offen ) {
            $mitglieder = array_values( array_filter( $offen, fn( $id ) => $id > 0 ) );
            $gaeste     = array_map( [ self::class, 'guest_id' ], array_filter( $offen, fn( $id ) => $id < 0 ) );

            foreach ( self::user_names( $mitglieder ) as $id => $name ) {
                self::$cache[ $id ] = $name;
            }
            foreach ( self::guest_names( $gaeste ) as $gid => $name ) {
                self::$cache[ self::from_guest( $gid ) ] = $name;
            }

            // Was jetzt noch fehlt, gibt es nicht mehr.
            foreach ( $offen as $id ) {
                if ( ! isset( self::$cache[ $id ] ) ) {
                    self::$cache[ $id ] = __( '(entfernte Person)', 'vereins-plugin' );
                }
            }
        }

        $result = [];
        foreach ( $ids as $id ) {
            $result[ $id ] = self::$cache[ $id ];
        }
        return $result;
    }

    /**
     * Wessen Kontaktangabe noch nicht bestätigt ist.
     *
     * Betrifft nur offene Kontakte aus `vp_people` – Gäste über einen
     * Terminlink, deren Angabe niemand geprüft hat. In den Listen erscheinen
     * sie als „unbestätigt“ (Ausrufezeichen), ihre Begleitpersonen ebenso.
     *
     * Mitglieder nie: Sie hat ein Administrator angelegt oder aufgenommen,
     * sie haben ein Konto und melden sich an. Früher wurden auch sie
     * markiert, solange `vp_members.confirmed_at` leer war – das wurde aber
     * nur über den Bestätigungslink für Gäste gesetzt, den ein angelegtes
     * Mitglied nie bekommt. Ergebnis: Fast jedes Mitglied samt Kindern stand
     * mit Ausrufezeichen in der Teilnehmerliste.
     *
     * @param int[] $person_ids
     * @return array<int,bool> Personen-ID => true
     */
    public static function unconfirmed_map( array $person_ids ): array {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $person_ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }

        $map = [];

        $gaeste = array_map( [ self::class, 'guest_id' ], array_filter( $ids, fn( $id ) => $id < 0 ) );
        if ( $gaeste ) {
            $in   = implode( ',', $gaeste );
            $rows = $wpdb->get_col(
                "SELECT id FROM {$wpdb->prefix}vp_people
                 WHERE id IN ({$in}) AND confirmed_at IS NULL"
            );
            foreach ( $rows ?: [] as $gid ) {
                $map[ self::from_guest( (int) $gid ) ] = true;
            }
        }

        return $map;
    }

    /**
     * Vollständiger Datensatz einer Person – für Freigabe und Verwaltung.
     *
     * @return array{id:int, name:string, email:string, phone:string, source:string, confirmed:bool}|null
     */
    public static function find( int $person_id ): ?array {
        if ( self::is_guest( $person_id ) ) {
            $row = self::guest_row( self::guest_id( $person_id ) );
            if ( ! $row ) {
                return null;
            }
            return [
                'id'        => $person_id,
                'name'      => (string) $row['display_name'],
                'email'     => (string) ( $row['email'] ?? '' ),
                'phone'     => (string) ( $row['phone'] ?? '' ),
                'source'    => (string) $row['source'],
                'confirmed' => ! empty( $row['confirmed_at'] ),
            ];
        }

        $user = get_userdata( $person_id );
        if ( ! $user ) {
            return null;
        }

        $member = ( new MemberModel() )->find_by_user_id( $person_id );

        return [
            'id'        => $person_id,
            'name'      => $user->display_name,
            'email'     => $user->user_email,
            'phone'     => (string) ( $member['phone'] ?? '' ),
            'source'    => 'member',
            // Aufgenommene Mitglieder gelten als bestätigt, siehe unconfirmed_map().
            'confirmed' => true,
        ];
    }

    /** Nach einer Freigabe oder Löschung stimmt der Zwischenspeicher nicht mehr. */
    public static function flush_cache(): void {
        self::$cache = [];
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /** @return array<int,string> */
    private static function user_names( array $user_ids ): array {
        if ( ! $user_ids ) {
            return [];
        }

        $namen = [];
        // get_users() mit include füllt den Objekt-Cache in einem Rutsch.
        foreach ( get_users( [ 'include' => $user_ids, 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
            $namen[ (int) $user->ID ] = (string) $user->display_name;
        }
        return $namen;
    }

    /** @return array<int,string> */
    private static function guest_names( array $guest_ids ): array {
        global $wpdb;

        if ( ! $guest_ids ) {
            return [];
        }

        $in   = implode( ',', array_map( 'intval', $guest_ids ) );
        $rows = $wpdb->get_results(
            "SELECT id, display_name FROM {$wpdb->prefix}vp_people WHERE id IN ({$in})",
            ARRAY_A
        );

        $namen = [];
        foreach ( $rows ?: [] as $row ) {
            $namen[ (int) $row['id'] ] = (string) $row['display_name'];
        }
        return $namen;
    }

    private static function guest_row( int $guest_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}vp_people WHERE id = %d", $guest_id ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
