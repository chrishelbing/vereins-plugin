<?php

namespace VereinsPlugin\Privacy;

use VereinsPlugin\Auth\OtpModel;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Members\MemberModel;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\PeopleModel;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Support\Capabilities;

/**
 * Anbindung an die WordPress-Bordmittel für Auskunft und Löschung
 * (Werkzeuge → Persönliche Daten exportieren / löschen).
 *
 * Ohne diese Anbindung müsste jede Auskunfts- oder Löschanfrage von Hand
 * durch alle Tabellen des Plugins nachvollzogen werden. WordPress sucht
 * beide Werkzeuge über eine E-Mail-Adresse; entsprechend wird hier sowohl
 * nach einem aufgenommenen Mitglied (wp_users + vp_members) als auch nach
 * einem offenen Kontakt (vp_people – Gast oder wartende Registrierung)
 * gesucht. Die Löschung nutzt dieselben Wege wie die Mitgliederverwaltung
 * (MemberService::erase_member() bzw. reject_person()), damit es nur eine
 * Stelle gibt, die weiß, was „vollständig gelöscht" bedeutet.
 */
class PersonalData {

    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser' ] );
    }

    public function register_exporter( array $exporters ): array {
        $exporters['vereins-plugin'] = [
            'exporter_friendly_name' => __( 'Vereins-Plugin', 'vereins-plugin' ),
            'callback'                => [ $this, 'export' ],
        ];
        return $exporters;
    }

    public function register_eraser( array $erasers ): array {
        $erasers['vereins-plugin'] = [
            'eraser_friendly_name' => __( 'Vereins-Plugin', 'vereins-plugin' ),
            'callback'               => [ $this, 'erase' ],
        ];
        return $erasers;
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * @return array{data: array, done: bool}
     */
    public function export( string $email_address, int $page = 1 ): array {
        $email  = sanitize_email( $email_address );
        $groups = [];

        $member_id = $this->member_id_by_email( $email );
        if ( $member_id ) {
            $groups[] = $this->member_stammdaten_item( $member_id );
            $groups   = array_merge(
                $groups,
                $this->attendance_items( $member_id ),
                $this->claims_items( $member_id ),
                $this->companions_items( $member_id ),
                $this->assigned_companion_items( $member_id ),
                $this->photos_items( $member_id )
            );
        }

        $guest = ( new PeopleModel() )->find_by_email( $email );
        if ( $guest ) {
            $guest_id = Person::from_guest( (int) $guest['id'] );
            $groups[] = $this->guest_stammdaten_item( $guest, $guest_id );
            $groups   = array_merge(
                $groups,
                $this->attendance_items( $guest_id ),
                $this->claims_items( $guest_id ),
                $this->companions_items( $guest_id )
            );
        }

        return [ 'data' => $groups, 'done' => true ];
    }

    private function member_stammdaten_item( int $wp_user_id ): array {
        $member = ( new MemberModel() )->find_by_user_id( $wp_user_id );
        $user   = get_userdata( $wp_user_id );

        return [
            'group_id'    => 'vp_mitgliedschaft',
            'group_label' => __( 'Vereinsmitgliedschaft', 'vereins-plugin' ),
            'item_id'     => 'vp-mitglied-' . $wp_user_id,
            'data'        => [
                [ 'name' => __( 'Name', 'vereins-plugin' ), 'value' => $member['display_name'] ?? '' ],
                [ 'name' => __( 'E-Mail', 'vereins-plugin' ), 'value' => $user ? $user->user_email : '' ],
                [ 'name' => __( 'Telefon', 'vereins-plugin' ), 'value' => $member['phone'] ?? '' ],
                [ 'name' => __( 'Status', 'vereins-plugin' ), 'value' => $member['status'] ?? '' ],
                [
                    'name'  => __( 'Vereinsrollen', 'vereins-plugin' ),
                    'value' => implode( ', ', array_intersect( (array) ( $user->roles ?? [] ), Capabilities::club_roles() ) ),
                ],
                [ 'name' => __( 'Bestätigt am', 'vereins-plugin' ), 'value' => $member['confirmed_at'] ?? '' ],
                [ 'name' => __( 'Mitglied seit', 'vereins-plugin' ), 'value' => $member['created_at'] ?? '' ],
            ],
        ];
    }

    private function guest_stammdaten_item( array $person, int $person_id ): array {
        return [
            'group_id'    => 'vp_kontakt',
            'group_label' => __( 'Offener Kontakt (Gast/Registrierung)', 'vereins-plugin' ),
            'item_id'     => 'vp-person-' . abs( $person_id ),
            'data'        => [
                [ 'name' => __( 'Name', 'vereins-plugin' ), 'value' => $person['display_name'] ?? '' ],
                [ 'name' => __( 'E-Mail', 'vereins-plugin' ), 'value' => $person['email'] ?? '' ],
                [ 'name' => __( 'Telefon', 'vereins-plugin' ), 'value' => $person['phone'] ?? '' ],
                [ 'name' => __( 'Herkunft', 'vereins-plugin' ), 'value' => $person['source'] ?? '' ],
                [
                    'name'  => __( 'Bestätigt', 'vereins-plugin' ),
                    'value' => empty( $person['confirmed_at'] ) ? __( 'nein', 'vereins-plugin' ) : $person['confirmed_at'],
                ],
                [ 'name' => __( 'Seit', 'vereins-plugin' ), 'value' => $person['created_at'] ?? '' ],
            ],
        ];
    }

    private function attendance_items( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}vp_event_attendance WHERE person_id = %d ORDER BY responded_at DESC",
                $person_id
            ),
            ARRAY_A
        ) ?: [];

        $events = new EventModel();
        $out    = [];
        foreach ( $rows as $row ) {
            $event = $events->find( (int) $row['event_id'] );
            $out[] = [
                'group_id'    => 'vp_zusagen',
                'group_label' => __( 'Zu-/Absagen', 'vereins-plugin' ),
                'item_id'     => 'vp-zusage-' . $row['id'],
                'data'        => [
                    [ 'name' => __( 'Termin', 'vereins-plugin' ), 'value' => $event['title'] ?? ( '#' . $row['event_id'] ) ],
                    [ 'name' => __( 'Datum', 'vereins-plugin' ), 'value' => $row['instance_date'] ],
                    [
                        'name'  => __( 'Antwort', 'vereins-plugin' ),
                        'value' => $row['response'] === 'yes' ? __( 'Zugesagt', 'vereins-plugin' ) : __( 'Abgesagt', 'vereins-plugin' ),
                    ],
                    [
                        'name'  => __( 'Hilft mit', 'vereins-plugin' ),
                        'value' => $row['helping'] ? ( $row['help_task'] ?: __( 'ja', 'vereins-plugin' ) ) : __( 'nein', 'vereins-plugin' ),
                    ],
                    [ 'name' => __( 'Beantwortet am', 'vereins-plugin' ), 'value' => $row['responded_at'] ],
                ],
            ];
        }
        return $out;
    }

    private function claims_items( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}vp_event_item_claims WHERE person_id = %d ORDER BY claimed_at DESC",
                $person_id
            ),
            ARRAY_A
        ) ?: [];

        $items = new ItemModel();
        $out   = [];
        foreach ( $rows as $row ) {
            $item  = $items->find( (int) $row['item_id'] );
            $out[] = [
                'group_id'    => 'vp_uebernahmen',
                'group_label' => __( 'Mitbringliste & Helferaufgaben', 'vereins-plugin' ),
                'item_id'     => 'vp-claim-' . $row['id'],
                'data'        => [
                    [ 'name' => __( 'Posten', 'vereins-plugin' ), 'value' => $item['label'] ?? ( '#' . $row['item_id'] ) ],
                    [
                        'name'  => __( 'Art', 'vereins-plugin' ),
                        'value' => ( $item['kind'] ?? '' ) === 'help' ? __( 'Helferaufgabe', 'vereins-plugin' ) : __( 'Mitbringliste', 'vereins-plugin' ),
                    ],
                    [ 'name' => __( 'Menge', 'vereins-plugin' ), 'value' => (string) $row['quantity'] ],
                    [ 'name' => __( 'Notiz', 'vereins-plugin' ), 'value' => $row['note'] ?? '' ],
                    [
                        'name'  => __( 'Für', 'vereins-plugin' ),
                        'value' => (int) $row['companion_id'] === 0 ? __( 'mich selbst', 'vereins-plugin' ) : __( 'Begleitperson', 'vereins-plugin' ),
                    ],
                    [ 'name' => __( 'Übernommen am', 'vereins-plugin' ), 'value' => $row['claimed_at'] ],
                ],
            ];
        }
        return $out;
    }

    private function companions_items( int $person_id ): array {
        $out = [];
        foreach ( ( new CompanionModel() )->saved_for_user( $person_id ) as $c ) {
            $out[] = [
                'group_id'    => 'vp_begleitpersonen',
                'group_label' => __( 'Gespeicherte Begleitpersonen', 'vereins-plugin' ),
                'item_id'     => 'vp-begleitung-' . $c['id'],
                'data'        => [
                    [ 'name' => __( 'Name', 'vereins-plugin' ), 'value' => $c['name'] ],
                ],
            ];
        }
        return $out;
    }

    /**
     * Begleitpersonen, die tatsächlich zu einer Zusage mitgeschickt wurden –
     * getrennt von der persönlichen Vorschlagsliste, weil der Name dort
     * unabhängig von ihr weiterlebt (siehe CompanionModel).
     */
    private function assigned_companion_items( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}vp_event_companions WHERE person_id = %d",
                $person_id
            ),
            ARRAY_A
        ) ?: [];

        $events = new EventModel();
        $out    = [];
        foreach ( $rows as $row ) {
            $event = $events->find( (int) $row['event_id'] );
            $out[] = [
                'group_id'    => 'vp_begleitpersonen',
                'group_label' => __( 'Gespeicherte Begleitpersonen', 'vereins-plugin' ),
                'item_id'     => 'vp-begleitung-zusage-' . $row['id'],
                'data'        => [
                    [ 'name' => __( 'Name', 'vereins-plugin' ), 'value' => $row['name'] ],
                    [ 'name' => __( 'Termin', 'vereins-plugin' ), 'value' => $event['title'] ?? ( '#' . $row['event_id'] ) ],
                    [ 'name' => __( 'Datum', 'vereins-plugin' ), 'value' => $row['instance_date'] ],
                ],
            ];
        }
        return $out;
    }

    private function photos_items( int $wp_user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}vp_event_photos WHERE wp_user_id = %d ORDER BY created_at DESC",
                $wp_user_id
            ),
            ARRAY_A
        ) ?: [];

        $events = new EventModel();
        $out    = [];
        foreach ( $rows as $row ) {
            $event = $events->find( (int) $row['event_id'] );
            $url   = wp_get_attachment_url( (int) $row['attachment_id'] );
            $out[] = [
                'group_id'    => 'vp_fotos',
                'group_label' => __( 'Hochgeladene Fotos', 'vereins-plugin' ),
                'item_id'     => 'vp-foto-' . $row['id'],
                'data'        => [
                    [ 'name' => __( 'Termin', 'vereins-plugin' ), 'value' => $event['title'] ?? ( '#' . $row['event_id'] ) ],
                    [ 'name' => __( 'Datum', 'vereins-plugin' ), 'value' => $row['instance_date'] ],
                    [ 'name' => __( 'Hochgeladen am', 'vereins-plugin' ), 'value' => $row['created_at'] ],
                    [ 'name' => __( 'Zustimmung zur Nutzung am', 'vereins-plugin' ), 'value' => $row['consent_at'] ?? '' ],
                    [ 'name' => __( 'Link', 'vereins-plugin' ), 'value' => $url ?: '' ],
                ],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Löschung
    // ------------------------------------------------------------------

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase( string $email_address, int $page = 1 ): array {
        $email          = sanitize_email( $email_address );
        $messages       = [];
        $items_removed  = false;
        $items_retained = false;

        $member_id = $this->member_id_by_email( $email );
        if ( $member_id ) {
            $result = ( new MemberService() )->erase_member( $member_id );

            if ( is_wp_error( $result ) ) {
                $items_retained = true;
                $messages[]     = $result->get_error_message();
            } else {
                $items_removed = true;
                if ( ! $result['account_deleted'] ) {
                    $items_retained = true;
                    $messages[]     = __(
                        'Das WordPress-Konto trägt eine Rolle der Website oder eigene Beiträge und bleibt deshalb bestehen; die Vereinsdaten wurden entfernt und die Vereinsrollen entzogen.',
                        'vereins-plugin'
                    );
                }
            }
        }

        $guest = ( new PeopleModel() )->find_by_email( $email );
        if ( $guest ) {
            $guest_id = Person::from_guest( (int) $guest['id'] );
            if ( ( new MemberService() )->reject_person( $guest_id ) ) {
                $items_removed = true;
            } else {
                $items_retained = true;
                $messages[]     = __( 'Der offene Eintrag (Gast/Registrierung) konnte nicht gelöscht werden.', 'vereins-plugin' );
            }
        }

        // Offene Einmalcode-Anfragen für diese Adresse ebenfalls entfernen –
        // sonst bliebe die IP-Adresse der letzten Anfrage stehen, obwohl der
        // Rest der Person gerade gelöscht wurde.
        ( new OtpModel() )->delete_for_identifier( $email );

        return [
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    private function member_id_by_email( string $email ): ?int {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return null;
        }
        return ( new MemberModel() )->find_by_user_id( $user->ID ) ? (int) $user->ID : null;
    }
}
