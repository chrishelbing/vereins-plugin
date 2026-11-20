<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\SavedNames;
use VereinsPlugin\Support\Capabilities;
use WP_Error;

/**
 * Regeln der Wettkampfmeldung.
 *
 * Ein Melder (Mitglied oder Gast) meldet einen oder mehrere Starter, etwa
 * Geschwister in verschiedenen Klassen. Die Altersklasse folgt aus dem
 * Jahrgang; passen mehrere (z. B. U13 und U15 im selben Jahrgang), wählt der
 * Melder. Die Gewichtsklasse muss zur Klasse und zum Geschlecht passen –
 * bei gewichtsnahen Gruppen entfällt sie.
 *
 * Gäste brauchen eine E-Mail-Adresse oder Telefonnummer: Das Orgateam muss
 * bei Rückfragen jemanden erreichen können.
 */
class EntryService {

    /** Mehr Starter je Melder sind kaum denkbar – und bremsen Unfug. */
    public const MAX_ROWS = 12;

    private PollModel   $model;
    private PollService $polls;

    public function __construct( ?PollModel $model = null ) {
        $this->model = $model ?? new PollModel();
        $this->polls = new PollService( $this->model );
    }

    /** Wettkampfjahr – bestimmt die Jahrgänge. */
    public static function year( array $poll ): int {
        $datum = (string) ( $poll['event_date'] ?? '' );
        return $datum !== '' ? (int) substr( $datum, 0, 4 ) : (int) substr( current_time( 'mysql' ), 0, 4 );
    }

    /**
     * Klassen, in denen jemand dieses Jahrgangs und Geschlechts starten kann.
     *
     * @return array<int, array> Klassen der Umfrage
     */
    public static function eligible( array $poll, int $birth_year, string $sex ): array {
        $alter = self::year( $poll ) - $birth_year;
        return array_values( array_filter(
            PollModel::classes( $poll ),
            static fn( $c ) => $alter >= (int) $c['from'] && $alter <= (int) $c['to'] && str_contains( (string) $c['sex'], $sex )
        ) );
    }

    /** Hat der Melder einen Kontaktweg für Rückfragen? */
    public static function has_contact( int $person_id ): bool {
        if ( $person_id > 0 ) {
            return true; // Mitglied: E-Mail des Kontos
        }
        $p = Person::find( $person_id );
        return $p && ( $p['email'] !== '' || $p['phone'] !== '' );
    }

    /**
     * Meldung prüfen und speichern. Eine leere Liste zieht die Meldung zurück.
     *
     * @param array $rows je Starter: first_name, last_name, birth_year, sex, class_key, weight, note
     */
    public function submit( array $poll, int $person_id, array $rows ): true|WP_Error {
        if ( ( $poll['kind'] ?? '' ) !== 'entry' ) {
            return new WP_Error( 'bad_kind', __( 'Das ist keine Wettkampfmeldung.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }
        if ( ! $this->polls->is_open( $poll ) ) {
            return new WP_Error( 'poll_closed', __( 'Der Meldeschluss ist vorbei.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }
        if ( ! $person_id ) {
            return new WP_Error( 'identify_first', __( 'Bitte gib zuerst an, wer du bist.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }
        if ( ! empty( $poll['login_required'] ) && ( Person::is_guest( $person_id ) || ! Capabilities::is_active_member( $person_id ) ) ) {
            return new WP_Error( 'login_required', __( 'Melden können hier nur angemeldete Mitglieder.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }
        if ( ! self::has_contact( $person_id ) ) {
            return new WP_Error( 'contact_required', __( 'Für Rückfragen brauchen wir eine E-Mail-Adresse oder Telefonnummer von dir.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }
        if ( count( $rows ) > self::MAX_ROWS ) {
            return new WP_Error( 'too_many', sprintf( __( 'Höchstens %d Starter je Meldung.', 'vereins-plugin' ), self::MAX_ROWS ), [ 'status' => 400 ] );
        }

        $sauber = $this->validate_rows( $poll, $person_id, $rows );
        if ( $sauber instanceof WP_Error ) {
            return $sauber;
        }

        $this->model->replace_entries( (int) $poll['id'], $person_id, $sauber );

        // Mit Login: Namen merken – nur den Namen, kein Jahrgang, kein Gewicht.
        if ( $person_id > 0 ) {
            foreach ( $sauber as $s ) {
                SavedNames::remember( $person_id, $s['first_name'] . ' ' . $s['last_name'] );
            }
        }
        return true;
    }

    /**
     * Starter eines Melders prüfen und bereinigen (Name, Jahrgang, Klasse,
     * Gewicht, keine Doppelten, Judopass bei DokuMe). Auch fürs Orgateam,
     * wenn es im Backend einen Starter bearbeitet.
     *
     * @return array|WP_Error bereinigte Zeilen
     */
    public function validate_rows( array $poll, int $person_id, array $rows ): array|WP_Error {
        $jahr    = self::year( $poll );
        $sauber  = [];
        $doppelt = [];

        // Was andere Melder schon gemeldet haben – derselbe Starter darf in
        // einer Klasse nur einmal stehen, auch wenn ihn zwei Leute melden.
        $fremd = [];
        foreach ( $this->model->entries( (int) $poll['id'] ) as $e ) {
            if ( (int) $e['person_id'] !== $person_id ) {
                $fremd[ self::starter_key( $e['first_name'], $e['last_name'], (int) $e['birth_year'], $e['class_key'] ) ] = true;
            }
        }

        foreach ( array_values( $rows ) as $i => $r ) {
            $nr    = $i + 1;
            $vor   = mb_substr( trim( sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ) ), 0, 60 );
            $nach  = mb_substr( trim( sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ) ), 0, 60 );
            $jg    = (int) ( $r['birth_year'] ?? 0 );
            $sex   = (string) ( $r['sex'] ?? '' );
            $key   = (string) ( $r['class_key'] ?? '' );
            $gew   = trim( (string) ( $r['weight'] ?? '' ) );
            $notiz = mb_substr( trim( sanitize_text_field( (string) ( $r['note'] ?? '' ) ) ), 0, 255 );
            $pass  = $r['judopass'] ?? null;

            if ( $vor === '' || $nach === '' ) {
                return self::row_error( $nr, __( 'Bitte Vor- und Nachnamen angeben.', 'vereins-plugin' ) );
            }
            if ( $jg < $jahr - 99 || $jg > $jahr ) {
                return self::row_error( $nr, __( 'Bitte den Jahrgang prüfen.', 'vereins-plugin' ) );
            }
            if ( ! in_array( $sex, [ 'm', 'w' ], true ) ) {
                return self::row_error( $nr, __( 'Bitte männlich oder weiblich wählen.', 'vereins-plugin' ) );
            }

            $passend = self::eligible( $poll, $jg, $sex );
            if ( ! $passend ) {
                return self::row_error( $nr, sprintf( __( 'Für Jahrgang %d ist bei diesem Wettkampf keine Klasse ausgeschrieben.', 'vereins-plugin' ), $jg ) );
            }
            if ( $key === '' && count( $passend ) === 1 ) {
                $key = $passend[0]['key'];
            }
            $klasse = null;
            foreach ( $passend as $c ) {
                if ( $c['key'] === $key ) {
                    $klasse = $c;
                }
            }
            if ( ! $klasse ) {
                return self::row_error( $nr, __( 'Bitte eine passende Altersklasse wählen.', 'vereins-plugin' ) );
            }

            if ( ! empty( $klasse['near'] ) ) {
                $gew = '';
            } elseif ( ! in_array( $gew, (array) $klasse[ $sex ], true ) ) {
                return self::row_error( $nr, __( 'Bitte eine Gewichtsklasse wählen.', 'vereins-plugin' ) );
            }

            // Derselbe Starter in zwei Klassen ist erlaubt (z. B. U18 und
            // U21), zweimal in derselben Klasse nicht.
            $schluessel = self::starter_key( $vor, $nach, $jg, $klasse['key'] );
            if ( isset( $doppelt[ $schluessel ] ) ) {
                return self::row_error( $nr, sprintf( __( '%1$s %2$s steht schon in der Klasse %3$s in dieser Meldung.', 'vereins-plugin' ), $vor, $nach, $klasse['key'] ) );
            }
            if ( isset( $fremd[ $schluessel ] ) ) {
                return self::row_error( $nr, sprintf( __( '%1$s %2$s ist in der Klasse %3$s schon von jemand anderem gemeldet. Bei Fragen wende dich ans Orgateam.', 'vereins-plugin' ), $vor, $nach, $klasse['key'] ) );
            }
            $doppelt[ $schluessel ] = true;

            // Meldung über DokuMe: dort kann nur gemeldet werden, wer einen
            // digitalen Judopass hat – lieber jetzt fragen als beim Eintragen merken.
            $judopass = null;
            if ( ! empty( $poll['dokume'] ) ) {
                if ( ! in_array( (string) $pass, [ '1', '0', 'true', 'false' ], true ) && ! is_bool( $pass ) ) {
                    return self::row_error( $nr, __( 'Bitte angeben, ob ein digitaler Judopass vorhanden ist.', 'vereins-plugin' ) );
                }
                $judopass = in_array( (string) $pass, [ '1', 'true' ], true ) || $pass === true ? 1 : 0;
            }

            $sauber[] = [
                'first_name' => $vor,
                'last_name'  => $nach,
                'birth_year' => $jg,
                'sex'        => $sex,
                'class_key'  => $klasse['key'],
                'weight'     => $gew,
                'note'       => $notiz,
                'judopass'   => $judopass,
            ];
        }
        return $sauber;
    }

    /** Wer ist „derselbe Starter in derselben Klasse“? Name ohne Groß/klein, Jahrgang, Klasse. */
    public static function starter_key( string $vor, string $nach, int $jg, string $klasse ): string {
        $norm = static fn( $t ) => mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $t ) ) );
        return $norm( $vor ) . '|' . $norm( $nach ) . '|' . $jg . '|' . $norm( $klasse );
    }

    private static function row_error( int $nr, string $text ): WP_Error {
        return new WP_Error( 'bad_entry', sprintf( __( 'Starter %1$d: %2$s', 'vereins-plugin' ), $nr, $text ), [ 'status' => 400, 'row' => $nr ] );
    }

    /**
     * Klassen für die Oberfläche – mit Jahrgängen des Wettkampfjahres.
     */
    public static function classes_payload( array $poll ): array {
        $jahr = self::year( $poll );
        return array_map( static function ( $c ) use ( $jahr ) {
            [ $alt, $jung ] = ClassTemplates::years( $c, $jahr );
            return [
                'key'   => $c['key'],
                'years' => ClassTemplates::years_label( $c, $jahr ),
                'from'  => $alt,
                'to'    => $jung,
                'sex'   => $c['sex'],
                'near'  => ! empty( $c['near'] ),
                'm'     => array_values( (array) $c['m'] ),
                'w'     => array_values( (array) $c['w'] ),
            ];
        }, PollModel::classes( $poll ) );
    }

    /**
     * Meldungen fürs Orgateam: nach Klasse, Geschlecht und Gewicht sortiert,
     * je Starter der Melder mit Kontakt.
     *
     * @return array<int, array>
     */
    public function list_for_orga( array $poll, bool $abmeldungen = false ): array {
        $zeilen  = $abmeldungen ? $this->model->withdrawn_entries( (int) $poll['id'] ) : $this->model->entries( (int) $poll['id'] );
        $melder  = [];
        foreach ( array_unique( array_map( static fn( $e ) => (int) $e['person_id'], $zeilen ) ) as $pid ) {
            $melder[ $pid ] = Person::find( $pid ) ?? [ 'name' => __( 'Unbekannt', 'vereins-plugin' ), 'email' => '', 'phone' => '' ];
        }
        foreach ( $zeilen as &$z ) {
            $m = $melder[ (int) $z['person_id'] ];
            $z['reporter']       = $m['name'];
            $z['reporter_guest'] = Person::is_guest( (int) $z['person_id'] );
            $z['contact']        = trim( implode( ' · ', array_filter( [ $m['email'], $m['phone'] ] ) ) );
            // Hat sich der Melder selbst gemeldet?
            $z['is_self']        = SavedNames::normalize( $z['first_name'] . ' ' . $z['last_name'] ) === SavedNames::normalize( (string) $m['name'] );
        }
        unset( $z );

        return $this->sort_for_orga( $poll, $zeilen );
    }

    /** Nach Klasse (Reihenfolge der Vorlage), Geschlecht, Gewicht, Name. */
    public function sort_for_orga( array $poll, array $zeilen ): array {
        $reihe = array_flip( array_map( static fn( $c ) => $c['key'], PollModel::classes( $poll ) ) );
        usort( $zeilen, static function ( $a, $b ) use ( $reihe ) {
            return [ $reihe[ $a['class_key'] ] ?? 99, $a['sex'], self::weight_order( $a['weight'] ), $a['last_name'], $a['first_name'] ]
               <=> [ $reihe[ $b['class_key'] ] ?? 99, $b['sex'], self::weight_order( $b['weight'] ), $b['last_name'], $b['first_name'] ];
        } );
        return $zeilen;
    }

    /** -28 vor -31 vor +55; leer (gewichtsnah) zuletzt. */
    public static function weight_order( string $w ): float {
        if ( $w === '' ) {
            return 10000;
        }
        $n = (float) substr( $w, 1 );
        return $w[0] === '+' ? $n + 0.5 : $n;
    }

    /**
     * CSV für die Meldung beim Ausrichter – Semikolon und BOM, damit Excel
     * Umlaute und Spalten richtig liest.
     */
    public function csv( array $poll, string $verein ): string {
        $kopf = [ 'Nachname', 'Vorname', 'Jahrgang', 'Geschlecht', 'Altersklasse', 'Gewichtsklasse', 'Verein', 'Melder', 'Kontakt', 'Hinweis' ];
        $dokume = ! empty( $poll['dokume'] );
        if ( $dokume ) {
            $kopf[] = 'Judopass';
        }
        $kopf[] = 'Beim Veranstalter gemeldet';
        $out  = "\xEF\xBB\xBF" . self::csv_line( $kopf );
        foreach ( $this->list_for_orga( $poll ) as $z ) {
            $zeile = [
                $z['last_name'], $z['first_name'], $z['birth_year'], $z['sex'] === 'w' ? 'w' : 'm',
                $z['class_key'], $z['weight'] !== '' ? ClassTemplates::weight_label( $z['weight'] ) : 'gewichtsnah',
                $verein, $z['is_self'] ? 'selbst' : $z['reporter'], $z['contact'], $z['note'],
            ];
            if ( $dokume ) {
                $zeile[] = $z['judopass'] === null ? '' : ( (int) $z['judopass'] ? 'ja' : 'nein' );
            }
            $zeile[] = ! empty( $z['submitted_at'] ) ? mysql2date( 'd.m.Y', $z['submitted_at'] ) : '';
            $out .= self::csv_line( $zeile );
        }
        return $out;
    }

    private static function csv_line( array $felder ): string {
        return implode( ';', array_map( static function ( $f ) {
            $f = (string) $f;
            // Formeln in Tabellenprogrammen nicht ausführen lassen.
            if ( $f !== '' && in_array( $f[0], [ '=', '@', "\t", "\r" ], true ) ) {
                $f = "'" . $f;
            }
            return '"' . str_replace( '"', '""', $f ) . '"';
        }, $felder ) ) . "\r\n";
    }
}
