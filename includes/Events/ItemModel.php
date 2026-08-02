<?php

namespace VereinsPlugin\Events;

use VereinsPlugin\Members\Person;

/**
 * DB-Operationen für die Mitbringliste (vp_event_items + vp_event_item_claims).
 *
 * Zwei Sorten Einträge:
 *   is_request = 1 → Bedarf, den das Orgateam vorgibt ("3x Kuchen").
 *                    Mitglieder übernehmen davon offene Plätze.
 *   is_request = 0 → freier Eintrag eines Mitglieds ("Ich bringe Salat mit").
 *                    Der Ersteller ist automatisch der einzige Übernehmer.
 */
class ItemModel {

    private string $items;
    private string $claims;

    public function __construct() {
        global $wpdb;
        $this->items  = $wpdb->prefix . 'vp_event_items';
        $this->claims = $wpdb->prefix . 'vp_event_item_claims';
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->items} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Komplette Mitbringliste einer Termininstanz inkl. Übernahmen und Namen.
     *
     * @return array<int, array{
     *   id:int, label:string, quantity_needed:int, is_request:bool,
     *   claims: array<int, array{user_id:int, name:string, quantity:int, note:?string}>,
     *   claimed:int, open:int, own_claim:bool
     * }>
     */
    public function for_instance( int $event_id, string $instance_date, int $user_id = 0, string $kind = 'bring' ): array {
        global $wpdb;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->items}
                 WHERE event_id = %d AND instance_date = %s AND kind = %s
                 ORDER BY is_request DESC, created_at ASC",
                $event_id, $instance_date, $kind
            ),
            ARRAY_A
        ) ?: [];

        if ( empty( $items ) ) {
            return [];
        }

        $ids          = array_map( fn( $i ) => (int) $i['id'], $items );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $claims = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->claims} WHERE item_id IN ($placeholders) ORDER BY claimed_at ASC",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];

        $by_item = [];
        foreach ( $claims as $claim ) {
            $by_item[ (int) $claim['item_id'] ][] = $claim;
        }

        $companions = new CompanionModel();

        $result = [];
        foreach ( $items as $item ) {
            $item_id     = (int) $item['id'];
            $item_claims = $by_item[ $item_id ] ?? [];

            $claimed   = 0;
            $own_claim = false;
            $list      = [];

            foreach ( $item_claims as $claim ) {
                $claim_user   = (int) $claim['person_id'];
                $companion_id = (int) ( $claim['companion_id'] ?? 0 );
                $member_name  = Person::name( $claim_user );
                $claimed     += (int) $claim['quantity'];

                if ( $claim_user === $user_id ) {
                    $own_claim = true;
                }

                // Übernimmt eine Begleitperson, steht deren Name vorn.
                $name = $member_name;
                if ( $companion_id ) {
                    $companion = $companions->find_saved( $claim_user, $companion_id );
                    if ( $companion ) {
                        $name = $companion['name'];
                    }
                }

                $list[] = [
                    'user_id'      => $claim_user,
                    'companion_id' => $companion_id,
                    'name'         => $name,
                    'via'          => $companion_id ? $member_name : null,
                    'own'          => $claim_user === $user_id,
                    'quantity'     => (int) $claim['quantity'],
                    'note'         => $claim['note'],
                ];
            }

            $needed = (int) $item['quantity_needed'];

            $result[] = [
                'id'              => $item_id,
                'label'           => $item['label'],
                'kind'            => $item['kind'],
                'quantity_needed' => $needed,
                'is_request'      => (bool) $item['is_request'],
                'created_by'      => (int) $item['created_by'],
                'claims'          => $list,
                'claimed'         => $claimed,
                'open'            => max( 0, $needed - $claimed ),
                'own_claim'       => $own_claim,
            ];
        }

        return $result;
    }

    /**
     * Kurzfassung für die Terminliste: wie viele Posten offen sind.
     *
     * @param  array<int, array{event_id:int, instance_date:string}> $instances
     * @return array<string, array{items:int, open:int}>  Key: "{event_id}|{instance_date}"
     */
    public function summary_for_instances( array $instances, string $kind = 'bring' ): array {
        global $wpdb;

        if ( empty( $instances ) ) {
            return [];
        }

        $clauses = [];
        $params  = [];
        foreach ( $instances as $inst ) {
            $clauses[] = '(i.event_id = %d AND i.instance_date = %s)';
            $params[]  = (int) $inst['event_id'];
            $params[]  = $inst['instance_date'];
        }
        $params[] = $kind;

        $sql = "SELECT i.event_id, i.instance_date, i.id, i.quantity_needed,
                       COALESCE(SUM(c.quantity), 0) AS claimed
                FROM {$this->items} i
                LEFT JOIN {$this->claims} c ON c.item_id = i.id
                WHERE (" . implode( ' OR ', $clauses ) . ') AND i.kind = %s
                GROUP BY i.id';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $key = $row['event_id'] . '|' . $row['instance_date'];

            if ( ! isset( $result[ $key ] ) ) {
                $result[ $key ] = [ 'items' => 0, 'open' => 0 ];
            }

            $result[ $key ]['items']++;
            $result[ $key ]['open'] += max( 0, (int) $row['quantity_needed'] - (int) $row['claimed'] );
        }

        return $result;
    }

    /**
     * Alle Personen, die für eine Termininstanz Helferaufgaben übernommen
     * haben – Mitglieder wie Begleitpersonen, je Person einmal mit allen
     * ihren Aufgaben.
     *
     * Das ist die verlässliche Quelle für die Helferzahl: Seit Aufgaben
     * konkret übernommen werden, sagt das Flag in vp_event_attendance nur
     * noch etwas über das Mitglied selbst aus.
     *
     * @return array<int, array{name:string, via:?string, help_task:string}>
     */
    public function helpers_for_instance( int $event_id, string $instance_date ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.person_id, c.companion_id, i.label
                 FROM {$this->claims} c
                 INNER JOIN {$this->items} i ON i.id = c.item_id
                 WHERE i.event_id = %d AND i.instance_date = %s AND i.kind = 'help'
                 ORDER BY c.claimed_at ASC",
                $event_id, $instance_date
            ),
            ARRAY_A
        ) ?: [];

        $companions = new CompanionModel();
        $people     = [];
        // Gäste aus Terminlinks bleiben bis zur Bestätigung gekennzeichnet.
        $ids   = array_map( fn( $r ) => (int) $r['person_id'], $rows );
        $open  = Person::unconfirmed_map( $ids );
        $namen = Person::names( $ids );

        foreach ( $rows as $row ) {
            $user_id      = (int) $row['person_id'];
            $companion_id = (int) $row['companion_id'];
            $key          = $user_id . ':' . $companion_id;

            if ( ! isset( $people[ $key ] ) ) {
                $member_name = $namen[ $user_id ] ?? __( '(entfernte Person)', 'vereins-plugin' );
                $name        = $member_name;
                $via         = null;

                if ( $companion_id ) {
                    $companion = $companions->find_saved( $user_id, $companion_id );
                    $name      = $companion ? $companion['name'] : __( '(entfernte Person)', 'vereins-plugin' );
                    $via       = $member_name;
                }

                $people[ $key ] = [
                    'name'        => $name,
                    'via'         => $via,
                    'unconfirmed' => isset( $open[ $user_id ] ),
                    'tasks'       => [],
                ];
            }

            $people[ $key ]['tasks'][] = $row['label'];
        }

        return array_values( array_map(
            fn( $p ) => [
                'name'        => $p['name'],
                'via'         => $p['via'],
                'unconfirmed' => $p['unconfirmed'],
                'help_task'   => implode( ', ', $p['tasks'] ),
            ],
            $people
        ) );
    }

    /**
     * Zahl der Helfer je Instanz – jede Person einmal, egal wie viele
     * Aufgaben sie übernommen hat.
     *
     * @param  array<int, array{event_id:int, instance_date:string}> $instances
     * @return array<string, int>  Key: "{event_id}|{instance_date}"
     */
    public function helper_counts_for_instances( array $instances ): array {
        global $wpdb;

        if ( empty( $instances ) ) {
            return [];
        }

        $clauses = [];
        $params  = [];
        foreach ( $instances as $inst ) {
            $clauses[] = '(i.event_id = %d AND i.instance_date = %s)';
            $params[]  = (int) $inst['event_id'];
            $params[]  = $inst['instance_date'];
        }

        $sql = "SELECT i.event_id, i.instance_date,
                       COUNT(DISTINCT CONCAT(c.person_id, ':', c.companion_id)) AS cnt
                FROM {$this->items} i
                INNER JOIN {$this->claims} c ON c.item_id = i.id
                WHERE (" . implode( ' OR ', $clauses ) . ") AND i.kind = 'help'
                GROUP BY i.event_id, i.instance_date";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['event_id'] . '|' . $row['instance_date'] ] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * Aggregierte Zahlen über alle Instanzen eines Termins (Admin-Übersicht).
     *
     * @return array{items:int, open:int}
     */
    public function totals_for_event( int $event_id, string $kind = 'bring' ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.quantity_needed, COALESCE(SUM(c.quantity), 0) AS claimed
                 FROM {$this->items} i
                 LEFT JOIN {$this->claims} c ON c.item_id = i.id
                 WHERE i.event_id = %d AND i.kind = %s
                 GROUP BY i.id",
                $event_id,
                $kind
            ),
            ARRAY_A
        ) ?: [];

        $totals = [ 'items' => 0, 'open' => 0 ];
        foreach ( $rows as $row ) {
            $totals['items']++;
            $totals['open'] += max( 0, (int) $row['quantity_needed'] - (int) $row['claimed'] );
        }
        return $totals;
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    public function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            $this->items,
            [
                'event_id'        => $data['event_id'],
                'instance_date'   => $data['instance_date'],
                'kind'            => in_array( $data['kind'] ?? '', [ 'bring', 'help' ], true ) ? $data['kind'] : 'bring',
                'label'           => $data['label'],
                'quantity_needed' => max( 1, (int) ( $data['quantity_needed'] ?? 1 ) ),
                'is_request'      => ! empty( $data['is_request'] ) ? 1 : 0,
                'created_by'      => $data['created_by'],
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%d' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Überträgt die Posten einer Vorlage auf eine Termininstanz. Bereits
     * vorhandene Posten derselben Art bleiben unangetastet; doppelte
     * Bezeichnungen werden übersprungen.
     *
     * @return int  Anzahl neu angelegter Posten
     */
    public function apply_template( int $event_id, string $instance_date, int $template_id, int $created_by ): int {
        $template = ( new TemplateModel() )->find( $template_id );
        if ( ! $template || empty( $template['items'] ) ) {
            return 0;
        }

        $existing = array_column(
            $this->for_instance( $event_id, $instance_date, 0, $template['kind'] ),
            'label'
        );

        $created = 0;
        foreach ( $template['items'] as $item ) {
            if ( in_array( $item['label'], $existing, true ) ) {
                continue;
            }

            $this->create( [
                'event_id'        => $event_id,
                'instance_date'   => $instance_date,
                'kind'            => $template['kind'],
                'label'           => $item['label'],
                'quantity_needed' => (int) $item['quantity_needed'],
                'is_request'      => 1,
                'created_by'      => $created_by,
            ] );
            $created++;
        }

        return $created;
    }

    public function delete( int $id ): void {
        global $wpdb;

        $item = $this->find( $id );

        // Betroffene Nutzer merken, um deren Helferstatus danach neu zu berechnen.
        $affected = ( $item && $item['kind'] === 'help' )
            ? $wpdb->get_col( $wpdb->prepare( "SELECT person_id FROM {$this->claims} WHERE item_id = %d", $id ) )
            : [];

        $wpdb->delete( $this->claims, [ 'item_id' => $id ], [ '%d' ] );
        $wpdb->delete( $this->items,  [ 'id'      => $id ], [ '%d' ] );

        foreach ( $affected as $user_id ) {
            $this->sync_helper_status( (int) $item['event_id'], $item['instance_date'], (int) $user_id );
        }
    }

    /**
     * Alle Posten einer Termininstanz löschen (z. B. beim Löschen des Termins).
     */
    public function delete_for_event( int $event_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE c FROM {$this->claims} c
                 INNER JOIN {$this->items} i ON i.id = c.item_id
                 WHERE i.event_id = %d",
                $event_id
            )
        );
        $wpdb->delete( $this->items, [ 'event_id' => $event_id ], [ '%d' ] );
    }

    // ------------------------------------------------------------------
    // Übernahmen
    // ------------------------------------------------------------------

    /**
     * Hält den Helferstatus in vp_event_attendance mit den übernommenen
     * Helferaufgaben in Deckung. Wird nach jeder Änderung an help-Posten
     * aufgerufen.
     */
    private function sync_helper_status( int $event_id, string $instance_date, int $person_id ): void {
        global $wpdb;

        // Nur was das Mitglied selbst übernimmt (companion_id = 0) zählt als
        // eigener Helferstatus – Aufgaben der Begleitpersonen stehen für sich.
        $tasks = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT i.label
                 FROM {$this->claims} c
                 INNER JOIN {$this->items} i ON i.id = c.item_id
                 WHERE i.event_id = %d AND i.instance_date = %s
                   AND i.kind = 'help' AND c.person_id = %d AND c.companion_id = 0
                 ORDER BY c.claimed_at ASC",
                $event_id, $instance_date, $person_id
            )
        ) ?: [];

        ( new AttendanceModel() )->sync_helper_status( $event_id, $instance_date, $person_id, $tasks );
    }

    /**
     * Posten übernehmen. Gibt false zurück, wenn nichts mehr offen ist.
     */
    /**
     * Posten übernehmen – wahlweise für sich selbst ($companion_id = 0) oder
     * für eine Begleitperson. So kann eine Familie mehrere Aufgaben abdecken.
     */
    public function claim( int $item_id, int $person_id, int $quantity = 1, ?string $note = null, int $companion_id = 0 ): bool {
        global $wpdb;

        $item = $this->find( $item_id );
        if ( ! $item ) {
            return false;
        }

        $quantity = max( 1, $quantity );
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, quantity FROM {$this->claims}
                 WHERE item_id = %d AND person_id = %d AND companion_id = %d",
                $item_id, $person_id, $companion_id
            ),
            ARRAY_A
        );

        // Bereits vergebene Menge ohne den eigenen (zu ersetzenden) Anteil
        $claimed_by_others = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM {$this->claims}
                 WHERE item_id = %d AND NOT (person_id = %d AND companion_id = %d)",
                $item_id, $person_id, $companion_id
            )
        );

        if ( $claimed_by_others + $quantity > (int) $item['quantity_needed'] ) {
            return false;
        }

        if ( $existing ) {
            $wpdb->update(
                $this->claims,
                [ 'quantity' => $quantity, 'note' => $note, 'claimed_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $existing['id'] ],
                [ '%d', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $this->claims,
                [
                    'item_id'      => $item_id,
                    'person_id'   => $person_id,
                    'companion_id' => $companion_id,
                    'quantity'     => $quantity,
                    'note'         => $note,
                    'claimed_at'   => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%d', '%d', '%s', '%s' ]
            );
        }

        if ( $item['kind'] === 'help' ) {
            $this->sync_helper_status( (int) $item['event_id'], $item['instance_date'], $person_id );
        }

        return true;
    }

    /**
     * Übernahme zurückziehen. Freie Einträge (kein Bedarfsposten) verschwinden
     * dabei komplett, da sie ohne Übernehmer keinen Sinn ergeben.
     */
    public function release( int $item_id, int $person_id, int $companion_id = 0 ): bool {
        global $wpdb;

        $item = $this->find( $item_id );
        if ( ! $item ) {
            return false;
        }

        $wpdb->delete(
            $this->claims,
            [ 'item_id' => $item_id, 'person_id' => $person_id, 'companion_id' => $companion_id ],
            [ '%d', '%d', '%d' ]
        );

        if ( ! $item['is_request'] ) {
            $remaining = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$this->claims} WHERE item_id = %d", $item_id )
            );
            if ( $remaining === 0 ) {
                $wpdb->delete( $this->items, [ 'id' => $item_id ], [ '%d' ] );
            }
        }

        if ( $item['kind'] === 'help' ) {
            $this->sync_helper_status( (int) $item['event_id'], $item['instance_date'], $person_id );
        }

        return true;
    }
}
