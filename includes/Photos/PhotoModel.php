<?php

namespace VereinsPlugin\Photos;

/**
 * DB-Operationen für Termin-Fotos (vp_event_photos).
 *
 * Die Bilddatei selbst liegt als reguläres WordPress-Attachment in der
 * Mediathek. Diese Tabelle hält nur die Zuordnung zum Termin, den Uploader,
 * die Dateigröße (fürs Kontingent) und den Löschzeitpunkt.
 */
class PhotoModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_event_photos';
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Alle Fotos einer Termininstanz, angereichert um URLs und Uploader-Namen.
     *
     * @return array<int, array{
     *   id:int, user_id:int, uploader:string, full:string, thumb:string,
     *   file_size:int, created_at:string, own:bool
     * }>
     */
    public function for_instance( int $event_id, string $instance_date, int $user_id = 0 ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s
                 ORDER BY created_at ASC, id ASC",
                $event_id, $instance_date
            ),
            ARRAY_A
        ) ?: [];

        $photos = [];
        foreach ( $rows as $row ) {
            $attachment_id = (int) $row['attachment_id'];
            $full          = wp_get_attachment_url( $attachment_id );

            // Attachment aus der Mediathek entfernt? Dann Karteileiche aufräumen.
            if ( ! $full ) {
                $this->delete_row( (int) $row['id'] );
                continue;
            }

            $thumb  = wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: $full;
            $owner  = (int) $row['wp_user_id'];
            $author = get_userdata( $owner );

            $photos[] = [
                'id'         => (int) $row['id'],
                'user_id'    => $owner,
                'uploader'   => $author ? $author->display_name : __( '(gelöschter Nutzer)', 'vereins-plugin' ),
                'full'       => $full,
                'thumb'      => $thumb,
                'file_size'  => (int) $row['file_size'],
                'created_at' => $row['created_at'],
                'own'        => $owner === $user_id,
            ];
        }

        return $photos;
    }

    /**
     * Vom Mitglied bei diesem Termin belegter Speicher (Bytes).
     * Das Kontingent gilt je Mitglied und Termininstanz.
     */
    public function used_bytes( int $event_id, string $instance_date, int $wp_user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(file_size), 0) FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s AND wp_user_id = %d",
                $event_id, $instance_date, $wp_user_id
            )
        );
    }

    /**
     * Anzahl und Gesamtgröße je Termin – für die Admin-Übersicht.
     *
     * @return array{count:int, bytes:int}
     */
    public function totals_for_event( int $event_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS bytes
                 FROM {$this->table} WHERE event_id = %d",
                $event_id
            ),
            ARRAY_A
        );

        return [
            'count' => (int) ( $row['cnt'] ?? 0 ),
            'bytes' => (int) ( $row['bytes'] ?? 0 ),
        ];
    }

    /**
     * Fotoanzahl für mehrere Instanzen auf einmal (Terminliste im Frontend).
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
                FROM {$this->table}
                WHERE " . implode( ' OR ', $clauses ) . '
                GROUP BY event_id, instance_date';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['event_id'] . '|' . $row['instance_date'] ] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * Attachment-IDs einer Instanz – für den ZIP-Download.
     *
     * @return int[]
     */
    public function attachment_ids( int $event_id, string $instance_date ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT attachment_id FROM {$this->table}
                 WHERE event_id = %d AND instance_date = %s
                 ORDER BY created_at ASC, id ASC",
                $event_id, $instance_date
            )
        ) ?: [] );
    }

    // ------------------------------------------------------------------
    // Ablauf (Cron)
    // ------------------------------------------------------------------

    /**
     * Fotos, deren Aufbewahrungsfrist abgelaufen ist.
     */
    public function expired( int $limit = 200 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE purge_after IS NOT NULL AND purge_after <= %s
                 ORDER BY purge_after ASC
                 LIMIT %d",
                current_time( 'mysql' ),
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Fotos, die bald ablaufen und für die noch keine Vorwarnung raus ist –
     * gruppiert nach Uploader und Termin.
     */
    public function due_for_warning( int $warn_days ): array {
        global $wpdb;

        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . " +{$warn_days} days" ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wp_user_id, event_id, instance_date,
                        COUNT(*) AS cnt, MIN(purge_after) AS purge_after
                 FROM {$this->table}
                 WHERE warned_at IS NULL
                   AND purge_after IS NOT NULL
                   AND purge_after <= %s
                 GROUP BY wp_user_id, event_id, instance_date",
                $threshold
            ),
            ARRAY_A
        ) ?: [];
    }

    public function mark_warned( int $wp_user_id, int $event_id, string $instance_date ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET warned_at = %s
                 WHERE wp_user_id = %d AND event_id = %d AND instance_date = %s AND warned_at IS NULL",
                current_time( 'mysql' ), $wp_user_id, $event_id, $instance_date
            )
        );
    }

    /**
     * Löschzeitpunkt aller Fotos eines Termins neu setzen – nötig, wenn sich
     * das Termindatum ändert oder die Frist angepasst wird.
     */
    public function reschedule_for_event( int $event_id, ?string $purge_after ): void {
        global $wpdb;
        $wpdb->update(
            $this->table,
            [ 'purge_after' => $purge_after, 'warned_at' => null ],
            [ 'event_id' => $event_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    public function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'event_id'      => $data['event_id'],
                'instance_date' => $data['instance_date'],
                'wp_user_id'    => $data['wp_user_id'],
                'attachment_id' => $data['attachment_id'],
                'file_size'     => $data['file_size'] ?? 0,
                'purge_after'   => $data['purge_after'] ?? null,
                'consent_at'    => $data['consent_at'] ?? null,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Foto samt Mediathek-Eintrag entfernen.
     */
    public function delete( int $id ): bool {
        $photo = $this->find( $id );
        if ( ! $photo ) {
            return false;
        }

        wp_delete_attachment( (int) $photo['attachment_id'], true );
        $this->delete_row( $id );

        return true;
    }

    /**
     * Alle Fotos eines Termins löschen (beim Löschen des Termins).
     */
    public function delete_for_event( int $event_id ): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, attachment_id FROM {$this->table} WHERE event_id = %d", $event_id ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as $row ) {
            wp_delete_attachment( (int) $row['attachment_id'], true );
        }

        $wpdb->delete( $this->table, [ 'event_id' => $event_id ], [ '%d' ] );
    }

    /**
     * Alle Fotos einer Person löschen, quer über alle Termine – für die
     * vollständige Löschung eines Mitglieds (Recht auf Löschung).
     */
    public function delete_for_user( int $wp_user_id ): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, attachment_id FROM {$this->table} WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as $row ) {
            wp_delete_attachment( (int) $row['attachment_id'], true );
        }

        $wpdb->delete( $this->table, [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
    }

    /**
     * Nur den Datensatz entfernen, ohne das Attachment anzufassen.
     */
    public function delete_row( int $id ): void {
        global $wpdb;
        $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }
}
