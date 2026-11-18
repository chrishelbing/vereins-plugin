<?php

namespace VereinsPlugin\Polls;

/**
 * Datenzugriff für Umfragen, Antwortmöglichkeiten und Stimmen.
 *
 * Einfache Einzelabfragen ohne JOIN – lesbar, an den Indizes entlang und im
 * WordPress-Ersatz der Tests ausführbar.
 */
class PollModel {

    public const KINDS   = [ 'date', 'choice' ];
    public const STATES  = [ 'draft', 'open', 'closed' ];
    public const RESULTS = [ 'always', 'after_vote', 'after_close', 'orga' ];

    private function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ------------------------------------------------------------------
    // Umfragen
    // ------------------------------------------------------------------

    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t('vp_polls')} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    /** @return array<int, array> neueste zuerst */
    public function all( ?string $status = null ): array {
        global $wpdb;
        $rows = $status
            ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t('vp_polls')} WHERE status = %s ORDER BY id DESC", $status ), ARRAY_A )
            : $wpdb->get_results( "SELECT * FROM {$this->t('vp_polls')} ORDER BY id DESC", ARRAY_A );
        $rows = $rows ?: [];
        usort( $rows, static fn( $a, $b ) => (int) $b['id'] <=> (int) $a['id'] );
        return $rows;
    }

    /** Anlegen (ohne id) oder ändern (mit id). Gibt die ID zurück. */
    public function save( array $data ): int {
        global $wpdb;
        $felder = [
            'kind'           => in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'choice',
            'title'          => (string) ( $data['title'] ?? '' ),
            'description'    => (string) ( $data['description'] ?? '' ),
            'status'         => in_array( $data['status'] ?? '', self::STATES, true ) ? $data['status'] : 'draft',
            'closes_at'      => ! empty( $data['closes_at'] ) ? (string) $data['closes_at'] : null,
            'multiple'       => ! empty( $data['multiple'] ) ? 1 : 0,
            'allow_maybe'    => ! empty( $data['allow_maybe'] ) ? 1 : 0,
            'anonymous'      => ! empty( $data['anonymous'] ) ? 1 : 0,
            'results'        => in_array( $data['results'] ?? '', self::RESULTS, true ) ? $data['results'] : 'after_vote',
            'login_required' => ! empty( $data['login_required'] ) ? 1 : 0,
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $this->t( 'vp_polls' ), $felder, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        }

        $felder['created_by'] = (int) ( $data['created_by'] ?? 0 );
        $felder['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $this->t( 'vp_polls' ), $felder );
        return (int) $wpdb->insert_id;
    }

    public function set_status( int $id, string $status ): void {
        global $wpdb;
        if ( in_array( $status, self::STATES, true ) ) {
            $wpdb->update( $this->t( 'vp_polls' ), [ 'status' => $status ], [ 'id' => $id ] );
        }
    }

    public function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( $this->t( 'vp_poll_votes' ),   [ 'poll_id' => $id ] );
        $wpdb->delete( $this->t( 'vp_poll_options' ), [ 'poll_id' => $id ] );
        $wpdb->delete( $this->t( 'vp_polls' ),        [ 'id' => $id ] );
    }

    // ------------------------------------------------------------------
    // Antwortmöglichkeiten
    // ------------------------------------------------------------------

    /** @return array<int, array> in Anzeigereihenfolge */
    public function options( int $poll_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->t('vp_poll_options')} WHERE poll_id = %d ORDER BY sort_order ASC, id ASC", $poll_id
        ), ARRAY_A ) ?: [];
        usort( $rows, static fn( $a, $b ) => [ (int) $a['sort_order'], (int) $a['id'] ] <=> [ (int) $b['sort_order'], (int) $b['id'] ] );
        return $rows;
    }

    /**
     * Antwortmöglichkeiten abgleichen. Einträge mit id werden geändert, ohne
     * id neu angelegt; was fehlt, wird samt seinen Stimmen entfernt. So
     * bleiben Stimmen erhalten, wenn nur ein Tippfehler korrigiert wird.
     *
     * @param array<int, array{id?:int, label?:string, starts_at?:?string, ends_at?:?string}> $options
     */
    public function save_options( int $poll_id, array $options ): void {
        global $wpdb;
        $vorhanden = array_column( $this->options( $poll_id ), null, 'id' );
        $behalten  = [];

        foreach ( array_values( $options ) as $i => $o ) {
            $felder = [
                'label'      => (string) ( $o['label'] ?? '' ),
                'starts_at'  => ! empty( $o['starts_at'] ) ? (string) $o['starts_at'] : null,
                'ends_at'    => ! empty( $o['ends_at'] ) ? (string) $o['ends_at'] : null,
                'sort_order' => $i,
            ];
            $id = (int) ( $o['id'] ?? 0 );
            if ( $id && isset( $vorhanden[ $id ] ) ) {
                $wpdb->update( $this->t( 'vp_poll_options' ), $felder, [ 'id' => $id ] );
                $behalten[] = $id;
            } else {
                $wpdb->insert( $this->t( 'vp_poll_options' ), $felder + [ 'poll_id' => $poll_id ] );
                $behalten[] = (int) $wpdb->insert_id;
            }
        }

        foreach ( array_keys( $vorhanden ) as $id ) {
            if ( ! in_array( (int) $id, $behalten, true ) ) {
                $wpdb->delete( $this->t( 'vp_poll_votes' ),   [ 'option_id' => (int) $id ] );
                $wpdb->delete( $this->t( 'vp_poll_options' ), [ 'id' => (int) $id ] );
            }
        }
    }

    // ------------------------------------------------------------------
    // Stimmen
    // ------------------------------------------------------------------

    /** @return array<int, array> alle Stimmen einer Umfrage */
    public function votes( int $poll_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->t('vp_poll_votes')} WHERE poll_id = %d", $poll_id
        ), ARRAY_A ) ?: [];
    }

    /**
     * Die Stimmen einer Person ersetzen – eine Abstimmung gilt immer als
     * Ganzes, wer nachträglich ändert, schickt seine vollständige Auswahl.
     *
     * @param array<int, string> $values option_id => 'yes'|'maybe'|'no'
     */
    public function replace_votes( int $poll_id, int $person_id, array $values ): void {
        global $wpdb;
        $wpdb->delete( $this->t( 'vp_poll_votes' ), [ 'poll_id' => $poll_id, 'person_id' => $person_id ] );
        $jetzt = current_time( 'mysql' );
        foreach ( $values as $option_id => $value ) {
            $wpdb->insert( $this->t( 'vp_poll_votes' ), [
                'poll_id'   => $poll_id,
                'option_id' => (int) $option_id,
                'person_id' => $person_id,
                'value'     => $value,
                'voted_at'  => $jetzt,
            ] );
        }
    }

    public function delete_votes( int $poll_id, int $person_id ): void {
        global $wpdb;
        $wpdb->delete( $this->t( 'vp_poll_votes' ), [ 'poll_id' => $poll_id, 'person_id' => $person_id ] );
    }

    /** Wie viele Personen haben abgestimmt? */
    public function voter_count( int $poll_id ): int {
        return count( array_unique( array_map( static fn( $v ) => (int) $v['person_id'], $this->votes( $poll_id ) ) ) );
    }
}
