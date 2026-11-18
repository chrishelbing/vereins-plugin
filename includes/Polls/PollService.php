<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Members\Person;
use VereinsPlugin\Support\Capabilities;
use WP_Error;

/**
 * Regeln der Umfragen: wer abstimmen darf, was gültig ist und wer welches
 * Ergebnis sieht. Kennt die Basis (Personen, Rollen), nicht das Modul Termine.
 */
class PollService {

    private PollModel $model;

    public function __construct( ?PollModel $model = null ) {
        $this->model = $model ?? new PollModel();
    }

    /** Läuft die Umfrage gerade (offen und Ende nicht erreicht)? */
    public function is_open( array $poll ): bool {
        if ( ( $poll['status'] ?? '' ) !== 'open' ) {
            return false;
        }
        $ende = (string) ( $poll['closes_at'] ?? '' );
        return $ende === '' || $ende > current_time( 'mysql' );
    }

    /** Beendet – geschlossen oder Ende überschritten? */
    public function is_closed( array $poll ): bool {
        return ( $poll['status'] ?? '' ) === 'closed'
            || ( ( $poll['status'] ?? '' ) === 'open' && ! $this->is_open( $poll ) );
    }

    /**
     * Stimmen einer Person prüfen und speichern.
     *
     * Terminumfrage: je Vorschlag 'yes' | 'maybe' | 'no' (vielleicht nur, wenn
     * erlaubt). Nicht genannte Vorschläge gelten als „passt nicht“.
     * Auswahl: Liste der gewählten Antworten – genau eine, oder bei
     * Mehrfachauswahl mindestens eine.
     *
     * @param array $input ['answers' => [option_id => value]] bzw. ['choices' => [option_id, …]]
     */
    public function vote( array $poll, int $person_id, array $input ): true|WP_Error {
        if ( ! $this->is_open( $poll ) ) {
            return new WP_Error( 'poll_closed', __( 'Diese Umfrage ist beendet.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }
        if ( ! $person_id ) {
            return new WP_Error( 'identify_first', __( 'Bitte gib zuerst an, wer du bist.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }
        if ( ! empty( $poll['login_required'] ) && ( Person::is_guest( $person_id ) || ! Capabilities::is_active_member( $person_id ) ) ) {
            return new WP_Error( 'login_required', __( 'Bei dieser Umfrage stimmen nur angemeldete Mitglieder ab.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $gueltig = array_map( 'intval', array_column( $this->model->options( (int) $poll['id'] ), 'id' ) );
        $werte   = [];

        if ( $poll['kind'] === 'date' ) {
            $erlaubt = ! empty( $poll['allow_maybe'] ) ? [ 'yes', 'maybe', 'no' ] : [ 'yes', 'no' ];
            foreach ( (array) ( $input['answers'] ?? [] ) as $option_id => $value ) {
                $option_id = (int) $option_id;
                if ( ! in_array( $option_id, $gueltig, true ) ) {
                    return new WP_Error( 'bad_option', __( 'Diese Antwort gibt es in der Umfrage nicht.', 'vereins-plugin' ), [ 'status' => 400 ] );
                }
                if ( ! in_array( $value, $erlaubt, true ) ) {
                    return new WP_Error( 'bad_value', __( 'Diese Antwort ist hier nicht möglich.', 'vereins-plugin' ), [ 'status' => 400 ] );
                }
                $werte[ $option_id ] = $value;
            }
            foreach ( $gueltig as $option_id ) {
                $werte[ $option_id ] ??= 'no';
            }
        } else {
            $wahl = array_values( array_unique( array_map( 'intval', (array) ( $input['choices'] ?? [] ) ) ) );
            foreach ( $wahl as $option_id ) {
                if ( ! in_array( $option_id, $gueltig, true ) ) {
                    return new WP_Error( 'bad_option', __( 'Diese Antwort gibt es in der Umfrage nicht.', 'vereins-plugin' ), [ 'status' => 400 ] );
                }
            }
            if ( ! $wahl ) {
                return new WP_Error( 'nothing_chosen', __( 'Bitte wähle eine Antwort.', 'vereins-plugin' ), [ 'status' => 400 ] );
            }
            if ( empty( $poll['multiple'] ) && count( $wahl ) > 1 ) {
                return new WP_Error( 'one_only', __( 'Bei dieser Umfrage ist nur eine Antwort möglich.', 'vereins-plugin' ), [ 'status' => 400 ] );
            }
            foreach ( $wahl as $option_id ) {
                $werte[ $option_id ] = 'yes';
            }
        }

        $this->model->replace_votes( (int) $poll['id'], $person_id, $werte );
        return true;
    }

    /**
     * Darf diese Person das Ergebnis sehen?
     * Das Orgateam sieht es immer (bei geheimen Umfragen ohne Namen).
     */
    public function may_see_results( array $poll, int $person_id, bool $has_voted, bool $is_orga ): bool {
        if ( $is_orga ) {
            return true;
        }
        return match ( $poll['results'] ) {
            'always'      => true,
            'after_vote'  => $has_voted || $this->is_closed( $poll ),
            'after_close' => $this->is_closed( $poll ),
            default       => false,
        };
    }

    /**
     * Auswertung: je Antwortmöglichkeit die Zahlen und – außer bei geheimen
     * Umfragen – die Namen.
     *
     * @return array<int, array{yes:int, maybe:int, no:int, names:array{yes:string[], maybe:string[], no:string[]}}>
     */
    public function results( array $poll ): array {
        $optionen = $this->model->options( (int) $poll['id'] );
        $stimmen  = $this->model->votes( (int) $poll['id'] );
        $namen    = empty( $poll['anonymous'] )
            ? Person::names( array_values( array_unique( array_map( static fn( $v ) => (int) $v['person_id'], $stimmen ) ) ) )
            : [];

        $out = [];
        foreach ( $optionen as $o ) {
            $out[ (int) $o['id'] ] = [ 'yes' => 0, 'maybe' => 0, 'no' => 0, 'names' => [ 'yes' => [], 'maybe' => [], 'no' => [] ] ];
        }
        foreach ( $stimmen as $v ) {
            $oid = (int) $v['option_id'];
            if ( ! isset( $out[ $oid ] ) ) {
                continue;
            }
            $wert = $v['value'];
            $out[ $oid ][ $wert ]++;
            if ( $namen ) {
                $out[ $oid ]['names'][ $wert ][] = $namen[ (int) $v['person_id'] ] ?? __( 'Unbekannt', 'vereins-plugin' );
            }
        }
        return $out;
    }

    /**
     * Eine Umfrage so, wie die Oberfläche sie braucht – mit den eigenen
     * Antworten und, wenn erlaubt, dem Ergebnis.
     */
    public function payload( array $poll, int $person_id, bool $is_orga ): array {
        $optionen = $this->model->options( (int) $poll['id'] );
        $meine    = [];
        if ( $person_id ) {
            foreach ( $this->model->votes( (int) $poll['id'] ) as $v ) {
                if ( (int) $v['person_id'] === $person_id ) {
                    $meine[ (int) $v['option_id'] ] = $v['value'];
                }
            }
        }
        $abgestimmt = (bool) $meine;
        $sichtbar   = $this->may_see_results( $poll, $person_id, $abgestimmt, $is_orga );

        return [
            'id'             => (int) $poll['id'],
            'kind'           => $poll['kind'],
            'title'          => $poll['title'],
            'description'    => (string) $poll['description'],
            'open'           => $this->is_open( $poll ),
            'closes_at'      => $poll['closes_at'] ?: null,
            'multiple'       => ! empty( $poll['multiple'] ),
            'allow_maybe'    => ! empty( $poll['allow_maybe'] ),
            'anonymous'      => ! empty( $poll['anonymous'] ),
            'login_required' => ! empty( $poll['login_required'] ),
            'results_rule'   => $poll['results'],
            'voters'         => $this->model->voter_count( (int) $poll['id'] ),
            'options'        => array_map( static fn( $o ) => [
                'id'        => (int) $o['id'],
                'label'     => (string) $o['label'],
                'starts_at' => $o['starts_at'] ?: null,
                'ends_at'   => $o['ends_at'] ?: null,
            ], $optionen ),
            'mine'           => $meine ?: (object) [],
            'results'        => $sichtbar ? $this->results( $poll ) : null,
        ];
    }
}
