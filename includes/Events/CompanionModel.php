<?php

namespace VereinsPlugin\Events;

/**
 * Begleitpersonen: die persönliche Vorschlagsliste eines Mitglieds
 * (vp_member_companions) und die Zuordnung zu einer Zusage
 * (vp_event_companions).
 *
 * Der Name wird bei der Zusage mitgespeichert. Räumt jemand später seine
 * Liste auf, bleiben vergangene Zusagen dadurch lesbar.
 */
class CompanionModel {

    private string $saved;
    private string $assigned;

    public function __construct() {
        global $wpdb;
        $this->saved    = $wpdb->prefix . 'vp_member_companions';
        $this->assigned = $wpdb->prefix . 'vp_event_companions';
    }

    // ------------------------------------------------------------------
    // Persönliche Liste
    // ------------------------------------------------------------------

    /**
     * Gespeicherte Personen eines Mitglieds.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function saved_for_user( int $person_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name FROM {$this->saved} WHERE person_id = %d ORDER BY name ASC",
                $person_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map( fn( $r ) => [ 'id' => (int) $r['id'], 'name' => $r['name'] ], $rows );
    }

    /**
     * Person in die persönliche Liste aufnehmen – oder die vorhandene
     * zurückgeben, wenn der Name schon existiert.
     *
     * @return int  ID der gespeicherten Person, 0 bei leerem Namen
     */
    public function remember( int $person_id, string $name ): int {
        global $wpdb;

        $name = trim( sanitize_text_field( $name ) );
        if ( $name === '' ) {
            return 0;
        }
        $name = mb_substr( $name, 0, 100 );

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->saved} WHERE person_id = %d AND name = %s",
                $person_id, $name
            )
        );

        if ( $existing ) {
            return (int) $existing;
        }

        $wpdb->insert(
            $this->saved,
            [ 'person_id' => $person_id, 'name' => $name, 'created_at' => current_time( 'mysql' ) ],
            [ '%d', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Person aus der persönlichen Liste entfernen. Bereits gespeicherte
     * Zusagen bleiben unverändert, weil dort der Name mitgeführt wird.
     */
    public function forget( int $person_id, int $companion_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $this->saved,
            [ 'id' => $companion_id, 'person_id' => $person_id ],
            [ '%d', '%d' ]
        );
    }

    public function find_saved( int $person_id, int $companion_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name FROM {$this->saved} WHERE id = %d AND person_id = %d",
                $companion_id, $person_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // Zuordnung zur Zusage
    // ------------------------------------------------------------------

    /**
     * Begleitpersonen eines Mitglieds für eine Termininstanz.
     *
     * @return array<int, array{companion_id:int, name:string}>
     */
    public function for_attendance( int $event_id, string $instance_date, int $person_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT companion_id, name FROM {$this->assigned}
                 WHERE event_id = %d AND instance_date = %s AND person_id = %d
                 ORDER BY name ASC",
                $event_id, $instance_date, $person_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map(
            fn( $r ) => [ 'companion_id' => (int) $r['companion_id'], 'name' => $r['name'] ],
            $rows
        );
    }

    /**
     * Alle Begleitpersonen einer Instanz, nach Mitglied gruppiert.
     *
     * @return array<int, array<int, string>>  person_id => [Namen]
     */
    public function for_instance( int $event_id, string $instance_date ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT person_id, name FROM {$this->assigned}
                 WHERE event_id = %d AND instance_date = %s
                 ORDER BY name ASC",
                $event_id, $instance_date
            ),
            ARRAY_A
        ) ?: [];

        $grouped = [];
        foreach ( $rows as $row ) {
            $grouped[ (int) $row['person_id'] ][] = $row['name'];
        }
        return $grouped;
    }

    /**
     * Begleitpersonen einer Zusage komplett ersetzen.
     *
     * Eingabe sind IDs aus der persönlichen Liste und/oder frei getippte
     * Namen; neue Namen wandern automatisch in die Liste, damit sie beim
     * nächsten Mal nur noch angeklickt werden müssen.
     *
     * @param  int[]    $companion_ids
     * @param  string[] $new_names
     * @return array<int, array{companion_id:int, name:string}>  gespeicherter Stand
     */
    public function set_for_attendance(
        int $event_id,
        string $instance_date,
        int $person_id,
        array $companion_ids = [],
        array $new_names = []
    ): array {
        global $wpdb;

        $resolved = [];

        foreach ( $companion_ids as $companion_id ) {
            $saved = $this->find_saved( $person_id, (int) $companion_id );
            if ( $saved ) {
                $resolved[ (int) $saved['id'] ] = $saved['name'];
            }
        }

        foreach ( $new_names as $name ) {
            $id = $this->remember( $person_id, $name );
            if ( $id ) {
                $saved = $this->find_saved( $person_id, $id );
                if ( $saved ) {
                    $resolved[ $id ] = $saved['name'];
                }
            }
        }

        // Alten Stand verwerfen und neu schreiben – einfacher und sicherer
        // als ein Abgleich, die Mengen sind winzig.
        $this->clear_for_attendance( $event_id, $instance_date, $person_id );

        foreach ( $resolved as $companion_id => $name ) {
            $wpdb->insert(
                $this->assigned,
                [
                    'event_id'      => $event_id,
                    'instance_date' => $instance_date,
                    'person_id'    => $person_id,
                    'companion_id'  => $companion_id,
                    'name'          => $name,
                ],
                [ '%d', '%s', '%d', '%d', '%s' ]
            );
        }

        return array_map(
            fn( $id, $name ) => [ 'companion_id' => (int) $id, 'name' => $name ],
            array_keys( $resolved ),
            $resolved
        );
    }

    public function clear_for_attendance( int $event_id, string $instance_date, int $person_id ): void {
        global $wpdb;
        $wpdb->delete(
            $this->assigned,
            [ 'event_id' => $event_id, 'instance_date' => $instance_date, 'person_id' => $person_id ],
            [ '%d', '%s', '%d' ]
        );
    }

    /**
     * Anzahl der Begleitpersonen je Instanz – für die Personenzählung.
     *
     * @param  array<int, array{event_id:int, instance_date:string}> $instances
     * @return array<string, int>  Key: "{event_id}|{instance_date}"
     */
    public function counts_for_instances( array $instances ): array {
        global $wpdb;

        if ( empty( $instances ) ) {
            return [];
        }

        $clauses = [];
        $params  = [];
        foreach ( $instances as $inst ) {
            $clauses[] = '(event_id = %d AND instance_date = %s)';
            $params[]  = (int) $inst['event_id'];
            $params[]  = $inst['instance_date'];
        }

        $sql = "SELECT event_id, instance_date, COUNT(*) AS cnt
                FROM {$this->assigned}
                WHERE " . implode( ' OR ', $clauses ) . '
                GROUP BY event_id, instance_date';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['event_id'] . '|' . $row['instance_date'] ] = (int) $row['cnt'];
        }
        return $result;
    }

    public function count_for_event( int $event_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$this->assigned} WHERE event_id = %d", $event_id )
        );
    }

    public function delete_for_event( int $event_id ): void {
        global $wpdb;
        $wpdb->delete( $this->assigned, [ 'event_id' => $event_id ], [ '%d' ] );
    }
}
