<?php

namespace VereinsPlugin\Members;

/**
 * Was eine Person im Verein getan hat: Zu- und Absagen, Mitgebrachtes,
 * Helferaufgaben, Begleitungen und Fotos – je Termininstanz.
 *
 * Gedacht für das Backend (Mitglieder und offene Personen). Anlass war ein
 * Mitglied, das dreimal als Gast über Terminlinks zugesagt hatte und dreimal
 * aufgenommen wurde: Welches Konto „das richtige“ ist, entscheidet sich erst,
 * wenn man sieht, woran jedes hängt.
 *
 * Bewusst einfache Einzelabfragen statt JOINs: Sie sind gut lesbar, laufen
 * gegen die Indizes der Tabellen und lassen sich im WordPress-Ersatz der
 * Tests ausführen. Die Personen-ID folgt der üblichen Verabredung – positiv
 * für Mitglieder (wp_users), negativ für offene Personen (vp_people).
 */
class PersonActivity {

    /**
     * Alles zu einer Person, neueste Instanz zuerst.
     *
     * @return array<int, array{
     *   event_id:int, date:string, title:string, exists:bool,
     *   response:?string, helping:bool, help_task:?string, responded_at:?string,
     *   bring:array<int,string>, help:array<int,string>,
     *   companions:array<int,string>, photos:int
     * }>
     */
    public function for_person( int $person_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $zeilen = [];
        $leer   = static fn( int $e, string $d ): array => [
            'event_id' => $e, 'date' => $d, 'title' => '', 'exists' => false,
            'response' => null, 'helping' => false, 'help_task' => null, 'responded_at' => null,
            'bring' => [], 'help' => [], 'companions' => [], 'photos' => 0,
        ];
        $zeile = static function ( int $e, string $d ) use ( &$zeilen, $leer ): string {
            $k = $e . '|' . $d;
            $zeilen[ $k ] ??= $leer( $e, $d );
            return $k;
        };

        // Zu- und Absagen
        $antworten = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}vp_event_attendance WHERE person_id = %d", $person_id
        ), ARRAY_A ) ?: [];
        foreach ( $antworten as $a ) {
            $k = $zeile( (int) $a['event_id'], (string) $a['instance_date'] );
            $zeilen[ $k ]['response']     = (string) $a['response'];
            $zeilen[ $k ]['helping']      = ! empty( $a['helping'] );
            $zeilen[ $k ]['help_task']    = ( (string) ( $a['help_task'] ?? '' ) ) !== '' ? (string) $a['help_task'] : null;
            $zeilen[ $k ]['responded_at'] = $a['responded_at'] ?? null;
        }

        // Begleitungen bei der Zusage – Name ist mitgespeichert.
        $begleitung = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}vp_event_companions WHERE person_id = %d", $person_id
        ), ARRAY_A ) ?: [];
        $begleit_namen = [];
        foreach ( $begleitung as $b ) {
            $k = $zeile( (int) $b['event_id'], (string) $b['instance_date'] );
            $zeilen[ $k ]['companions'][] = (string) $b['name'];
            $begleit_namen[ (int) $b['companion_id'] ] = (string) $b['name'];
        }

        // Übernommene Posten – Mitbringliste und Helferaufgaben.
        $uebernahmen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}vp_event_item_claims WHERE person_id = %d", $person_id
        ), ARRAY_A ) ?: [];
        $posten = $this->items_by_id( array_map( static fn( $u ) => (int) $u['item_id'], $uebernahmen ) );
        foreach ( $uebernahmen as $u ) {
            $item = $posten[ (int) $u['item_id'] ] ?? null;
            if ( ! $item ) {
                continue;
            }
            $k     = $zeile( (int) $item['event_id'], (string) $item['instance_date'] );
            $text  = (string) $item['label'];
            $menge = (int) ( $u['quantity'] ?? 1 );
            if ( $menge > 1 ) {
                $text = $menge . '× ' . $text;
            }
            if ( ! empty( $u['note'] ) ) {
                $text .= ' („' . $u['note'] . '“)';
            }
            $wer = (int) ( $u['companion_id'] ?? 0 );
            if ( $wer && isset( $begleit_namen[ $wer ] ) ) {
                $text .= ' – ' . $begleit_namen[ $wer ];
            }
            $art = ( $item['kind'] ?? 'bring' ) === 'help' ? 'help' : 'bring';
            $zeilen[ $k ][ $art ][] = $text;
        }

        // Fotos gibt es nur von Mitgliedern.
        if ( $person_id > 0 ) {
            $fotos = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}vp_event_photos WHERE wp_user_id = %d", $person_id
            ), ARRAY_A ) ?: [];
            foreach ( $fotos as $f ) {
                $k = $zeile( (int) $f['event_id'], (string) $f['instance_date'] );
                $zeilen[ $k ]['photos']++;
            }
        }

        // Titel nachschlagen – ein gelöschter Termin bleibt als Zeile stehen.
        $titel = $this->titles( array_unique( array_map( static fn( $z ) => (int) $z['event_id'], $zeilen ) ) );
        foreach ( $zeilen as &$z ) {
            $z['exists'] = isset( $titel[ $z['event_id'] ] );
            $z['title']  = $titel[ $z['event_id'] ] ?? __( '(Termin gelöscht)', 'vereins-plugin' );
        }
        unset( $z );

        usort( $zeilen, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) ?: ( $b['event_id'] <=> $a['event_id'] ) );

        return $zeilen;
    }

    /**
     * Kurzfassung für die Tabellen: je Person Zusagen, Absagen, Posten und
     * die letzte Antwort.
     *
     * @param  int[] $person_ids
     * @return array<int, array{yes:int, no:int, items:int, last:?string}>
     */
    public function summaries( array $person_ids ): array {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = array_values( array_unique( array_map( 'intval', $person_ids ) ) );
        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = [ 'yes' => 0, 'no' => 0, 'items' => 0, 'last' => null ];
        }
        if ( ! $ids ) {
            return $out;
        }

        $liste = implode( ',', $ids ); // nur Ganzzahlen, siehe intval oben

        foreach ( $wpdb->get_results( "SELECT * FROM {$p}vp_event_attendance WHERE person_id IN ($liste)", ARRAY_A ) ?: [] as $a ) {
            $id = (int) $a['person_id'];
            if ( ! isset( $out[ $id ] ) ) {
                continue;
            }
            $a['response'] === 'yes' ? $out[ $id ]['yes']++ : $out[ $id ]['no']++;
            $wann = (string) ( $a['responded_at'] ?? '' );
            if ( $wann !== '' && ( $out[ $id ]['last'] === null || $wann > $out[ $id ]['last'] ) ) {
                $out[ $id ]['last'] = $wann;
            }
        }

        foreach ( $wpdb->get_results( "SELECT * FROM {$p}vp_event_item_claims WHERE person_id IN ($liste)", ARRAY_A ) ?: [] as $u ) {
            $id = (int) $u['person_id'];
            if ( isset( $out[ $id ] ) ) {
                $out[ $id ]['items']++;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    /** @return array<int, array> */
    private function items_by_id( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $liste = implode( ',', $ids );
        $out   = [];
        foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vp_event_items WHERE id IN ($liste)", ARRAY_A ) ?: [] as $i ) {
            $out[ (int) $i['id'] ] = $i;
        }
        return $out;
    }

    /** @return array<int, string> */
    private function titles( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return [];
        }
        $liste = implode( ',', $ids );
        $out   = [];
        foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vp_events WHERE id IN ($liste)", ARRAY_A ) ?: [] as $e ) {
            $out[ (int) $e['id'] ] = (string) $e['title'];
        }
        return $out;
    }
}
