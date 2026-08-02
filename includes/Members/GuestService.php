<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Support\PublicLink;
use WP_Error;

/**
 * Identifikation ohne Login.
 *
 * Wer über einen öffentlichen Terminlink zusagt, soll sich nicht erst
 * registrieren müssen. Nach der Aktion genügt der Name. Die E-Mail ist
 * freiwillig: Sie dient der Benachrichtigung bei Änderungen und der
 * Wiedererkennung beim nächsten Mal. Ist sie bekannt, wird ein bestehender
 * Zugang wiedererkannt, sonst still ein neuer angelegt.
 *
 * Die Rufnummer wird weiterhin unterstützt – die App kann sie mitschicken.
 * Das Formular auf der Website fragt sie mangels Versandweg nicht ab.
 *
 * Wichtig: Es entsteht KEIN WordPress-Nutzer. Wer einmal zu einem Sommerfest
 * zusagt, soll kein Profil in der Nutzerverwaltung hinterlassen. Der Eintrag
 * liegt in vp_people und wird über eine negative Personen-ID adressiert –
 * damit funktionieren Zusagen, Begleitpersonen, Helferaufgaben und
 * Mitbringliste unverändert weiter.
 *
 * Erst wenn der Vorstand die Person aufnimmt, entsteht ein WordPress-Nutzer
 * und die Einträge werden umgehängt. Die Bestätigung per Mail betrifft nur
 * die Kontaktdaten, nicht die Mitgliedschaft.
 */
class GuestService {

    private PeopleModel $model;

    public function __construct() {
        $this->model = new PeopleModel();
    }

    /**
     * Kennung entgegennehmen und Nutzer ermitteln.
     *
     * @param array{name?:string, email?:string, phone?:string} $data  Kontakt optional
     * @param string $confirm_base_url  Seite, auf die der Bestätigungslink zeigt
     * @return array{person_id:int, created:bool, confirmed:bool}|WP_Error
     */
    public function identify( array $data, string $confirm_base_url = '' ): array|WP_Error {
        $name  = trim( sanitize_text_field( $data['name'] ?? '' ) );
        $email = sanitize_email( $data['email'] ?? '' );
        $phone = trim( sanitize_text_field( $data['phone'] ?? '' ) );

        if ( $name === '' ) {
            return new WP_Error( 'no_name', __( 'Bitte gib deinen Namen an.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        // Kontaktdaten sind freiwillig. Ohne sie lässt sich niemand
        // wiedererkennen – der Name allein taugt nicht als Schlüssel, sonst
        // liefen zwei gleichnamige Personen auf denselben Zugang. Es entsteht
        // also jedes Mal ein neuer Eintrag, solange das Cookie fehlt. Das ist
        // der Preis dafür, dass niemand zur Angabe gedrängt wird.
        if ( $email && ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Diese E-Mail-Adresse sieht nicht richtig aus.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        // Ist die Person längst Mitglied, gehört die Zusage dorthin – sonst
        // entstünde ein Doppeleintrag neben dem eigenen Zugang.
        $mitglied = $email ? get_user_by( 'email', $email ) : false;
        if ( $mitglied ) {
            PublicLink::remember_guest( (int) $mitglied->ID );
            return [
                'person_id' => (int) $mitglied->ID,
                'created'   => false,
                'confirmed' => true,
            ];
        }

        $vorhanden = $this->find_existing( $email, $phone );

        if ( $vorhanden ) {
            $this->fill_gaps( $vorhanden, $name, $phone );
            $person_id = Person::from_guest( (int) $vorhanden['id'] );
            PublicLink::remember_guest( $person_id );

            return [
                'person_id' => $person_id,
                'created'   => false,
                'confirmed' => ! empty( $vorhanden['confirmed_at'] ),
            ];
        }

        $guest_id = $this->model->create( [
            'display_name' => $name,
            'email'        => $email,
            'phone'        => $phone ? $this->normalize_phone( $phone ) : '',
            'source'       => 'link',
        ] );

        if ( ! $guest_id ) {
            return new WP_Error( 'create_failed', __( 'Der Eintrag konnte nicht gespeichert werden.', 'vereins-plugin' ), [ 'status' => 500 ] );
        }

        $person_id = Person::from_guest( $guest_id );
        PublicLink::remember_guest( $person_id );

        if ( $email && $confirm_base_url ) {
            $this->send_confirmation( $person_id, $email, $name, $confirm_base_url );
        }

        $this->notify_admin( $name, $email ?: $phone );

        return [ 'person_id' => $person_id, 'created' => true, 'confirmed' => false ];
    }

    /**
     * Bestätigungslink einlösen: Kontaktdaten gelten damit als geprüft.
     * Der Mitgliedsstatus bleibt davon unberührt.
     */
    public function confirm( int $person_id ): bool {
        // Auch Mitglieder können einen Bestätigungslink einlösen – etwa wenn
        // sie zwischenzeitlich aufgenommen wurden.
        if ( ! Person::is_guest( $person_id ) ) {
            $mitglied = ( new MemberModel() )->find_by_user_id( $person_id );
            if ( ! $mitglied ) {
                return false;
            }
            ( new MemberModel() )->update( (int) $mitglied['id'], [ 'confirmed_at' => current_time( 'mysql' ) ] );
            PublicLink::remember_guest( $person_id );
            return true;
        }

        $eintrag = $this->model->find( Person::guest_id( $person_id ) );
        if ( ! $eintrag ) {
            return false;
        }

        $this->model->update( (int) $eintrag['id'], [ 'confirmed_at' => current_time( 'mysql' ) ] );
        PublicLink::remember_guest( $person_id );
        return true;
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * Bestehenden Eintrag über E-Mail oder Rufnummer finden.
     */
    private function find_existing( string $email, string $phone ): ?array {
        if ( $email ) {
            $treffer = $this->model->find_by_email( $email );
            if ( $treffer ) {
                return $treffer;
            }
        }

        if ( $phone ) {
            $treffer = $this->model->find_by_phone( $this->normalize_phone( $phone ) );
            if ( $treffer ) {
                return $treffer;
            }
        }

        return null;
    }

    /**
     * Fehlende Angaben bei einem bekannten Eintrag ergänzen – aber nie
     * vorhandene überschreiben. Wer sich beim zweiten Mal mit Nachnamen
     * einträgt, soll den alten Namen nicht verlieren.
     */
    private function fill_gaps( array $eintrag, string $name, string $phone ): void {
        $update = [];

        if ( empty( $eintrag['display_name'] ) && $name ) {
            $update['display_name'] = $name;
        }
        if ( empty( $eintrag['phone'] ) && $phone ) {
            $update['phone'] = $this->normalize_phone( $phone );
        }

        if ( $update ) {
            $this->model->update( (int) $eintrag['id'], $update );
        }
    }

    /**
     * Rufnummern vereinheitlichen, damit dieselbe Nummer nicht zu mehreren
     * Zugängen führt: alles außer Ziffern und führendem Plus entfernen.
     */
    private function normalize_phone( string $phone ): string {
        $digits = preg_replace( '/[^\d+]/', '', $phone );
        return preg_replace( '/(?!^)\+/', '', (string) $digits );
    }

    private function send_confirmation( int $person_id, string $email, string $name, string $base_url ): void {
        $sender  = Settings::get( 'sender_name' );
        $subject = sprintf( __( '[%s] Bitte bestätige deine Anmeldung', 'vereins-plugin' ), $sender );

        $body = sprintf(
            __(
                "Hallo %s,\n\ndanke für deine Rückmeldung! Deine Zusage ist bereits eingetragen.\n\nDamit wir sicher sein können, dass die Angabe von dir stammt, bestätige bitte einmalig diesen Link:\n\n%s\n\nDanach wirst du beim nächsten Mal automatisch erkannt und musst deinen Namen nicht erneut eingeben.\n\nViele Grüße",
                'vereins-plugin'
            ),
            $name,
            PublicLink::confirm_url( $person_id, $base_url )
        );

        wp_mail( $email, $subject, $body );
    }

    private function notify_admin( string $name, string $contact ): void {
        $to = Settings::get( 'notify_email' );
        if ( ! $to ) {
            return;
        }

        wp_mail(
            $to,
            sprintf( __( '[%s] Neue Rückmeldung über einen Terminlink', 'vereins-plugin' ), Settings::get( 'sender_name' ) ),
            sprintf(
                __( "Hallo,\n\n%s (%s) hat über einen öffentlichen Terminlink zugesagt und wurde als neuer Zugang angelegt.\n\nDer Zugang ist noch nicht freigeschaltet – zu finden im wp-admin unter „Verein → Mitglieder“.", 'vereins-plugin' ),
                $name,
                $contact
            )
        );
    }
}
