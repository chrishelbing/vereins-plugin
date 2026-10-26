<?php

namespace VereinsPlugin\Support;

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Members\MemberService;

/**
 * Zentrale Rechte-Helfer.
 *
 * Rechtemodell:
 *   Administrator – volle Rechte, einziger Zugang zum wp-admin-Backend.
 *                   Legt Termintypen an, konfiguriert das Plugin, gibt
 *                   eingereichte Termine frei.
 *   Orgateam      – organisiert im Frontend: gibt Bedarf für die Mitbring-
 *                   liste vor, räumt dort auf. Kein Backend-Zugang.
 *   Mitglied      – sieht Termine, sagt zu/ab, schlägt Termine vor.
 *
 * Termine anlegen darf jedes freigeschaltete Mitglied; sichtbar werden sie
 * aber erst nach Freigabe durch einen Administrator (Ausnahme: Termine, die
 * ein Administrator selbst anlegt).
 */
class Capabilities {

    /**
     * Administrator? Einziger Personenkreis mit Backend-Zugang und
     * Freigaberecht für Termine.
     */
    public static function is_admin_user( ?int $user_id = null ): bool {
        // Negative IDs sind Personen ohne WordPress-Profil (vp_people). Sie
        // haben grundsätzlich keine Rechte – hier abfangen, damit niemand
        // sich darauf verlässt, dass user_can() mit einer solchen ID false
        // liefert.
        if ( $user_id !== null && $user_id < 0 ) {
            return false;
        }

        return $user_id
            ? user_can( $user_id, 'manage_options' )
            : current_user_can( 'manage_options' );
    }

    /**
     * Trägt dieser Nutzer ausschließlich Rollen dieses Plugins?
     *
     * Entscheidend dafür, wie weit das Plugin eingreifen darf. Wer daneben
     * eine Rolle der Seite hat – Redakteur, Autor, Abonnent –, gehört nicht
     * uns: Sein Backend, seine Werkzeugleiste und seine Rechte bleiben so,
     * wie die Seite sie vergeben hat. Ein Plugin, das einem Redakteur die
     * Beiträge wegnimmt, weil er im Verein mithilft, wäre unbrauchbar.
     */
    public static function is_club_only( ?int $user_id = null ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        return ! array_diff( (array) $user->roles, self::club_roles() );
    }

    /** Die Rollen, die dieses Plugin selbst vergibt. */
    public static function club_roles(): array {
        return [ Activator::ROLE_MEMBER, Activator::ROLE_ORGA ];
    }

    /**
     * Orgateam oder Administrator?
     */
    public static function is_orga( ?int $user_id = null ): bool {
        if ( $user_id !== null && $user_id < 0 ) {
            return false;
        }

        if ( self::is_admin_user( $user_id ) ) {
            return true;
        }

        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        return in_array( Activator::ROLE_ORGA, (array) $user->roles, true );
    }

    /**
     * Termine anlegen darf jedes freigeschaltete Mitglied.
     */
    public static function can_create_events( ?int $user_id = null ): bool {
        return self::is_active_member( $user_id );
    }

    /**
     * Darf diese Person einen bestehenden Termin ändern, löschen oder eine
     * einzelne Instanz abweichen lassen?
     *
     * Anlegen darf jedes freigeschaltete Mitglied. Ohne eine eigene Regel
     * fürs Ändern dürfte danach aber auch jedes andere Mitglied den Termin
     * wieder umschreiben – die Freigabe durch einen Administrator wäre
     * damit wertlos, weil sie sich im Nachhinein aushebeln ließe.
     *
     * Deshalb: Orgateam und Administratoren dürfen jeden Termin, alle
     * anderen nur den, den sie selbst angelegt haben.
     */
    public static function can_edit_event( ?array $event, ?int $person_id = null ): bool {
        if ( ! $event ) {
            return false;
        }

        $person_id = $person_id ?: get_current_user_id();

        // Gäste (negative IDs, kein WordPress-Profil) dürfen grundsätzlich nicht.
        if ( ! $person_id || $person_id < 0 ) {
            return false;
        }

        // Reihenfolge mit Bedacht: Die Terminliste ruft das je Kachel auf.
        // Eigentümerfrage und Rollen kosten nichts (Nutzerdaten kommen aus
        // dem Objektcache), die Freischaltung dagegen eine Abfrage – die
        // stellen wir erst, wenn es überhaupt darauf ankommt.
        $ist_eigener = (int) ( $event['created_by'] ?? 0 ) === $person_id;

        if ( ! $ist_eigener && ! self::is_orga( $person_id ) ) {
            return false;
        }

        return self::is_active_member( $person_id );
    }

    /**
     * Termine freigeben/ablehnen dürfen ausschließlich Administratoren.
     */
    public static function can_approve_events( ?int $user_id = null ): bool {
        return self::is_admin_user( $user_id );
    }

    /**
     * Freigeschaltetes Mitglied? (Orgateam/Admin immer, vp_member nur wenn
     * Status 'active')
     */
    public static function is_active_member( ?int $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        return ( new MemberService() )->is_approved( $user_id );
    }

    /**
     * Stellt die Plugin-Capabilities sicher. Der Backend-Zugang hängt an
     * WordPress' eigenem 'manage_options', daher braucht es hier nur die
     * Frontend-Capability des Orgateams. add_cap() schreibt nur bei
     * tatsächlicher Änderung.
     *
     * Räumt außerdem die nicht mehr genutzte Capability 'vp_manage' auf,
     * die frühere Versionen an 'administrator' vergeben haben.
     */
    public static function ensure_admin_role_caps(): void {
        foreach ( [ 'administrator', Activator::ROLE_ORGA ] as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            if ( ! $role->has_cap( 'vp_organize' ) ) {
                $role->add_cap( 'vp_organize' );
            }
            if ( $role->has_cap( 'vp_manage' ) ) {
                $role->remove_cap( 'vp_manage' );
            }
        }
    }
}
