<?php

namespace VereinsPlugin\Events;

use VereinsPlugin\Members\Person;

/**
 * DB-Operationen für Zu-/Absagen (vp_event_attendance).
 *
 * Eine Antwort gilt immer für eine konkrete Termininstanz:
 * bei Serien für den jeweiligen Tag, bei einmaligen Terminen
 * für das Startdatum des Termins.
 */
class AttendanceModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_event_attendance';
    }

    /**
     * Antwort speichern bzw. aktualisieren (ein Datensatz je Nutzer + Instanz).
     *
     * Eine Absage hebt einen zuvor gesetzten Helfer-Status automatisch auf –
     * wer nicht da ist, kann auch nicht mit anpacken.
     */
    public function save(
        int $event_id,
        string $instance_date,
        int $person_id,
        string $response,
        bool $helping = false,
        ?string $help_task = null
    ): bool {
        global $wpdb;

        if ( ! in_array( $response, [ 'yes', 'no' ], true ) ) {
            return false;
        }

        if ( $response === 'no' ) {
            // Wer selbst nicht kommt, hilft auch nicht. Begleitpersonen
            // bleiben aber bestehen – es kann durchaus sein, dass die Kinder
            // kommen und die Eltern nicht.
            $helping   = false;
            $help_task = null;
        }

        $payload = [
            'response'     => $response,
            'helping'      => $helping ? 1 : 0,
            'help_task'    => $helping ? ( $help_task ?: null ) : null,
            'responded_at' => current_time( 'mysql' ),
        ];
        $formats = [ '%s', '%d', '%s', '%s' ];

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s AND person_id = %d",
                $event_id, $instance_date, $person_id
            )
        );

        if ( $existing ) {
            return (bool) $wpdb->update( $this->table, $payload, [ 'id' => (int) $existing ], $formats, [ '%d' ] );
        }

        return (bool) $wpdb->insert(
            $this->table,
            array_merge( [
                'event_id'      => $event_id,
                'instance_date' => $instance_date,
                'person_id'    => $person_id,
            ], $payload ),
            array_merge( [ '%d', '%s', '%d' ], $formats )
        );
    }

    /**
     * Schreibt den Helferstatus, der sich aus übernommenen Helferaufgaben
     * ergibt. Wer eine Aufgabe übernimmt, sagt damit automatisch zu –
     * existiert noch keine Antwort, wird sie als Zusage angelegt.
     *
     * @param string[] $tasks  Bezeichnungen der übernommenen Aufgaben
     */
    public function sync_helper_status( int $event_id, string $instance_date, int $person_id, array $tasks ): void {
        global $wpdb;

        $helping = ! empty( $tasks );
        $label   = $helping ? implode( ', ', $tasks ) : null;

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s AND person_id = %d",
                $event_id, $instance_date, $person_id
            )
        );

        if ( $existing ) {
            $payload = [ 'helping' => $helping ? 1 : 0, 'help_task' => $label ];
            $formats = [ '%d', '%s' ];

            // Eine Aufgabe zu übernehmen bedeutet zusagen.
            if ( $helping ) {
                $payload['response'] = 'yes';
                $formats[]           = '%s';
            }

            $wpdb->update( $this->table, $payload, [ 'id' => (int) $existing ], $formats, [ '%d' ] );
            return;
        }

        if ( ! $helping ) {
            return;
        }

        $wpdb->insert(
            $this->table,
            [
                'event_id'      => $event_id,
                'instance_date' => $instance_date,
                'person_id'    => $person_id,
                'response'      => 'yes',
                'helping'       => 1,
                'help_task'     => $label,
                'responded_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Aktuelle Antwort eines Nutzers zu einer Instanz (oder null).
     *
     * @return array{response:string, helping:bool, help_task:?string}|null
     */
    public function own_entry( int $event_id, string $instance_date, int $person_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT response, helping, help_task FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s AND person_id = %d",
                $event_id, $instance_date, $person_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return [
            'response'  => $row['response'],
            'helping'   => (bool) $row['helping'],
            'help_task' => $row['help_task'],
        ];
    }

    /**
     * Antwort eines Nutzers zurückziehen.
     */
    public function remove( int $event_id, string $instance_date, int $person_id ): bool {
        global $wpdb;

        ( new CompanionModel() )->clear_for_attendance( $event_id, $instance_date, $person_id );

        return (bool) $wpdb->delete(
            $this->table,
            [ 'event_id' => $event_id, 'instance_date' => $instance_date, 'person_id' => $person_id ],
            [ '%d', '%s', '%d' ]
        );
    }

    /**
     * Alle Antworten zu einer Instanz, aufgeteilt nach ja/nein, inkl. Namen.
     *
     * @return array{yes: array<int, array{user_id:int,name:string}>, no: array<int, array{user_id:int,name:string}>}
     */
    public function for_instance( int $event_id, string $instance_date ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT person_id, response, helping, help_task FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s
                 ORDER BY responded_at ASC",
                $event_id, $instance_date
            ),
            ARRAY_A
        ) ?: [];

        return $this->group(
            $rows,
            ( new CompanionModel() )->for_instance( $event_id, $instance_date )
        );
    }

    /**
     * Antworten für mehrere Instanzen auf einmal laden (vermeidet N+1-Queries
     * in der Terminliste).
     *
     * @param  array<int, array{event_id:int, instance_date:string}> $instances
     * @return array<string, array{yes: array, no: array}>  Key: "{event_id}|{instance_date}"
     */
    public function for_instances( array $instances ): array {
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

        $sql = "SELECT event_id, instance_date, person_id, response
                FROM {$this->table}
                WHERE " . implode( ' OR ', $clauses ) . '
                ORDER BY responded_at ASC';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $grouped = [];
        foreach ( $rows as $row ) {
            $key = $row['event_id'] . '|' . $row['instance_date'];
            $grouped[ $key ][] = $row;
        }

        $result = [];
        foreach ( $grouped as $key => $group ) {
            $result[ $key ] = $this->group( $group );
        }
        return $result;
    }

    /**
     * Nur Zahlen + eigene Antwort für mehrere Instanzen – ohne Namen.
     * Wird für die Terminliste genutzt: dort sind die Namen bewusst nicht
     * enthalten, sie werden erst bei Bedarf über for_instance() nachgeladen.
     *
     * @param  array<int, array{event_id:int, instance_date:string}> $instances
     * @return array<string, array{yes:int, no:int, helpers:int, own:?string, own_helping:bool, own_task:?string}>
     */
    public function summary_for_instances( array $instances, int $user_id ): array {
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

        $sql = "SELECT event_id, instance_date, response, helping, help_task, person_id
                FROM {$this->table}
                WHERE " . implode( ' OR ', $clauses );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $key = $row['event_id'] . '|' . $row['instance_date'];

            if ( ! isset( $result[ $key ] ) ) {
                $result[ $key ] = self::empty_summary();
            }

            $result[ $key ][ $row['response'] ]++;

            if ( ! empty( $row['helping'] ) ) {
                $result[ $key ]['helpers']++;
            }

            // Personenzahl: das Mitglied selbst zählt nur bei einer Zusage.
            // Begleitpersonen kommen weiter unten dazu – sie hängen nicht an
            // der eigenen Antwort.
            if ( $row['response'] === 'yes' ) {
                $result[ $key ]['people']++;
            }

            if ( (int) $row['person_id'] === $user_id ) {
                $result[ $key ]['own']         = $row['response'];
                $result[ $key ]['own_helping'] = ! empty( $row['helping'] );
                $result[ $key ]['own_task']    = $row['help_task'];
            }
        }

        // Begleitpersonen aufaddieren – auch für Instanzen, zu denen es sonst
        // noch gar keine Antwort gibt.
        foreach ( ( new CompanionModel() )->counts_for_instances( $instances ) as $key => $count ) {
            if ( ! isset( $result[ $key ] ) ) {
                $result[ $key ] = self::empty_summary();
            }
            $result[ $key ]['people'] += $count;
        }

        // Helferzahl aus den übernommenen Aufgaben – schließt Begleitpersonen
        // ein und ersetzt die Zählung über das Helfer-Flag.
        foreach ( ( new ItemModel() )->helper_counts_for_instances( $instances ) as $key => $count ) {
            if ( ! isset( $result[ $key ] ) ) {
                $result[ $key ] = self::empty_summary();
            }
            $result[ $key ]['helpers'] = $count;
        }

        return $result;
    }

    public static function empty_summary(): array {
        return [
            'yes'         => 0,
            'no'          => 0,
            'helpers'     => 0,
            // Gesamtzahl erwarteter Personen: Zusagende plus Begleitpersonen.
            'people'      => 0,
            'own'         => null,
            'own_helping' => false,
            'own_task'    => null,
        ];
    }

    /**
     * Aggregierte Zahlen über alle Instanzen eines Termins (für die Admin-Übersicht).
     *
     * @return array{yes:int, no:int, helpers:int}
     */
    public function totals_for_event( int $event_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT response, COUNT(*) AS cnt
                 FROM {$this->table}
                 WHERE event_id = %d GROUP BY response",
                $event_id
            ),
            ARRAY_A
        ) ?: [];

        $totals = [ 'yes' => 0, 'no' => 0, 'helpers' => 0 ];
        foreach ( $rows as $row ) {
            $totals[ $row['response'] ] = (int) $row['cnt'];
        }

        // Helfer über die übernommenen Aufgaben zählen, damit Begleitpersonen
        // mitzählen.
        $totals['helpers'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT CONCAT(c.person_id, ':', c.companion_id))
                 FROM {$wpdb->prefix}vp_event_items i
                 INNER JOIN {$wpdb->prefix}vp_event_item_claims c ON c.item_id = i.id
                 WHERE i.event_id = %d AND i.kind = 'help'",
                $event_id
            )
        );

        return $totals;
    }

    // ------------------------------------------------------------------
    // Interne Helpers
    // ------------------------------------------------------------------

    /**
     * Rohzeilen nach Antwort gruppieren und Anzeigenamen ergänzen.
     */
    /**
     * @param array<int, array<int, string>> $companions  person_id => [Namen]
     */
    /**
     * Eigene Teilnahme und Begleitpersonen sind unabhängig voneinander:
     * jemand kann selbst absagen, seine Kinder aber trotzdem schicken.
     * Deshalb zählen Begleitpersonen unabhängig von der eigenen Antwort.
     *
     * @param array<int, array<int, string>> $companions  person_id => [Namen]
     */
    private function group( array $rows, array $companions = [] ): array {
        $result = [ 'yes' => [], 'no' => [], 'helpers' => [], 'people' => 0, 'attending' => [] ];

        $seen = [];

        // Wessen Kontaktangabe noch offen ist, wird gekennzeichnet – Gäste
        // aus Terminlinks bestätigen erst per Mail.
        $ids   = array_map( fn( $r ) => (int) $r['person_id'], $rows );
        $ids   = array_merge( $ids, array_map( 'intval', array_keys( $companions ) ) );
        $open  = Person::unconfirmed_map( $ids );
        // Namen im Stapel auflösen, sonst eine Abfrage je Person.
        $namen = Person::names( $ids );

        foreach ( $rows as $row ) {
            $user_id = (int) $row['person_id'];
            $name    = $namen[ $user_id ] ?? __( '(entfernte Person)', 'vereins-plugin' );
            $helping = ! empty( $row['helping'] );
            $guests  = $companions[ $user_id ] ?? [];
            $seen[ $user_id ] = true;

            $entry = [
                'user_id'   => $user_id,
                'name'      => $name,
                'unconfirmed' => isset( $open[ $user_id ] ),
                'helping'   => $helping,
                'help_task' => $row['help_task'] ?? null,
                // Begleitpersonen nur beim zusagenden Mitglied anhängen. Wer
                // selbst absagt, dessen Begleitung steht eigenständig in
                // 'attending' – sonst läse sie sich wie eine Absage.
                'companions' => $row['response'] === 'yes' ? $guests : [],
            ];

            $result[ $row['response'] ][] = $entry;

            if ( $row['response'] === 'yes' ) {
                $result['people']++;
                $result['attending'][] = [ 'name' => $name, 'via' => null, 'unconfirmed' => isset( $open[ $user_id ] ) ];
            }

            foreach ( $guests as $guest ) {
                $result['people']++;
                $result['attending'][] = [ 'name' => $guest, 'via' => $name, 'unconfirmed' => isset( $open[ $user_id ] ) ];
            }

            if ( $helping ) {
                $result['helpers'][] = $entry;
            }
        }

        // Begleitpersonen von Mitgliedern ohne eigene Antwort – etwa wenn
        // jemand nur die Kinder anmeldet, ohne sich selbst zu äußern.
        foreach ( $companions as $user_id => $guests ) {
            if ( isset( $seen[ $user_id ] ) ) {
                continue;
            }
            $name = $namen[ (int) $user_id ] ?? __( '(entfernte Person)', 'vereins-plugin' );

            foreach ( $guests as $guest ) {
                $result['people']++;
                $result['attending'][] = [ 'name' => $guest, 'via' => $name, 'unconfirmed' => isset( $open[ (int) $user_id ] ) ];
            }
        }

        return $result;
    }
}
