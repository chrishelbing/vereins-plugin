<?php

namespace VereinsPlugin\Events;

/**
 * DB-Operationen für Termine (vp_events, vp_event_recurrence, vp_event_exceptions).
 */
class EventModel {

    private string $table;
    private string $recurrence_table;
    private string $exceptions_table;

    public function __construct() {
        global $wpdb;
        $this->table            = $wpdb->prefix . 'vp_events';
        $this->recurrence_table = $wpdb->prefix . 'vp_event_recurrence';
        $this->exceptions_table = $wpdb->prefix . 'vp_event_exceptions';
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public function find( int $id ): ?array {
        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $event ) return null;

        $event['recurrence'] = $this->get_recurrence( $id );
        $event['exceptions'] = $this->get_exceptions( $id );
        return $event;
    }

    /**
     * Alle Stamm-Events (ohne Zeitraumfilter) – für die Admin-Übersicht.
     * $status: 'approved' | 'pending' | 'rejected' | 'draft' | null (= alle)
     */
    public function all( ?string $status = null, int $limit = 200 ): array {
        global $wpdb;

        $sql    = "SELECT e.*, et.name AS type_name, et.color AS type_color, et.is_resource,
                       et.allows_rsvp, et.allows_items, et.needs_helpers, et.allows_photos
                    FROM {$this->table} e
                    LEFT JOIN {$wpdb->prefix}vp_event_types et ON et.id = e.event_type_id";
        $params = [];

        if ( $status ) {
            $sql     .= ' WHERE e.approval_status = %s';
            $params[] = $status;
        }
        $sql     .= ' ORDER BY e.start_at DESC LIMIT %d';
        $params[] = $limit;

        $events = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        foreach ( $events as &$event ) {
            $event['recurrence'] = $this->get_recurrence( (int) $event['id'] );
        }
        return $events;
    }

    public function count_by_status( string $status ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE approval_status = %s", $status )
        );
    }

    /**
     * Alle Stamm-Events in einem Zeitraum (ohne Expansion der Serien).
     * Für die Expansion → RecurrenceExpander::expand().
     *
     * $approval_status: nur Events mit diesem Status liefern (Standard: 'approved',
     * damit ausstehende/abgelehnte Mitglieder-Termine nirgends öffentlich auftauchen).
     * null = kein Filter (für interne/Admin-Zwecke).
     */
    public function in_range( string $from, string $to, ?string $approval_status = 'approved' ): array {
        global $wpdb;

        $sql    = "SELECT e.*, et.name AS type_name, et.color AS type_color, et.is_resource,
                       et.allows_rsvp, et.allows_items, et.needs_helpers, et.allows_photos
                    FROM {$this->table} e
                    LEFT JOIN {$wpdb->prefix}vp_event_types et ON et.id = e.event_type_id
                    WHERE (e.start_at < %s AND e.end_at > %s OR e.is_recurring = 1)";
        $params = [ $to, $from ];

        if ( $approval_status ) {
            $sql     .= ' AND e.approval_status = %s';
            $params[] = $approval_status;
        }
        $sql .= ' ORDER BY e.start_at ASC';

        $events = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        foreach ( $events as &$event ) {
            $event['recurrence'] = $this->get_recurrence( (int) $event['id'] );
            $event['exceptions'] = $this->get_exceptions( (int) $event['id'] );
        }
        return $events;
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    public function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'event_type_id'   => $data['event_type_id'],
                'title'           => $data['title'],
                'description'     => $data['description']  ?? null,
                'location'        => $data['location']      ?? null,
                'start_at'        => $data['start_at'],
                'end_at'          => $data['end_at'],
                'is_full_day'     => $data['is_full_day']   ?? 0,
                'is_recurring'    => ! empty( $data['recurrence'] ) ? 1 : 0,
                'resource_id'     => $data['resource_id']   ?? null,
                'approval_status' => $data['approval_status'] ?? 'approved',
                'photo_upload_days' => $data['photo_upload_days'] ?? null,
                'banner_days_before' => $data['banner_days_before'] ?? null,
                'tags'            => $data['tags'] ?? null,
                'is_public'       => ! empty( $data['is_public'] ) ? 1 : 0,
                'created_by'      => $data['created_by'],
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d' ]
        );

        $event_id = (int) $wpdb->insert_id;

        if ( ! empty( $data['recurrence'] ) ) {
            $this->save_recurrence( $event_id, $data['recurrence'] );
        }

        return $event_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        $fields  = [];
        $formats = [];

        $map = [
            'event_type_id'   => '%d',
            'title'           => '%s',
            'description'     => '%s',
            'location'        => '%s',
            'start_at'        => '%s',
            'end_at'          => '%s',
            'is_full_day'     => '%d',
            'resource_id'     => '%d',
            'approval_status' => '%s',
            'reviewed_by'     => '%d',
            'reviewed_at'     => '%s',
            'photo_upload_days' => '%d',
            'image_attachment_id' => '%d',
            'banner_days_before'  => '%d',
            'tags'                => '%s',
            'is_public'           => '%d',
        ];

        foreach ( $map as $key => $fmt ) {
            if ( array_key_exists( $key, $data ) ) {
                $fields[ $key ] = $data[ $key ];
                $formats[]      = $fmt;
            }
        }

        if ( ! empty( $data['recurrence'] ) ) {
            $fields['is_recurring'] = 1;
            $formats[]              = '%d';
            $this->save_recurrence( $id, $data['recurrence'] );
        }

        if ( empty( $fields ) ) return false;

        return (bool) $wpdb->update( $this->table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public function approve( int $id, int $reviewer_id ): bool {
        return $this->update( $id, [
            'approval_status' => 'approved',
            'reviewed_by'     => $reviewer_id,
            'reviewed_at'     => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    /**
     * Schaltet einen Termin zwischen Entwurf und live hin und her.
     *
     * Nur für Termine, die schon live sind oder Entwurf: Ein vorgeschlagener
     * Termin geht über Genehmigen/Ablehnen, ein abgelehnter bleibt abgelehnt.
     * Die Prüfspalten (reviewed_by/at) bleiben unberührt – Umschalten ist
     * keine Freigabe.
     */
    public function set_live( int $id, bool $live ): bool {
        $event = $this->find( $id );
        if ( ! $event || ! in_array( $event['approval_status'], [ 'approved', 'draft' ], true ) ) {
            return false;
        }
        return $this->update( $id, [ 'approval_status' => $live ? 'approved' : 'draft' ] );
    }

    public function reject( int $id, int $reviewer_id ): bool {
        return $this->update( $id, [
            'approval_status' => 'rejected',
            'reviewed_by'     => $reviewer_id,
            'reviewed_at'     => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    public function delete( int $id ): void {
        global $wpdb;
        ( new \VereinsPlugin\Photos\PhotoModel() )->delete_for_event( $id );
        ( new ItemModel() )->delete_for_event( $id );
        ( new CompanionModel() )->delete_for_event( $id );
        $wpdb->delete( $wpdb->prefix . 'vp_event_attendance', [ 'event_id' => $id ], [ '%d' ] );
        $wpdb->delete( $this->exceptions_table, [ 'event_id' => $id ], [ '%d' ] );
        $wpdb->delete( $this->recurrence_table,  [ 'event_id' => $id ], [ '%d' ] );
        $wpdb->delete( $this->table,              [ 'id'       => $id ], [ '%d' ] );
    }

    // ------------------------------------------------------------------
    // Ausnahmen
    // ------------------------------------------------------------------

    public function save_exception( int $event_id, string $original_date, array $data ): int {
        global $wpdb;

        // Upsert: bestehende Ausnahme für diesen Tag ersetzen
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->exceptions_table} WHERE event_id = %d AND original_date = %s",
                $event_id, $original_date
            )
        );

        $payload = [
            'exception_type' => $data['exception_type'] ?? 'modified',
            'title'          => $data['title']           ?? null,
            'description'    => $data['description']     ?? null,
            'location'       => $data['location']        ?? null,
            'start_at'       => $data['start_at']        ?? null,
            'end_at'         => $data['end_at']          ?? null,
            'modified_by'    => $data['modified_by'],
        ];
        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];

        if ( $existing ) {
            $wpdb->update( $this->exceptions_table, $payload, [ 'id' => (int) $existing ], $formats, [ '%d' ] );
            return (int) $existing;
        }

        $wpdb->insert(
            $this->exceptions_table,
            array_merge( [ 'event_id' => $event_id, 'original_date' => $original_date ], $payload ),
            array_merge( [ '%d', '%s' ], $formats )
        );
        return (int) $wpdb->insert_id;
    }

    // ------------------------------------------------------------------
    // Interne Helpers
    // ------------------------------------------------------------------

    private function get_recurrence( int $event_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->recurrence_table} WHERE event_id = %d", $event_id ),
            ARRAY_A
        );
        if ( $row && $row['weekdays'] ) {
            $row['weekdays'] = json_decode( $row['weekdays'], true );
        }
        return $row ?: null;
    }

    private function get_exceptions( int $event_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->exceptions_table} WHERE event_id = %d ORDER BY original_date ASC",
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    private function save_recurrence( int $event_id, array $rule ): void {
        global $wpdb;

        $payload = [
            'event_id'    => $event_id,
            'frequency'   => $rule['frequency'],
            'interval_n'  => $rule['interval']   ?? 1,
            'weekdays'    => isset( $rule['weekdays'] ) ? json_encode( $rule['weekdays'] ) : null,
            'month_day'   => $rule['month_day']  ?? null,
            'until'       => $rule['until']      ?? null,
            'count'       => $rule['count']      ?? null,
        ];

        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$this->recurrence_table} WHERE event_id = %d", $event_id )
        );

        if ( $existing ) {
            $wpdb->update( $this->recurrence_table, $payload, [ 'id' => (int) $existing ] );
        } else {
            $wpdb->insert( $this->recurrence_table, $payload );
        }
    }
}
