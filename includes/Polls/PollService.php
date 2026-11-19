<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\SavedNames;
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
        if ( ( $poll['kind'] ?? '' ) === 'entry' ) {
            return new WP_Error( 'bad_kind', __( 'Zu einer Wettkampfmeldung gehören Starter, keine Stimmen.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }
        if ( ! $this->is_open( $poll ) ) {
            return new WP_Error( 'poll_closed', __( 'Diese Umfrage ist beendet.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }
        if ( ! $person_id ) {
            return new WP_Error( 'identify_first', __( 'Bitte gib zuerst an, wer du bist.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }
        if ( ! empty( $poll['login_required'] ) && ( Person::is_guest( $person_id ) || ! Capabilities::is_active_member( $person_id ) ) ) {
            return new WP_Error( 'login_required', __( 'Bei dieser Umfrage stimmen nur angemeldete Mitglieder ab.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        if ( $poll['kind'] === 'text' ) {
            return $this->submit_texts( $poll, $person_id, (array) ( $input['texts'] ?? [] ) );
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

    /** Längste Länge eines Vorschlags (Zeichen). */
    public const TEXT_MAX = 300;

    /**
     * Ideensammlung: die Vorschläge einer Person speichern. Leere Felder
     * fallen weg, doppelte auch; eine leere Liste ist ein Fehler – zum
     * Zurückziehen gibt es „Vorschläge zurückziehen“.
     */
    private function submit_texts( array $poll, int $person_id, array $texte ): true|WP_Error {
        $sauber = [];
        foreach ( $texte as $t ) {
            $t = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $t ) ) );
            if ( $t === '' ) {
                continue;
            }
            if ( mb_strlen( $t ) > self::TEXT_MAX ) {
                return new WP_Error( 'too_long', sprintf( __( 'Ein Vorschlag darf höchstens %d Zeichen lang sein.', 'vereins-plugin' ), self::TEXT_MAX ), [ 'status' => 400 ] );
            }
            if ( ! in_array( mb_strtolower( $t ), array_map( 'mb_strtolower', $sauber ), true ) ) {
                $sauber[] = $t;
            }
        }
        if ( ! $sauber ) {
            return new WP_Error( 'nothing_written', __( 'Bitte schreib mindestens einen Vorschlag.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }
        $max = max( 1, (int) ( $poll['max_texts'] ?? 3 ) );
        if ( count( $sauber ) > $max ) {
            return new WP_Error( 'too_many', sprintf( _n( 'Höchstens %d Vorschlag je Person.', 'Höchstens %d Vorschläge je Person.', $max, 'vereins-plugin' ), $max ), [ 'status' => 400 ] );
        }
        $this->model->replace_texts( (int) $poll['id'], $person_id, $sauber );
        return true;
    }

    /**
     * Ideensammlung: alle Vorschläge mit Namen (außer bei geheimen Umfragen).
     *
     * @return array<int, array{id:int, text:string, name:?string, mine:bool}>
     */
    public function text_results( array $poll, int $person_id = 0 ): array {
        $zeilen = $this->model->texts( (int) $poll['id'] );
        $namen  = empty( $poll['anonymous'] )
            ? Person::names( array_values( array_unique( array_map( static fn( $t ) => (int) $t['person_id'], $zeilen ) ) ) )
            : [];
        return array_map( static fn( $t ) => [
            'id'   => (int) $t['id'],
            'text' => $t['text'],
            'name' => $namen ? ( $namen[ (int) $t['person_id'] ] ?? __( 'Unbekannt', 'vereins-plugin' ) ) : null,
            'mine' => $person_id !== 0 && (int) $t['person_id'] === $person_id,
        ], $zeilen );
    }

    /**
     * Darf diese Person das Ergebnis sehen?
     * Das Orgateam sieht es immer – bei „niemand“ (geheim) nur die Zahlen.
     */
    public function may_see_results( array $poll, int $person_id, bool $has_voted, bool $is_orga ): bool {
        if ( $is_orga ) {
            return true;
        }
        // „nur Orgateam“ und „niemand“: auf der Website sieht es sonst keiner.
        return match ( $poll['results'] ) {
            'always', 'after_vote' => true,
            'after_close'          => $this->is_closed( $poll ),
            default                => false,
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
        if ( $poll['kind'] === 'entry' ) {
            return $this->entry_payload( $poll, $person_id );
        }
        if ( $poll['kind'] === 'text' ) {
            return $this->text_payload( $poll, $person_id, $is_orga );
        }
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

    /** Ideensammlung für die Oberfläche: eigene Vorschläge und – wenn erlaubt – alle. */
    private function text_payload( array $poll, int $person_id, bool $is_orga ): array {
        $meine = $person_id ? array_column( $this->model->texts( (int) $poll['id'], $person_id ), 'text' ) : [];
        $sicht = $this->may_see_results( $poll, $person_id, (bool) $meine, $is_orga );
        return [
            'id'             => (int) $poll['id'],
            'kind'           => 'text',
            'title'          => $poll['title'],
            'description'    => (string) $poll['description'],
            'open'           => $this->is_open( $poll ),
            'closes_at'      => $poll['closes_at'] ?: null,
            'anonymous'      => ! empty( $poll['anonymous'] ),
            'login_required' => ! empty( $poll['login_required'] ),
            'results_rule'   => $poll['results'],
            'voters'         => $this->model->voter_count( (int) $poll['id'] ),
            'max_texts'      => max( 1, (int) ( $poll['max_texts'] ?? 3 ) ),
            'text_max'       => self::TEXT_MAX,
            'mine'           => $meine,
            'results'        => $sicht ? $this->text_results( $poll, $person_id ) : null,
        ];
    }

    /**
     * Wettkampfmeldung für die Oberfläche: Klassen mit Jahrgängen und die
     * eigenen Starter. Fremde Meldungen sieht auf der Website niemand – das
     * Orgateam arbeitet mit der Liste im Backend.
     */
    private function entry_payload( array $poll, int $person_id ): array {
        $meine = $person_id ? $this->model->entries( (int) $poll['id'], $person_id ) : [];

        return [
            'id'             => (int) $poll['id'],
            'kind'           => 'entry',
            'title'          => $poll['title'],
            'description'    => (string) $poll['description'],
            'open'           => $this->is_open( $poll ),
            'closes_at'      => $poll['closes_at'] ?: null,
            'event_date'     => $poll['event_date'] ?: null,
            'year'           => EntryService::year( $poll ),
            'login_required' => ! empty( $poll['login_required'] ),
            'voters'         => $this->model->voter_count( (int) $poll['id'] ),
            'starters'       => count( $this->model->entries( (int) $poll['id'] ) ),
            'classes'        => EntryService::classes_payload( $poll ),
            'entries'        => array_map( static fn( $e ) => [
                'first_name' => $e['first_name'],
                'last_name'  => $e['last_name'],
                'birth_year' => (int) $e['birth_year'],
                'sex'        => $e['sex'],
                'class_key'  => $e['class_key'],
                'weight'     => $e['weight'],
                'note'       => $e['note'],
            ], $meine ),
            'saved_names'    => SavedNames::for_person( $person_id ),
            // Für „Mich selbst melden“ – nie Teil der gemerkten Namen.
            'self_name'      => $person_id ? (string) Person::name( $person_id ) : '',
            'max_rows'       => EntryService::MAX_ROWS,
            'results'        => null,
        ];
    }
}
