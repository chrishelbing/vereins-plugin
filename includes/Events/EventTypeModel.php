<?php

namespace VereinsPlugin\Events;

/**
 * DB-Operationen für Termintypen (vp_event_types).
 */
class EventTypeModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_event_types';
    }

    public function all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY name ASC",
            ARRAY_A
        ) ?: [];
    }

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function create( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            $this->table,
            [
                'name'                     => $data['name'],
                'color'                    => $data['color']       ?? '#3B82F6',
                'icon'                     => $data['icon']         ?? null,
                'is_resource'              => $data['is_resource']  ?? 0,
                'allows_items'             => $data['allows_items']  ?? 0,
                'needs_helpers'            => $data['needs_helpers'] ?? 0,
                'allows_photos'            => $data['allows_photos'] ?? 0,
                // Beide Felder sind freiwillig: Ein Aufruf ohne Vorlage – etwa
                // aus den Startdaten oder einem Test – soll keine Warnung über
                // einen fehlenden Schlüssel auslösen.
                'default_item_template_id' => ( $data['default_item_template_id'] ?? 0 ) ?: null,
                'default_help_template_id' => ( $data['default_help_template_id'] ?? 0 ) ?: null,
                'created_by'               => $data['created_by'],
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ]
        );
        return (int) $wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;
        $fields  = [];
        $formats = [];

        $map = [
            'name'          => '%s',
            'color'         => '%s',
            'icon'          => '%s',
            'is_resource'   => '%d',
            'allows_items'  => '%d',
            'needs_helpers' => '%d',
            'allows_photos' => '%d',
        ];

        foreach ( $map as $key => $fmt ) {
            if ( isset( $data[ $key ] ) ) {
                $fields[ $key ] = $data[ $key ];
                $formats[]      = $fmt;
            }
        }

        // Vorlagen-IDs dürfen bewusst auf NULL gesetzt werden ("keine Vorlage"),
        // daher hier array_key_exists statt isset.
        foreach ( [ 'default_item_template_id', 'default_help_template_id' ] as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $fields[ $key ] = $data[ $key ] ?: null;
                $formats[]      = '%d';
            }
        }

        if ( empty( $fields ) ) return false;

        return (bool) $wpdb->update( $this->table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    public function in_use( int $id ): bool {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}vp_events WHERE event_type_id = %d",
                $id
            )
        );
        return (int) $count > 0;
    }
}
