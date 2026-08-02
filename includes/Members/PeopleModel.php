<?php

namespace VereinsPlugin\Members;

/**
 * Datenbankzugriff auf vp_people – Personen ohne WordPress-Profil.
 *
 * Hier liegen Gäste aus geteilten Terminlinks und Selbstregistrierungen, die
 * noch auf die Freigabe warten. Wer aufgenommen wird, wandert nach wp_users
 * und verschwindet aus dieser Tabelle.
 */
class PeopleModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_people';
    }

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_by_email( string $email ): ?array {
        global $wpdb;

        if ( ! $email ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s", $email ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_by_phone( string $phone ): ?array {
        global $wpdb;

        if ( ! $phone ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE phone = %s", $phone ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * @param string $source '' = alle, sonst 'link' oder 'registration'
     */
    public function all( string $source = '' ): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table}";
        if ( $source ) {
            $sql = $wpdb->prepare( $sql . ' WHERE source = %s', $source );
        }
        $sql .= ' ORDER BY created_at DESC';

        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }

    public function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'display_name'  => $data['display_name'] ?? '',
                'email'         => $data['email']   ?: null,
                'phone'         => $data['phone']   ?: null,
                'source'        => $data['source']  ?? 'link',
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        $felder  = [];
        $formate = [];
        foreach ( [ 'display_name' => '%s', 'email' => '%s', 'phone' => '%s', 'confirmed_at' => '%s' ] as $key => $fmt ) {
            if ( array_key_exists( $key, $data ) ) {
                $felder[ $key ] = $data[ $key ];
                $formate[]      = $fmt;
            }
        }

        if ( ! $felder ) {
            return false;
        }

        return (bool) $wpdb->update( $this->table, $felder, [ 'id' => $id ], $formate, [ '%d' ] );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    // ------------------------------------------------------------------
    // Aufbewahrung
    // ------------------------------------------------------------------

    /**
     * Offene Kontakte ohne Aktivität seit mindestens $days Tagen.
     *
     * Maßgeblich ist nicht das Anlegedatum, sondern die letzte Zusage –
     * sonst verlöre ein Gast, der jedes Jahr zum Sommerfest kommt, seinen
     * Eintrag nur, weil er ihn schon lange hat. Ohne jede Zusage zählt das
     * Anlegedatum.
     *
     * @return array<int, array>
     */
    public function stale( int $days ): array {
        global $wpdb;

        if ( $days <= 0 ) {
            return [];
        }

        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . " -{$days} days" ) );
        $rows      = $wpdb->get_results( "SELECT * FROM {$this->table}", ARRAY_A ) ?: [];

        return array_values( array_filter(
            $rows,
            fn( $row ) => $this->last_activity( (int) $row['id'], (string) $row['created_at'] ) < $threshold
        ) );
    }

    /**
     * Letzte Zusage einer Person, sonst ihr Anlegedatum.
     *
     * Absichtlich über get_col() statt MAX(...) in SQL gelöst und in PHP
     * verglichen – bei den üblicherweise winzigen Mengen je Person macht das
     * keinen Unterschied und bleibt unabhängig vom Datenbank-Dialekt.
     */
    private function last_activity( int $people_id, string $created_at ): string {
        global $wpdb;

        $antworten = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT responded_at FROM {$wpdb->prefix}vp_event_attendance WHERE person_id = %d",
                Person::from_guest( $people_id )
            )
        );

        $letzte_zusage = $antworten ? max( $antworten ) : '';

        return max( (string) $letzte_zusage, $created_at );
    }
}
