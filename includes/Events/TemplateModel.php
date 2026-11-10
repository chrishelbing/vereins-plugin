<?php

namespace VereinsPlugin\Events;

/**
 * Wiederverwendbare Vorlagen für Mitbringliste und Helferaufgaben
 * (vp_templates + vp_template_items).
 *
 * kind = 'bring' → Mitbringliste ("Kuchen 3x, Salat 2x")
 * kind = 'help'  → Helferaufgaben ("Aufbau 3x, Grill 2x")
 */
class TemplateModel {

    public const KINDS = [ 'bring', 'help' ];

    private string $templates;
    private string $items;

    /** Auswahlwert im Terminformular: „Standard des Termintyps“. */
    public const CHOICE_DEFAULT = 'default';

    public function __construct() {
        global $wpdb;
        $this->templates = $wpdb->prefix . 'vp_templates';
        $this->items     = $wpdb->prefix . 'vp_template_items';
    }

    /**
     * Welche Vorlage soll beim Speichern eines Termins angewendet werden?
     *
     * Beim Anlegen heißt ein leeres Feld „Standard des Termintyps“ – so wie
     * bisher. Beim Bearbeiten heißt es „nichts anwenden“: Eine Vorlage greift
     * an einem bestehenden Termin nur, wenn jemand sie ausdrücklich wählt.
     * "0" ist in beiden Fällen „keine Vorlage“.
     *
     * @return int  Vorlagen-ID oder 0
     */
    public static function resolve_choice( string $raw, int $type_default, bool $is_new ): int {
        if ( $raw === self::CHOICE_DEFAULT ) {
            return $type_default;
        }
        if ( $raw === '' ) {
            return $is_new ? $type_default : 0;
        }
        return max( 0, (int) $raw );
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Alle Vorlagen, optional nach Art gefiltert – inklusive ihrer Posten.
     */
    public function all( ?string $kind = null ): array {
        global $wpdb;

        $sql    = "SELECT * FROM {$this->templates}";
        $params = [];

        if ( $kind && in_array( $kind, self::KINDS, true ) ) {
            $sql     .= ' WHERE kind = %s';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY kind ASC, name ASC';

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        $rows = $rows ?: [];

        foreach ( $rows as &$row ) {
            $row['items'] = $this->items_for( (int) $row['id'] );
        }
        return $rows;
    }

    public function find( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->templates} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['items'] = $this->items_for( $id );
        return $row;
    }

    public function items_for( int $template_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->items} WHERE template_id = %d ORDER BY sort_order ASC, id ASC",
                $template_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * @param array{kind:string, name:string, created_by:int, items?:array<int, array{label:string, quantity_needed?:int}>} $data
     */
    public function create( array $data ): int {
        global $wpdb;

        $kind = in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'bring';

        $wpdb->insert(
            $this->templates,
            [
                'kind'       => $kind,
                'name'       => $data['name'],
                'created_by' => $data['created_by'],
            ],
            [ '%s', '%s', '%d' ]
        );

        $template_id = (int) $wpdb->insert_id;

        if ( ! empty( $data['items'] ) ) {
            $this->replace_items( $template_id, $data['items'] );
        }

        return $template_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        if ( isset( $data['name'] ) ) {
            $wpdb->update( $this->templates, [ 'name' => $data['name'] ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        }

        if ( array_key_exists( 'items', $data ) ) {
            $this->replace_items( $id, $data['items'] ?? [] );
        }

        return true;
    }

    public function delete( int $id ): void {
        global $wpdb;

        $wpdb->delete( $this->items,     [ 'template_id' => $id ], [ '%d' ] );
        $wpdb->delete( $this->templates, [ 'id'          => $id ], [ '%d' ] );

        // Verweise in Termintypen aufräumen, damit keine toten IDs zurückbleiben.
        $types = $wpdb->prefix . 'vp_event_types';
        $wpdb->update( $types, [ 'default_item_template_id' => null ], [ 'default_item_template_id' => $id ] );
        $wpdb->update( $types, [ 'default_help_template_id' => null ], [ 'default_help_template_id' => $id ] );
    }

    /**
     * Posten einer Vorlage komplett ersetzen.
     *
     * @param array<int, array{label:string, quantity_needed?:int}> $items
     */
    public function replace_items( int $template_id, array $items ): void {
        global $wpdb;

        $wpdb->delete( $this->items, [ 'template_id' => $template_id ], [ '%d' ] );

        $order = 0;
        foreach ( $items as $item ) {
            $label = trim( (string) ( $item['label'] ?? '' ) );
            if ( $label === '' ) {
                continue;
            }

            $wpdb->insert(
                $this->items,
                [
                    'template_id'     => $template_id,
                    'label'           => $label,
                    'quantity_needed' => max( 1, (int) ( $item['quantity_needed'] ?? 1 ) ),
                    'sort_order'      => $order++,
                ],
                [ '%d', '%s', '%d', '%d' ]
            );
        }
    }

    // ------------------------------------------------------------------
    // Vorlage aus bestehendem Termin erzeugen
    // ------------------------------------------------------------------

    /**
     * Sammelt die Posten der angegebenen Art aus einem bestehenden Termin und
     * legt daraus eine Vorlage an. Gleiche Bezeichnungen (auch über mehrere
     * Serien-Instanzen hinweg) werden zusammengefasst; als Bedarfsmenge gilt
     * die höchste vorgefundene Menge.
     *
     * @return int|null  ID der neuen Vorlage, null wenn der Termin nichts hergibt
     */
    public function create_from_event( int $event_id, string $kind, string $name, int $created_by ): ?int {
        global $wpdb;

        if ( ! in_array( $kind, self::KINDS, true ) ) {
            return null;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT label, MAX(quantity_needed) AS quantity_needed, MIN(id) AS first_id
                 FROM {$wpdb->prefix}vp_event_items
                 WHERE event_id = %d AND kind = %s
                 GROUP BY label
                 ORDER BY first_id ASC",
                $event_id,
                $kind
            ),
            ARRAY_A
        ) ?: [];

        if ( empty( $rows ) ) {
            return null;
        }

        return $this->create( [
            'kind'       => $kind,
            'name'       => $name,
            'created_by' => $created_by,
            'items'      => array_map(
                fn( $r ) => [ 'label' => $r['label'], 'quantity_needed' => (int) $r['quantity_needed'] ],
                $rows
            ),
        ] );
    }
}
