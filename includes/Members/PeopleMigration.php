<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Core\Activator;

/**
 * Überführt bereits angelegte, noch nicht freigegebene WordPress-Nutzer
 * nach vp_people.
 *
 * Frühere Fassungen des Plugins haben für jede Zusage über einen Terminlink
 * und für jede Selbstregistrierung sofort einen WordPress-Nutzer angelegt.
 * Genau das soll die Nutzerverwaltung nicht mehr zeigen.
 *
 * Läuft einmalig beim Update. Angefasst wird ausschließlich, wer alle diese
 * Bedingungen erfüllt:
 *
 *   – hat einen vp_members-Eintrag mit Status 'pending'
 *   – ist kein Administrator und in keiner weiteren Rolle
 *   – hat nichts veröffentlicht (keine Beiträge, Seiten, Kommentare)
 *
 * Die letzte Bedingung ist die wichtigste: Ein Nutzer, an dem Inhalte hängen,
 * wird niemals gelöscht. Im Zweifel bleibt der Zugang lieber bestehen.
 */
class PeopleMigration {

    /** Muss zu MemberService::PERSON_TABLES passen. */
    private const PERSON_TABLES = [
        'vp_event_attendance'  => 'person_id',
        'vp_event_item_claims' => 'person_id',
        'vp_member_companions' => 'person_id',
        'vp_event_companions'  => 'person_id',
        'vp_event_items'       => 'created_by',
    ];

    /**
     * @return array{übernommen:int, übersprungen:int}
     */
    public static function run(): array {
        global $wpdb;

        $übernommen   = 0;
        $übersprungen = 0;

        $offen = $wpdb->get_results(
            "SELECT m.id, m.wp_user_id, m.display_name, m.phone, m.confirmed_at
             FROM {$wpdb->prefix}vp_members m
             WHERE m.status = 'pending'",
            ARRAY_A
        ) ?: [];

        $people = new PeopleModel();

        foreach ( $offen as $row ) {
            $user_id = (int) $row['wp_user_id'];
            $user    = get_userdata( $user_id );

            if ( ! $user || ! self::is_safe_to_move( $user ) ) {
                $übersprungen++;
                continue;
            }

            $email = (string) $user->user_email;
            // Platzhalteradressen aus der alten Gastanlage nicht mitnehmen.
            if ( str_ends_with( $email, '@invalid.local' ) ) {
                $email = '';
            }

            // Doppelte Adressen vermeiden – vp_people hat einen eindeutigen Schlüssel.
            if ( $email && $people->find_by_email( $email ) ) {
                $übersprungen++;
                continue;
            }

            $guest_id = $people->create( [
                'display_name' => $row['display_name'] ?: $user->display_name,
                'email'        => $email,
                'phone'        => (string) ( $row['phone'] ?? '' ),
                // Ohne echte Adresse war es eine Zusage über einen Terminlink.
                'source'       => $email ? 'registration' : 'link',
            ] );

            if ( ! $guest_id ) {
                $übersprungen++;
                continue;
            }

            if ( ! empty( $row['confirmed_at'] ) ) {
                $people->update( $guest_id, [ 'confirmed_at' => $row['confirmed_at'] ] );
            }

            self::repoint( $user_id, Person::from_guest( $guest_id ) );

            $wpdb->delete( $wpdb->prefix . 'vp_members', [ 'id' => (int) $row['id'] ], [ '%d' ] );
            $wpdb->delete( $wpdb->prefix . 'vp_sessions', [ 'wp_user_id' => $user_id ], [ '%d' ] );

            // wp_delete_user() liegt im Admin-Bereich und ist im Frontend
            // nicht geladen – hier einmalig nachziehen.
            if ( ! function_exists( 'wp_delete_user' ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user( $user_id );

            $übernommen++;
        }

        Person::flush_cache();

        return [ 'übernommen' => $übernommen, 'übersprungen' => $übersprungen ];
    }

    /**
     * Darf dieser Nutzer gelöscht werden?
     *
     * Bewusst streng: Sobald irgendetwas an ihm hängt oder er mehr als die
     * reine Mitgliedsrolle hat, bleibt er unberührt. Ein übersprungener
     * Eintrag ist ein Schönheitsfehler, ein gelöschter Autor ein Datenverlust.
     */
    private static function is_safe_to_move( \WP_User $user ): bool {
        global $wpdb;

        if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_posts' ) ) {
            return false;
        }

        $rollen = array_diff( (array) $user->roles, [ Activator::ROLE_MEMBER ] );
        if ( $rollen ) {
            return false;
        }

        $inhalte = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d",
            $user->ID
        ) );
        if ( $inhalte > 0 ) {
            return false;
        }

        $kommentare = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d",
            $user->ID
        ) );

        return $kommentare === 0;
    }

    private static function repoint( int $von, int $nach ): void {
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
}
