<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Photos\PhotoModel;
use WP_Error;
use WP_User;

/**
 * Fachlogik für Mitgliederverwaltung: Registrierung, Freischaltung,
 * Statuswechsel, Rollenzuweisung.
 */
class MemberService {

    /**
     * Tabellen mit Personen-IDs und der jeweiligen Spalte. Zentral gehalten,
     * damit Aufnehmen, Ablehnen und die Bestandsübernahme dieselbe Liste
     * verwenden und keine Tabelle vergessen wird.
     */
    /** Rollen, die sich über die Mitgliederverwaltung vergeben lassen. */
    private const ROLES = [ Activator::ROLE_MEMBER, Activator::ROLE_ORGA ];

    private const PERSON_TABLES = [
        'vp_event_attendance'  => 'person_id',
        'vp_event_item_claims' => 'person_id',
        'vp_member_companions' => 'person_id',
        'vp_event_companions'  => 'person_id',
        'vp_event_items'       => 'created_by',
    ];

    private MemberModel $model;

    public function __construct() {
        $this->model = new MemberModel();
    }

    // ------------------------------------------------------------------
    // Selbstregistrierung
    // ------------------------------------------------------------------

    /**
     * Selbstregistrierung über [vp_register].
     *
     * Solange eine Freigabe nötig ist, entsteht KEIN WordPress-Nutzer – der
     * Eintrag liegt in vp_people. Erst die Aufnahme durch den Verein erzeugt
     * den Zugang. Damit bleibt die Nutzerverwaltung frei von Leuten, die sich
     * nur einmal umgesehen haben.
     *
     * Ist die Freigabe abgeschaltet, wird direkt ein Mitglied angelegt.
     *
     * @param array{display_name:string,email:string,phone?:string} $data
     * @return array{status: string, member?: array, person_id?: int}|WP_Error
     */
    public function register( array $data ): array|WP_Error {
        $email = sanitize_email( $data['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'vereins-plugin' ) );
        }

        if ( get_user_by( 'email', $email ) ) {
            return new WP_Error( 'email_exists', __( 'Für diese E-Mail-Adresse existiert bereits ein Zugang.', 'vereins-plugin' ) );
        }

        $display_name = sanitize_text_field( $data['display_name'] ?? '' );
        $phone        = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : null;

        if ( ! (bool) Settings::get( 'require_approval', true ) ) {
            $result = $this->create_by_admin( [
                'display_name' => $display_name,
                'email'        => $email,
                'phone'        => $phone,
            ] );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $this->notify_admin_of_registration( $display_name, $email );
            $this->notify_user_of_registration_received( $email, $display_name, 'active' );

            return [ 'member' => $result['member'], 'status' => 'active' ];
        }

        $people = new PeopleModel();

        // Wer schon über einen Terminlink zugesagt hat, bekommt keinen zweiten
        // Eintrag – die Registrierung ergänzt den vorhandenen.
        $vorhanden = $people->find_by_email( $email );
        if ( $vorhanden ) {
            $people->update( (int) $vorhanden['id'], [
                'display_name' => $display_name ?: $vorhanden['display_name'],
                'phone'        => $phone ?: $vorhanden['phone'],
            ] );
            $guest_id = (int) $vorhanden['id'];
        } else {
            $guest_id = $people->create( [
                'display_name' => $display_name ?: $email,
                'email'        => $email,
                'phone'        => $phone,
                'source'       => 'registration',
            ] );
        }

        if ( ! $guest_id ) {
            return new WP_Error( 'create_failed', __( 'Die Anmeldung konnte nicht gespeichert werden.', 'vereins-plugin' ) );
        }

        $this->notify_admin_of_registration( $display_name, $email );
        $this->notify_user_of_registration_received( $email, $display_name, 'pending' );

        return [ 'status' => 'pending', 'person_id' => Person::from_guest( $guest_id ) ];
    }

    /**
     * Nimmt eine Person aus vp_people in den Verein auf.
     *
     * Dabei entsteht der WordPress-Nutzer, und alle bisherigen Einträge –
     * Zusagen, übernommene Aufgaben, Begleitpersonen – wandern von der
     * negativen Personen-ID auf die neue Nutzer-ID. Nichts geht verloren:
     * Wer als Gast zum Sommerfest zugesagt hat, findet die Zusage nach der
     * Aufnahme unverändert vor.
     *
     * @return array{member: array, wp_user_id: int}|WP_Error
     */
    public function accept_person( int $person_id ): array|WP_Error {
        if ( ! Person::is_guest( $person_id ) ) {
            return new WP_Error( 'not_a_person', __( 'Dieser Eintrag ist bereits ein Mitglied.', 'vereins-plugin' ) );
        }

        $people = new PeopleModel();
        $person = $people->find( Person::guest_id( $person_id ) );

        if ( ! $person ) {
            return new WP_Error( 'not_found', __( 'Eintrag nicht gefunden.', 'vereins-plugin' ) );
        }

        $email = (string) ( $person['email'] ?? '' );

        // Ohne E-Mail braucht WordPress trotzdem eine. Die Platzhalteradresse
        // wird nie angeschrieben; sie fällt weg, sobald jemand eine echte
        // Adresse nachträgt.
        $login = $email ?: 'vp-' . wp_generate_password( 12, false, false );
        $mail  = $email ?: $login . '@invalid.local';

        // Gibt es zu der Adresse längst einen WordPress-Nutzer – etwa den
        // Redakteur der Vereinsseite –, wird er verknüpft statt ein zweites
        // Konto anzulegen.
        $vorhanden = $email ? get_user_by( 'email', $email ) : false;
        if ( $vorhanden ) {
            $user_id = (int) $vorhanden->ID;

            if ( ! $this->model->find_by_user_id( $user_id ) ) {
                $this->model->create( [
                    'wp_user_id'   => $user_id,
                    'display_name' => (string) $person['display_name'],
                    'phone'        => $person['phone'] ?: null,
                    'status'       => 'active',
                ] );
            }

            if ( ! Capabilities::is_admin_user( $user_id ) ) {
                $this->assign_role( $user_id, Activator::ROLE_MEMBER );
            }

            $this->repoint_person( $person_id, $user_id );
            $people->delete( Person::guest_id( $person_id ) );
            Person::flush_cache();

            $mitglied = $this->model->find_by_user_id( $user_id );
            return [ 'member' => $mitglied, 'wp_user_id' => $user_id ];
        }

        $user_id = wp_insert_user( [
            'user_login'   => $login,
            'user_email'   => $mail,
            'user_pass'    => wp_generate_password( 32 ),
            'display_name' => $person['display_name'] ?: $login,
            'role'         => Activator::ROLE_MEMBER,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $member_id = $this->model->create( [
            'wp_user_id'   => (int) $user_id,
            'display_name' => (string) $person['display_name'],
            'phone'        => $person['phone'] ?: null,
            'status'       => 'active',
        ] );

        if ( ! empty( $person['confirmed_at'] ) ) {
            $this->model->update( $member_id, [ 'confirmed_at' => $person['confirmed_at'] ] );
        }

        $this->repoint_person( $person_id, (int) $user_id );
        $people->delete( Person::guest_id( $person_id ) );
        Person::flush_cache();

        $this->notify_user_of_approval( (int) $user_id );

        return [ 'member' => $this->model->find( $member_id ), 'wp_user_id' => (int) $user_id ];
    }

    /**
     * Lehnt eine Person ab: Eintrag und alle daran hängenden Zusagen fallen weg.
     */
    public function reject_person( int $person_id ): bool {
        if ( ! Person::is_guest( $person_id ) ) {
            return false;
        }

        global $wpdb;

        foreach ( self::PERSON_TABLES as $suffix => $spalte ) {
            $wpdb->delete( $wpdb->prefix . $suffix, [ $spalte => $person_id ], [ '%d' ] );
        }

        $ok = ( new PeopleModel() )->delete( Person::guest_id( $person_id ) );
        Person::flush_cache();

        return $ok;
    }

    /**
     * Löscht ein aufgenommenes Mitglied vollständig – Recht auf Löschung.
     *
     * Anders als reject_person() (für Gäste ohne WordPress-Profil) muss hier
     * entschieden werden, was mit dem Konto selbst passiert. Vereinsdaten –
     * Zusagen, übernommene Aufgaben, Begleitpersonen, Fotos – werden immer
     * entfernt. Das WordPress-Konto fällt nur dann mit weg, wenn es
     * ausschließlich Vereinsrollen trägt und keine eigenen Inhalte oder
     * Kommentare an ihm hängen. Sonst bliebe von einem Redakteur, der auch
     * im Verein war, kein Zugang zur Website übrig – ein Verlust, den das
     * Plugin an keiner anderen Stelle zulässt (siehe assign_role()).
     *
     * Trägt das Konto daneben eine Rolle der Website oder eigene Beiträge,
     * bleibt es bestehen; ihm werden lediglich die Vereinsrollen entzogen.
     *
     * @return array{account_deleted: bool}|WP_Error
     */
    public function erase_member( int $wp_user_id ): array|WP_Error {
        $member = $this->model->find_by_user_id( $wp_user_id );
        if ( ! $member ) {
            return new WP_Error( 'not_found', __( 'Dieses Mitglied existiert nicht.', 'vereins-plugin' ) );
        }

        if ( ! $this->role_is_changeable( $wp_user_id ) ) {
            return new WP_Error( 'protected', __( 'Das eigene Konto und Administratoren lassen sich hier nicht löschen.', 'vereins-plugin' ) );
        }

        global $wpdb;

        foreach ( self::PERSON_TABLES as $suffix => $spalte ) {
            $wpdb->delete( $wpdb->prefix . $suffix, [ $spalte => $wp_user_id ], [ '%d' ] );
        }

        ( new PhotoModel() )->delete_for_user( $wp_user_id );

        $this->model->delete( (int) $member['id'] );

        $account_deleted = false;

        if ( $this->safe_to_delete_account( $wp_user_id ) ) {
            if ( ! function_exists( 'wp_delete_user' ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user( $wp_user_id );
            $account_deleted = true;
        } else {
            $user = get_userdata( $wp_user_id );
            if ( $user ) {
                foreach ( self::ROLES as $rolle ) {
                    if ( in_array( $rolle, (array) $user->roles, true ) ) {
                        $user->remove_role( $rolle );
                    }
                }
            }
        }

        Person::flush_cache();

        return [ 'account_deleted' => $account_deleted ];
    }

    /**
     * Darf das WordPress-Konto beim Löschen mit entfernt werden?
     *
     * Bewusst streng, nach demselben Maßstab wie PeopleMigration::is_safe_to_move():
     * Sobald irgendetwas an dem Konto hängt, das nicht dem Verein gehört,
     * bleibt es stehen. Ein stehen gelassenes Konto ist ein Schönheitsfehler,
     * ein mitgelöschter Redakteursbeitrag ein Datenverlust.
     */
    private function safe_to_delete_account( int $wp_user_id ): bool {
        global $wpdb;

        if ( ! Capabilities::is_club_only( $wp_user_id ) ) {
            return false;
        }

        $inhalte = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d",
            $wp_user_id
        ) );
        if ( $inhalte > 0 ) {
            return false;
        }

        $kommentare = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d",
            $wp_user_id
        ) );

        return $kommentare === 0;
    }

    /**
     * Hängt alle Einträge einer Person auf eine andere ID um.
     */
    private function repoint_person( int $von, int $nach ): void {
        global $wpdb;

        foreach ( self::PERSON_TABLES as $suffix => $spalte ) {
            $wpdb->update(
                $wpdb->prefix . $suffix,
                [ $spalte => $nach ],
                [ $spalte => $von ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }

    // ------------------------------------------------------------------
    // Manuell durch Admin anlegen (sofort aktiv)
    // ------------------------------------------------------------------

    public function create_by_admin( array $data ): array|WP_Error {
        $email = sanitize_email( $data['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'vereins-plugin' ) );
        }
        if ( get_user_by( 'email', $email ) ) {
            return new WP_Error( 'email_exists', __( 'Für diese E-Mail-Adresse existiert bereits ein Zugang.', 'vereins-plugin' ) );
        }

        $display_name = sanitize_text_field( $data['display_name'] ?? '' );
        $phone        = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : null;
        $role         = in_array( $data['role'] ?? '', self::ROLES, true ) ? $data['role'] : Activator::ROLE_MEMBER;

        $user_id = wp_insert_user( [
            'user_login'   => $email,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 32 ),
            'display_name' => $display_name ?: $email,
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $member_id = $this->model->create( [
            'wp_user_id'   => $user_id,
            'display_name' => $display_name,
            'phone'        => $phone,
            'status'       => 'active',
        ] );

        return [ 'member' => $this->model->find( $member_id ) ];
    }

    // ------------------------------------------------------------------
    // Status & Rolle
    // ------------------------------------------------------------------

    public function approve( int $member_id ): bool {
        $member = $this->model->find( $member_id );
        if ( ! $member ) {
            return false;
        }
        $ok = $this->set_status( $member_id, 'active' );
        if ( $ok ) {
            $this->notify_user_of_approval( (int) $member['wp_user_id'] );
        }
        return $ok;
    }

    /**
     * Mitgliedsstatus setzen – und die Vereinsrolle mitziehen.
     *
     * Status und Rolle beschreiben dieselbe Sache aus zwei Blickwinkeln:
     * „Gehört zum Verein“ einerseits, die Rechte im WordPress andererseits.
     * Liefen sie auseinander, stünde in der Liste „aktiv“ neben jemandem
     * ohne jede Vereinsrolle – ein Zustand, den niemand deuten kann.
     *
     * Deshalb: Aktivieren vergibt die Mitgliedsrolle, sofern noch keine
     * Vereinsrolle vorhanden ist. Deaktivieren nimmt sie wieder weg.
     * Rollen der Website bleiben davon unberührt.
     */
    public function set_status( int $member_id, string $status ): bool {
        if ( ! in_array( $status, [ 'active', 'inactive', 'pending' ], true ) ) {
            return false;
        }

        $member = $this->model->find( $member_id );
        if ( ! $member ) {
            return false;
        }

        $ok = $this->model->update( $member_id, [ 'status' => $status ] );
        if ( $ok ) {
            $this->sync_club_role( (int) $member['wp_user_id'], $status );
        }

        return $ok;
    }

    /**
     * Bringt die Vereinsrolle mit dem Mitgliedsstatus in Einklang.
     *
     * Administratoren bleiben außen vor: Sie dürfen ohnehin alles, und ihre
     * Rollen werden hier grundsätzlich nicht angefasst.
     */
    private function sync_club_role( int $wp_user_id, string $status ): void {
        $user = get_userdata( $wp_user_id );
        if ( ! $user || Capabilities::is_admin_user( $wp_user_id ) ) {
            return;
        }

        $vorhanden = array_intersect( (array) $user->roles, self::ROLES );

        if ( $status === 'active' ) {
            if ( ! $vorhanden ) {
                $user->add_role( Activator::ROLE_MEMBER );
            }
            return;
        }

        foreach ( $vorhanden as $rolle ) {
            $user->remove_role( $rolle );
        }
    }

    /**
     * Vereinsrolle zuweisen.
     *
     * Achtung: set_role() ersetzt sämtliche Rollen. Deshalb sind zwei Fälle
     * ausdrücklich gesperrt, sonst entzieht man sich hier versehentlich die
     * eigenen Administratorrechte und sperrt sich aus dem Backend aus:
     *   - Administratoren werden nie herabgestuft
     *   - das eigene Konto wird nie verändert
     */
    /**
     * Setzt die Vereinsrolle – und zwar ZUSÄTZLICH zu allem, was der Nutzer
     * sonst ist.
     *
     * Früher stand hier set_role(), das alle Rollen ersetzt. Damit verlor ein
     * Redakteur, den man ins Orgateam holte, still seine Redakteursrolle.
     * WordPress kann sehr wohl mehrere Rollen je Nutzer – nur die Oberfläche
     * zeigt lediglich eine Auswahl, weshalb es unmöglich wirkt.
     *
     * Angefasst werden ausschließlich die beiden Vereinsrollen; alles andere
     * bleibt unberührt.
     */
    public function assign_role( int $wp_user_id, string $role ): bool {
        if ( ! in_array( $role, self::ROLES, true ) ) {
            return false;
        }

        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return false;
        }

        if ( ! $this->role_is_changeable( $wp_user_id ) ) {
            return false;
        }

        foreach ( self::ROLES as $vereinsrolle ) {
            if ( $vereinsrolle !== $role && in_array( $vereinsrolle, (array) $user->roles, true ) ) {
                $user->remove_role( $vereinsrolle );
            }
        }

        if ( ! in_array( $role, (array) $user->roles, true ) ) {
            $user->add_role( $role );
        }

        return true;
    }

    /**
     * Setzt die Rollen eines Mitglieds auf genau die übergebene Auswahl.
     *
     * Anders als assign_role() nicht auf die Vereinsrollen beschränkt: Die
     * Mitgliederverwaltung soll alles zeigen und ändern können, was ein
     * Mensch auf dieser Seite ist – sonst muss man zwischen zwei Oberflächen
     * hin- und herspringen.
     *
     * Drei Sicherungen:
     *   1. Angeboten wird nur, was WordPress selbst zur Vergabe freigibt
     *      (get_editable_roles, worauf auch Sicherheits-Plugins hören).
     *   2. Das eigene Konto und Administratoren bleiben gesperrt – sonst
     *      entzieht man sich hier die eigenen Rechte.
     *   3. Rollen außerhalb der erlaubten Auswahl bleiben unangetastet,
     *      statt beim Speichern stillschweigend zu verschwinden.
     *
     * @param string[] $roles
     */
    public function set_roles( int $wp_user_id, array $roles ): bool|WP_Error {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return new WP_Error( 'not_found', __( 'Dieser Nutzer existiert nicht.', 'vereins-plugin' ) );
        }

        if ( ! $this->role_is_changeable( $wp_user_id ) ) {
            return new WP_Error( 'protected', __( 'Rollen dieses Kontos lassen sich hier nicht ändern.', 'vereins-plugin' ) );
        }

        $erlaubt = array_keys( self::editable_roles() );
        $gewählt = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $erlaubt ) );

        $vorher    = (array) $user->roles;
        $unberührt = array_diff( $vorher, $erlaubt );

        foreach ( array_diff( $vorher, $gewählt, $unberührt ) as $weg ) {
            $user->remove_role( $weg );
        }
        foreach ( array_diff( $gewählt, $vorher ) as $dazu ) {
            $user->add_role( $dazu );
        }

        // Wer keine Vereinsrolle mehr trägt, ist auch kein aktives Mitglied
        // mehr. Sonst stünde in der Liste „aktiv“ neben jemandem ohne jede
        // Zugehörigkeit – der Widerspruch, der beim Lesen stolpern lässt.
        $member = $this->model->find_by_user_id( $wp_user_id );
        if ( $member && $member['status'] === 'active' && ! array_intersect( $gewählt, self::ROLES ) ) {
            $this->model->update( (int) $member['id'], [ 'status' => 'inactive' ] );
        }

        // Umgekehrt: Wer eine Vereinsrolle bekommt, ist wieder dabei.
        if ( $member && $member['status'] !== 'active' && array_intersect( $gewählt, self::ROLES ) ) {
            $this->model->update( (int) $member['id'], [ 'status' => 'active' ] );
        }

        return true;
    }

    /**
     * Rollen, die sich hier vergeben lassen.
     *
     * get_editable_roles() liegt im Admin-Bereich; im Frontend nachziehen.
     *
     * @return array<string, array>
     */
    public static function editable_roles(): array {
        if ( ! function_exists( 'get_editable_roles' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        return get_editable_roles();
    }

    /**
     * Nimmt einen bereits vorhandenen WordPress-Nutzer in den Verein auf.
     *
     * Für Seiten, auf denen schon Menschen arbeiten: Der Redakteur, der auch
     * im Verein ist, bekommt einen Mitgliedseintrag und die Vereinsrolle
     * obendrauf – ohne neues Konto und ohne Verlust seiner bisherigen Rechte.
     *
     * @return array{member: array}|WP_Error
     */
    public function add_existing_user( int $wp_user_id, string $role = '' ): array|WP_Error {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return new WP_Error( 'not_found', __( 'Dieser Nutzer existiert nicht.', 'vereins-plugin' ) );
        }

        if ( $this->model->find_by_user_id( $wp_user_id ) ) {
            return new WP_Error( 'already_member', __( 'Dieser Nutzer ist bereits Mitglied.', 'vereins-plugin' ) );
        }

        $member_id = $this->model->create( [
            'wp_user_id'   => $wp_user_id,
            'display_name' => $user->display_name,
            'phone'        => null,
            'status'       => 'active',
        ] );

        // Administratoren bekommen keine zusätzliche Rolle – sie dürfen
        // ohnehin alles, und role_is_changeable() würde sie schützen.
        if ( ! Capabilities::is_admin_user( $wp_user_id ) ) {
            $this->assign_role( $wp_user_id, in_array( $role, self::ROLES, true ) ? $role : Activator::ROLE_MEMBER );
        }

        return [ 'member' => $this->model->find( $member_id ) ];
    }

    /**
     * Darf die Vereinsrolle dieses Kontos hier überhaupt geändert werden?
     */
    public function role_is_changeable( int $wp_user_id ): bool {
        if ( $wp_user_id === get_current_user_id() ) {
            return false;
        }

        return ! user_can( $wp_user_id, 'manage_options' );
    }

    // ------------------------------------------------------------------
    // Zugriffsprüfung
    // ------------------------------------------------------------------

    /**
     * Orgateam und Admins sind immer freigeschaltet. vp_member nur wenn vp_members.status === 'active'.
     */
    public function is_approved( int $wp_user_id ): bool {
        $user = get_userdata( $wp_user_id );
        if ( ! $user || ! $user->exists() ) {
            return false;
        }
        $roles = (array) $user->roles;
        if ( in_array( Activator::ROLE_ORGA, $roles, true ) || in_array( 'administrator', $roles, true ) ) {
            return true;
        }

        $member = $this->model->find_by_user_id( $wp_user_id );
        return $member && $member['status'] === 'active';
    }

    /**
     * Wird an 'wp_authenticate_user' gehängt: blockiert klassische Passwort-Logins
     * (Kern-Login, wp_signon) für nicht freigeschaltete Mitglieder.
     */
    public function block_unapproved_login( $user ) {
        if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
            return $user;
        }

        if ( $this->is_approved( $user->ID ) ) {
            return $user;
        }

        // Nur Menschen abweisen, die dieses Plugin auch verwaltet.
        //
        // Diese Prüfung hängt an wp_authenticate_user und greift damit bei
        // JEDER Anmeldung an dieser WordPress-Installation – auch bei
        // Redakteuren, die mit dem Verein nichts zu tun haben. Ohne die
        // folgende Einschränkung sperrte das Plugin sie allesamt aus.
        //
        // Ebenso: Wer als Mitglied deaktiviert wurde, aber eine Rolle der
        // Website trägt, behält seine Anmeldung. Er verliert den
        // Mitgliederbereich – das regelt is_approved() an anderer Stelle –,
        // nicht aber seine Arbeit an der Website.
        $member = $this->model->find_by_user_id( $user->ID );
        if ( ! $member || ! Capabilities::is_club_only( $user->ID ) ) {
            return $user;
        }

        $message = $member['status'] === 'pending'
            ? __( 'Dein Zugang wurde noch nicht freigeschaltet.', 'vereins-plugin' )
            : __( 'Dein Zugang wurde deaktiviert. Bitte wende dich an die Vereinsleitung.', 'vereins-plugin' );

        return new WP_Error( 'vp_not_approved', $message );
    }

    // ------------------------------------------------------------------
    // Benachrichtigungen
    // ------------------------------------------------------------------

    private function notify_admin_of_registration( string $name, string $email ): void {
        $to      = Settings::get( 'notify_email' );
        $sender  = Settings::get( 'sender_name' );
        if ( ! $to ) {
            return;
        }
        $subject = sprintf( __( '[%s] Neue Mitglieder-Anmeldung', 'vereins-plugin' ), $sender );
        $body    = sprintf(
            __( "Hallo,\n\neine neue Anmeldung wartet auf Freischaltung:\n\nName: %s\nE-Mail: %s\n\nBitte im wp-admin unter „Verein → Mitglieder“ prüfen.", 'vereins-plugin' ),
            $name ?: '–',
            $email
        );
        wp_mail( $to, $subject, $body );
    }

    private function notify_user_of_registration_received( string $email, string $name, string $status ): void {
        $sender = Settings::get( 'sender_name' );
        $subject = sprintf( __( 'Deine Anmeldung bei %s', 'vereins-plugin' ), $sender );

        $body = $status === 'active'
            ? __( "Hallo,\n\ndein Zugang wurde erstellt und ist sofort aktiv. Du kannst dich jetzt anmelden.", 'vereins-plugin' )
            : __( "Hallo,\n\ndanke für deine Anmeldung. Deine Anmeldung wird geprüft und in Kürze freigeschaltet. Du erhältst eine E-Mail, sobald es losgehen kann.", 'vereins-plugin' );

        wp_mail( $email, $subject, $body );
    }

    private function notify_user_of_approval( int $wp_user_id ): void {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return;
        }
        $sender  = Settings::get( 'sender_name' );
        $subject = sprintf( __( 'Dein Zugang bei %s ist freigeschaltet', 'vereins-plugin' ), $sender );
        $body    = __( "Hallo,\n\ndein Zugang wurde freigeschaltet. Du kannst dich ab sofort anmelden.", 'vereins-plugin' );
        wp_mail( $user->user_email, $subject, $body );
    }
}
