<?php

namespace VereinsPlugin\Members;

/**
 * DB-Operationen für vp_members, angereichert um wp_users-Daten
 * (E-Mail, Login, Rollen) für die Admin-Übersicht.
 */
class MemberModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_members';
    }

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_by_user_id( int $wp_user_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Alle Mitglieder, optional gefiltert nach Status, angereichert mit
     * E-Mail/Login/Rollen aus wp_users.
     */
    public function all( ?string $status = null ): array {
        global $wpdb;

        $sql = "SELECT m.*, u.user_email, u.user_login
                FROM {$this->table} m
                INNER JOIN {$wpdb->users} u ON u.ID = m.wp_user_id";
        $params = [];

        if ( $status ) {
            $sql     .= ' WHERE m.status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY m.created_at DESC';

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        $rows = $rows ?: [];

        foreach ( $rows as &$row ) {
            $user         = get_userdata( (int) $row['wp_user_id'] );
            $row['roles'] = $user ? (array) $user->roles : [];
        }

        return $rows;
    }

    /**
     * Nutzer-IDs, deren Kontakt (E-Mail/Telefon) noch nicht bestätigt ist.
     *
     * Betrifft vor allem Gäste, die über einen Terminlink zugesagt haben:
     * Ihre Zusage zählt, aber die Angabe ist ungeprüft. Eine Abfrage für die
     * ganze Liste, damit Anzeigen nicht je Person nachschlagen müssen.
     *
     * @param int[] $wp_user_ids
     * @return array<int,bool> user_id => true
     */
    public function unconfirmed_map( array $wp_user_ids ): array {
        global $wpdb;

        $ids = array_filter( array_map( 'intval', $wp_user_ids ) );
        if ( ! $ids ) {
            return [];
        }

        $in   = implode( ',', array_unique( $ids ) );
        $rows = $wpdb->get_col(
            "SELECT wp_user_id FROM {$this->table}
             WHERE wp_user_id IN ({$in}) AND confirmed_at IS NULL"
        );

        $map = [];
        foreach ( $rows ?: [] as $id ) {
            $map[ (int) $id ] = true;
        }
        return $map;
    }

    public function count_by_status( string $status ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", $status )
        );
    }

    public function create( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            $this->table,
            [
                'wp_user_id'   => $data['wp_user_id'],
                'display_name' => $data['display_name'] ?? '',
                'phone'        => $data['phone'] ?? null,
                'status'       => $data['status'] ?? 'pending',
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;
        $fields  = [];
        $formats = [];

        foreach ( [ 'display_name' => '%s', 'phone' => '%s', 'status' => '%s', 'confirmed_at' => '%s' ] as $key => $fmt ) {
            if ( array_key_exists( $key, $data ) ) {
                $fields[ $key ] = $data[ $key ];
                $formats[]      = $fmt;
            }
        }

        if ( empty( $fields ) ) {
            return false;
        }

        return (bool) $wpdb->update( $this->table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }
}
