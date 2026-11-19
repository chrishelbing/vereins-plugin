<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Core\Activator;
use WP_Error;

/**
 * Zwei Einträge derselben Person zusammenführen – Basis.
 *
 * Anlass: Gäste ohne E-Mail werden beim nächsten Terminlink nicht
 * wiedererkannt, und wer sie aufnimmt, hat dieselbe Person bald zweimal
 * („Franzi“ und „Franzi“). Zusammenführen behält ein Mitglied ($behalten,
 * immer ein aufgenommenes Mitglied) und übernimmt alles vom anderen Eintrag
 * ($weg, Mitglied oder offene Person):
 *
 *   – alles aus den Personentabellen (Zusagen, Mitbringliste, Helfer-
 *     aufgaben, Begleitpersonen, Fotos, Stimmen, Meldungen, Vorschläge –
 *     MemberService::person_tables() samt Modulen über vp_person_tables)
 *   – gemerkte Begleitpersonen; gleiche Namen werden eins, und was an der
 *     wegfallenden Begleitperson hing, hängt danach an der behaltenen
 *   – E-Mail, wenn das behaltene Konto nur eine Ersatzadresse hat
 *   – Telefon, wenn beim behaltenen keins steht
 *   – Orgateam, wenn der wegfallende Eintrag es hatte
 *
 * Doppeltes wird nicht verdoppelt: Hat das behaltene Mitglied zum selben
 * Termin schon zugesagt, gilt seine Antwort, und die des anderen fällt weg.
 * Danach verschwindet der andere Eintrag: eine offene Person ganz, ein
 * Mitglied samt Konto – außer an dem Konto hängen Beiträge oder fremde
 * Rollen; dann bleibt es ohne Vereinsrollen stehen (wie beim Löschen).
 */
class PersonMerge {

    private MemberService $service;
    private MemberModel   $members;
    private PeopleModel   $people;

    public function __construct( ?MemberService $service = null ) {
        $this->service = $service ?? new MemberService();
        $this->members = new MemberModel();
        $this->people  = new PeopleModel();
    }

    /**
     * @param int $behalten WordPress-Nutzer-ID des Mitglieds, das bleibt
     * @param int $weg      Personen-ID des Eintrags, der aufgeht (positiv = Mitglied, negativ = offene Person)
     * @return array{moved:int, dropped:int, email:bool, phone:bool, orga:bool, account_deleted:bool}|WP_Error
     */
    public function merge( int $behalten, int $weg ): array|WP_Error {
        if ( $behalten <= 0 || ! $this->members->find_by_user_id( $behalten ) ) {
            return new WP_Error( 'keep_not_member', __( 'Behalten wird immer ein aufgenommenes Mitglied.', 'vereins-plugin' ) );
        }
        if ( $weg === $behalten ) {
            return new WP_Error( 'same_person', __( 'Ein Eintrag lässt sich nicht mit sich selbst zusammenführen.', 'vereins-plugin' ) );
        }
        $quelle = Person::find( $weg );
        if ( ! $quelle ) {
            return new WP_Error( 'not_found', __( 'Der andere Eintrag existiert nicht mehr.', 'vereins-plugin' ) );
        }
        if ( $weg > 0 && ! $this->service->role_is_changeable( $weg ) ) {
            return new WP_Error( 'protected', __( 'Das eigene Konto und Administratoren können nicht in einem anderen Eintrag aufgehen.', 'vereins-plugin' ) );
        }

        $begleit = $this->merge_companions( $behalten, $weg );
        [ $bewegt, $verworfen ] = $this->move_rows( $behalten, $weg );

        // Stammdaten ergänzen – nie überschreiben, was beim behaltenen steht.
        $ziel_user = get_userdata( $behalten );
        $email     = false;
        $telefon   = false;
        $orga      = false;

        $ihre_mail = (string) ( $quelle['email'] ?? '' );
        if ( $ziel_user && MemberService::is_placeholder_email( (string) $ziel_user->user_email )
             && $ihre_mail !== '' && ! MemberService::is_placeholder_email( $ihre_mail ) ) {
            // Die Adresse ist eindeutig – erst beim wegfallenden Eintrag freimachen.
            if ( $weg > 0 ) {
                wp_update_user( [ 'ID' => $weg, 'user_email' => 'vp-frei-' . $weg . '-' . wp_generate_password( 6, false, false ) . MemberService::PLACEHOLDER_DOMAIN ] );
            } else {
                $this->people->update( Person::guest_id( $weg ), [ 'email' => null ] );
            }
            $andere = get_user_by( 'email', $ihre_mail );
            if ( ! $andere || (int) $andere->ID === $behalten ) {
                wp_update_user( [ 'ID' => $behalten, 'user_email' => $ihre_mail ] );
                $email = true;
            }
        }

        $ziel = $this->members->find_by_user_id( $behalten );
        if ( empty( $ziel['phone'] ) && ! empty( $quelle['phone'] ) ) {
            $this->members->update( (int) $ziel['id'], [ 'phone' => (string) $quelle['phone'] ] );
            $telefon = true;
        }

        $weg_user = $weg > 0 ? get_userdata( $weg ) : null;
        if ( $weg_user && in_array( Activator::ROLE_ORGA, (array) $weg_user->roles, true )
             && $ziel_user && ! in_array( Activator::ROLE_ORGA, (array) $ziel_user->roles, true ) ) {
            $this->service->assign_role( $behalten, Activator::ROLE_ORGA );
            $orga = true;
        }

        // Den anderen Eintrag entfernen. Seine Daten sind inzwischen umgezogen,
        // erase_member()/reject_person() räumen also nur noch Reste ab.
        $konto_weg = false;
        if ( $weg > 0 ) {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'vp_sessions', [ 'wp_user_id' => $weg ], [ '%d' ] );
            $ergebnis  = $this->service->erase_member( $weg );
            $konto_weg = ! is_wp_error( $ergebnis ) && ! empty( $ergebnis['account_deleted'] );
        } else {
            $this->service->reject_person( $weg );
        }

        Person::flush_cache();

        return [
            'moved'           => $bewegt + $begleit,
            'dropped'         => $verworfen,
            'email'           => $email,
            'phone'           => $telefon,
            'orga'            => $orga,
            'account_deleted' => $konto_weg,
        ];
    }

    /**
     * Gemerkte Begleitpersonen: gleiche Namen (ohne Groß/klein) werden eins.
     * Was an der wegfallenden Begleitperson hing (Zusagen, Mitbringliste),
     * hängt danach an der behaltenen. Gibt die Zahl umgezogener Namen zurück.
     */
    private function merge_companions( int $behalten, int $weg ): int {
        global $wpdb;
        $t      = $wpdb->prefix . 'vp_member_companions';
        $eigene = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$t} WHERE person_id = %d", $behalten ), ARRAY_A ) as $r ) {
            $eigene[ mb_strtolower( trim( $r['name'] ) ) ] = (int) $r['id'];
        }

        $umgezogen = 0;
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$t} WHERE person_id = %d", $weg ), ARRAY_A ) as $r ) {
            $schluessel = mb_strtolower( trim( $r['name'] ) );
            if ( isset( $eigene[ $schluessel ] ) ) {
                // Verweise auf die doppelte Begleitperson umbiegen, dann löschen.
                foreach ( [ 'vp_event_item_claims', 'vp_event_companions' ] as $tabelle ) {
                    $this->update_rows( $wpdb->prefix . $tabelle, 'companion_id', (int) $r['id'], $eigene[ $schluessel ] );
                }
                $wpdb->delete( $t, [ 'id' => (int) $r['id'] ], [ '%d' ] );
            } else {
                $wpdb->update( $t, [ 'person_id' => $behalten ], [ 'id' => (int) $r['id'] ], [ '%d' ], [ '%d' ] );
                $eigene[ $schluessel ] = (int) $r['id'];
                $umgezogen++;
            }
        }
        return $umgezogen;
    }

    /**
     * Alle Zeilen der Personentabellen umhängen. Zeile für Zeile, weil eine
     * eindeutige Spalte (etwa „eine Zusage je Person und Termin“) sonst die
     * ganze Abfrage scheitern ließe: Was beim behaltenen Mitglied schon
     * existiert, gewinnt, die doppelte Zeile fällt weg.
     *
     * @return array{0:int, 1:int} [umgezogen, verworfen]
     */
    private function move_rows( int $behalten, int $weg ): array {
        global $wpdb;
        $bewegt    = 0;
        $verworfen = 0;
        foreach ( MemberService::person_tables() as $suffix => $spalte ) {
            if ( $suffix === 'vp_member_companions' ) {
                continue; // schon in merge_companions()
            }
            [ $b, $v ] = $this->update_rows( $wpdb->prefix . $suffix, $spalte, $weg, $behalten );
            $bewegt    += $b;
            $verworfen += $v;
        }
        // Fotos hängen am Hochladenden (Termine-Modul, Spalte wp_user_id).
        if ( $weg > 0 ) {
            [ $b ] = $this->update_rows( $wpdb->prefix . 'vp_event_photos', 'wp_user_id', $weg, $behalten );
            $bewegt += $b;
        }
        return [ $bewegt, $verworfen ];
    }

    /** @return array{0:int, 1:int} [umgezogen, verworfen] */
    private function update_rows( string $tabelle, string $spalte, int $von, int $nach ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tabelle} WHERE {$spalte} = %d", $von ) ) ?: [];
        $bewegt    = 0;
        $verworfen = 0;
        $leise     = $wpdb->suppress_errors( true );
        foreach ( $ids as $id ) {
            $ok = $wpdb->update( $tabelle, [ $spalte => $nach ], [ 'id' => (int) $id ], [ '%d' ], [ '%d' ] );
            if ( $ok === false ) {
                $wpdb->delete( $tabelle, [ 'id' => (int) $id ], [ '%d' ] );
                $verworfen++;
            } else {
                $bewegt++;
            }
        }
        $wpdb->suppress_errors( $leise );
        return [ $bewegt, $verworfen ];
    }
}
