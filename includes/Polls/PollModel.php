<?php

namespace VereinsPlugin\Polls;

/**
 * Datenzugriff für Umfragen, Antwortmöglichkeiten und Stimmen.
 *
 * Einfache Einzelabfragen ohne JOIN – lesbar, an den Indizes entlang und im
 * WordPress-Ersatz der Tests ausführbar.
 */
class PollModel {

    public const KINDS   = [ 'date', 'choice', 'entry', 'text' ];
    public const STATES  = [ 'draft', 'open', 'closed' ];
    public const RESULTS = [ 'always', 'after_close', 'orga', 'nobody' ];

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
        // Altwert „nach eigener Abstimmung“ zählt als „sofort“, altes „geheim“ als „niemand“.
        $results = $data['results'] ?? 'always';
        $results = $results === 'after_vote' ? 'always' : $results;
        $results = ! empty( $data['anonymous'] ) && ! isset( $data['results'] ) ? 'nobody' : $results;
        $results = in_array( $results, self::RESULTS, true ) ? $results : 'always';
        $felder = [
            'kind'           => in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'choice',
            'title'          => (string) ( $data['title'] ?? '' ),
            'description'    => (string) ( $data['description'] ?? '' ),
            'status'         => in_array( $data['status'] ?? '', self::STATES, true ) ? $data['status'] : 'draft',
            'closes_at'      => ! empty( $data['closes_at'] ) ? (string) $data['closes_at'] : null,
            'multiple'       => ! empty( $data['multiple'] ) ? 1 : 0,
            'allow_maybe'    => ! empty( $data['allow_maybe'] ) ? 1 : 0,
            'results'        => $results,
            // „geheim“ ist keine eigene Einstellung mehr, sondern die Stufe „niemand“.
            'anonymous'      => $results === 'nobody' ? 1 : 0,
            'login_required' => ! empty( $data['login_required'] ) ? 1 : 0,
            'event_date'     => ! empty( $data['event_date'] ) ? (string) $data['event_date'] : null,
            'classes'        => isset( $data['classes'] ) ? wp_json_encode( array_values( (array) $data['classes'] ) ) : null,
            'purge_days'     => max( 0, min( 365, (int) ( $data['purge_days'] ?? 30 ) ) ),
            'max_texts'      => max( 1, min( 10, (int) ( $data['max_texts'] ?? 3 ) ) ),
            'dokume'         => ! empty( $data['dokume'] ) ? 1 : 0,
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
        $wpdb->delete( $this->t( 'vp_poll_entries' ), [ 'poll_id' => $id ] );
        $wpdb->delete( $this->t( 'vp_poll_texts' ),   [ 'poll_id' => $id ] );
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

    /** Wie viele Personen haben abgestimmt bzw. gemeldet? */
    public function voter_count( int $poll_id ): int {
        $zeilen = array_merge( $this->votes( $poll_id ), $this->entries( $poll_id ), $this->texts( $poll_id ) );
        return count( array_unique( array_map( static fn( $v ) => (int) $v['person_id'], $zeilen ) ) );
    }

    // ------------------------------------------------------------------
    // Meldungen (Wettkampfmeldung)
    // ------------------------------------------------------------------

    /** Klassen einer Wettkampfmeldung. */
    public static function classes( array $poll ): array {
        $c = json_decode( (string) ( $poll['classes'] ?? '' ), true );
        return is_array( $c ) ? $c : [];
    }

    /** @return array<int, array> alle Meldungen einer Umfrage, in Eingabereihenfolge */
    public function entries( int $poll_id, ?int $person_id = null ): array {
        global $wpdb;
        $rows = $person_id === null
            ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t('vp_poll_entries')} WHERE poll_id = %d ORDER BY id ASC", $poll_id ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t('vp_poll_entries')} WHERE poll_id = %d AND person_id = %d ORDER BY id ASC", $poll_id, $person_id ), ARRAY_A );
        $rows = $rows ?: [];
        usort( $rows, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $rows;
    }

    /**
     * Die Meldung eines Melders ersetzen – wie bei Stimmen gilt sie als
     * Ganzes: wer ändert, schickt alle seine Starter.
     */
    public function replace_entries( int $poll_id, int $person_id, array $rows ): void {
        global $wpdb;
        // Was schon beim Veranstalter gemeldet ist, bleibt es – sofern Starter,
        // Klasse und Gewicht unverändert sind. Sonst ist es eine Nachmeldung.
        $gemeldet = [];
        $info     = [];
        foreach ( $this->entries( $poll_id, $person_id ) as $alt ) {
            if ( ! empty( $alt['submitted_at'] ) ) {
                $gemeldet[ self::entry_key( $alt ) ] = $alt['submitted_at'];
            }
            if ( ! empty( $alt['notified_at'] ) ) {
                $info[ self::entry_key( $alt ) ] = $alt['notified_at'];
            }
        }
        $this->delete_entries( $poll_id, $person_id );
        $jetzt = current_time( 'mysql' );
        foreach ( $rows as $r ) {
            $wpdb->insert( $this->t( 'vp_poll_entries' ), [
                'poll_id'    => $poll_id,
                'person_id'  => $person_id,
                'first_name' => (string) $r['first_name'],
                'last_name'  => (string) $r['last_name'],
                'birth_year' => (int) $r['birth_year'],
                'sex'        => (string) $r['sex'],
                'class_key'  => (string) $r['class_key'],
                'weight'     => (string) $r['weight'],
                'note'       => (string) ( $r['note'] ?? '' ),
                'judopass'   => isset( $r['judopass'] ) && $r['judopass'] !== null ? (int) (bool) $r['judopass'] : null,
                'submitted_at' => $gemeldet[ self::entry_key( $r ) ] ?? null,
                'notified_at'  => $info[ self::entry_key( $r ) ] ?? null,
                'created_at' => $jetzt,
            ] );
        }
    }

    /** Starter + Klasse + Gewicht – was beim Veranstalter als „dieselbe Meldung“ gilt. */
    public static function entry_key( array $e ): string {
        $n = static fn( $t ) => mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $t ) ) );
        return $n( $e['first_name'] ) . '|' . $n( $e['last_name'] ) . '|' . (int) $e['birth_year'] . '|' . $n( $e['class_key'] ) . '|' . $n( $e['weight'] );
    }

    /**
     * Starter als beim Veranstalter gemeldet abhaken (oder wieder lösen).
     *
     * @param int[] $ids
     * @return int[] Personen-IDs der Melder, deren Starter neu abgehakt wurden
     */
    public function mark_entries_submitted( int $poll_id, array $ids, bool $gemeldet = true ): array {
        global $wpdb;
        $melder = [];
        $jetzt  = current_time( 'mysql' );
        foreach ( $this->entries( $poll_id ) as $e ) {
            if ( ! in_array( (int) $e['id'], $ids, true ) ) {
                continue;
            }
            if ( $gemeldet && empty( $e['submitted_at'] ) ) {
                $wpdb->update( $this->t( 'vp_poll_entries' ), [ 'submitted_at' => $jetzt ], [ 'id' => (int) $e['id'] ] );
                $melder[] = (int) $e['person_id'];
            } elseif ( ! $gemeldet && ! empty( $e['submitted_at'] ) ) {
                $wpdb->update( $this->t( 'vp_poll_entries' ), [ 'submitted_at' => null ], [ 'id' => (int) $e['id'] ] );
            }
        }
        return array_values( array_unique( $melder ) );
    }

    /**
     * Melder, die zu mindestens einem gemeldeten Starter noch keine Mail
     * „Meldung abgeschickt“ bekommen haben – egal, ob der Haken über die
     * Abhakliste oder über „Gemeldet“ kam.
     *
     * @return int[] Personen-IDs
     */
    public function melder_to_notify( int $poll_id ): array {
        $ids = [];
        foreach ( $this->entries( $poll_id ) as $e ) {
            if ( ! empty( $e['submitted_at'] ) && empty( $e['notified_at'] ) ) {
                $ids[] = (int) $e['person_id'];
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /** Gemeldete Starter dieser Melder als „Mail bekommen“ vermerken. */
    public function mark_notified( int $poll_id, array $melder ): void {
        global $wpdb;
        $jetzt = current_time( 'mysql' );
        foreach ( $this->entries( $poll_id ) as $e ) {
            if ( in_array( (int) $e['person_id'], $melder, true ) && ! empty( $e['submitted_at'] ) && empty( $e['notified_at'] ) ) {
                $wpdb->update( $this->t( 'vp_poll_entries' ), [ 'notified_at' => $jetzt ], [ 'id' => (int) $e['id'] ] );
            }
        }
    }

    /** Meldung als abgeschickt bzw. bestätigt vermerken ('submitted_at' / 'confirmed_at'). */
    public function mark_poll( int $poll_id, string $feld ): void {
        global $wpdb;
        if ( in_array( $feld, [ 'submitted_at', 'confirmed_at' ], true ) ) {
            $wpdb->update( $this->t( 'vp_polls' ), [ $feld => current_time( 'mysql' ) ], [ 'id' => $poll_id ] );
        }
    }

    public function delete_entries( int $poll_id, ?int $person_id = null ): void {
        global $wpdb;
        $wo = [ 'poll_id' => $poll_id ];
        if ( $person_id !== null ) {
            $wo['person_id'] = $person_id;
        }
        $wpdb->delete( $this->t( 'vp_poll_entries' ), $wo );
    }

    // ------------------------------------------------------------------
    // Vorschläge (Ideensammlung)
    // ------------------------------------------------------------------

    /** @return array<int, array> in Eingabereihenfolge */
    public function texts( int $poll_id, ?int $person_id = null ): array {
        global $wpdb;
        $rows = $person_id === null
            ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t('vp_poll_texts')} WHERE poll_id = %d ORDER BY id ASC", $poll_id ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t('vp_poll_texts')} WHERE poll_id = %d AND person_id = %d ORDER BY id ASC", $poll_id, $person_id ), ARRAY_A );
        $rows = $rows ?: [];
        usort( $rows, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $rows;
    }

    /**
     * Vorschläge einer Person abgleichen. Unveränderte behalten ihre Zeile
     * (und damit ihren Zeitpunkt), entfallene werden gelöscht, neue angelegt.
     *
     * @param string[] $texte
     */
    public function replace_texts( int $poll_id, int $person_id, array $texte ): void {
        global $wpdb;
        $offen = $texte;
        foreach ( $this->texts( $poll_id, $person_id ) as $row ) {
            $i = array_search( $row['text'], $offen, true );
            if ( $i === false ) {
                $wpdb->delete( $this->t( 'vp_poll_texts' ), [ 'id' => (int) $row['id'] ] );
            } else {
                unset( $offen[ $i ] );
            }
        }
        $jetzt = current_time( 'mysql' );
        foreach ( $offen as $text ) {
            $wpdb->insert( $this->t( 'vp_poll_texts' ), [
                'poll_id' => $poll_id, 'person_id' => $person_id, 'text' => $text, 'created_at' => $jetzt,
            ] );
        }
    }

    public function delete_texts( int $poll_id, int $person_id ): void {
        global $wpdb;
        $wpdb->delete( $this->t( 'vp_poll_texts' ), [ 'poll_id' => $poll_id, 'person_id' => $person_id ] );
    }

    /** Einen Vorschlag entfernen (Moderation durch das Orgateam). */
    public function delete_text( int $text_id ): void {
        global $wpdb;
        $wpdb->delete( $this->t( 'vp_poll_texts' ), [ 'id' => $text_id ] );
    }

    public function mark_purged( int $poll_id ): void {
        global $wpdb;
        $wpdb->update( $this->t( 'vp_polls' ), [ 'purged_at' => current_time( 'mysql' ) ], [ 'id' => $poll_id ] );
    }
}
