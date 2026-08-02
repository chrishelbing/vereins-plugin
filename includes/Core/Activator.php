<?php

namespace VereinsPlugin\Core;

use VereinsPlugin\Database\Schema;
use VereinsPlugin\Support\Capabilities;

/**
 * Wird bei Plugin-Aktivierung / Deaktivierung aufgerufen.
 */
class Activator {

    /** Rollenkennungen – an einer Stelle, damit Umbenennungen auffindbar bleiben. */
    public const ROLE_MEMBER = 'vp_vereinsmitglied';
    public const ROLE_ORGA   = 'vp_orgateam';

    /**
     * Frühere Kennungen und ihre heutige Entsprechung. Wird beim Update
     * abgearbeitet, damit niemand seine Rechte verliert.
     */
    private const ROLE_HISTORY = [
        'vp_board'  => self::ROLE_ORGA,
        'vp_orga'   => self::ROLE_ORGA,
        'vp_member' => self::ROLE_MEMBER,
    ];

    public static function activate(): void {
        Schema::create_tables();
        self::register_roles();
        self::migrate_roles();
        Capabilities::ensure_admin_role_caps();

        // Startdaten: Termintypen und Listen, damit die erste Terminanlage
        // nicht vor leeren Auswahlfeldern steht. Läuft nur bei einer frischen
        // Installation – Seeder::maybe_seed() prüft das selbst.
        Seeder::maybe_seed();

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        \VereinsPlugin\Photos\PhotoCleanup::unschedule();
        \VereinsPlugin\Auth\OtpCleanup::unschedule();
        \VereinsPlugin\Members\PeopleCleanup::unschedule();
        flush_rewrite_rules();
    }

    /**
     * Die Rollen dieses Plugins mit Beschriftung und Rechten.
     *
     * Die Beschriftungen tragen das Präfix „Verein:“ – denselben Namen wie
     * der Menüpunkt im Backend. In der Nutzerliste steht sonst nur
     * „Orgateam“, und niemand erkennt, woher diese Rolle stammt oder was
     * beim Deaktivieren des Plugins mit ihr passiert. Nebenbei stehen beide
     * Rollen dadurch in Auswahllisten beieinander.
     *
     * Die Kennungen sind ebenso ausgeschrieben: 'vp_orga' ließe offen, ob
     * Organisation, Organigramm oder Organisator gemeint ist.
     *
     * @return array<string, array{0:string, 1:array<string,bool>}>
     */
    private static function roles(): array {
        return [
            // Mitglied – liest vereinsinterne Inhalte, darf Termine vorschlagen
            self::ROLE_MEMBER => [
                __( 'Verein: Mitglied', 'vereins-plugin' ),
                [ 'read' => true ],
            ],
            // Orgateam – organisiert im Frontend (Mitbringliste, Bedarf vorgeben).
            // Bewusst OHNE 'vp_manage': wp-admin ist Admins vorbehalten.
            self::ROLE_ORGA => [
                __( 'Verein: Orgateam', 'vereins-plugin' ),
                [ 'read' => true, 'vp_organize' => true ],
            ],
        ];
    }

    public static function register_roles(): void {
        foreach ( self::roles() as $slug => [ $label, $caps ] ) {
            add_role( $slug, $label, $caps );
        }

        self::ensure_role_labels();
    }

    /**
     * Zieht geänderte Beschriftungen bei bestehenden Installationen nach.
     *
     * add_role() tut bei einer bereits vorhandenen Rolle gar nichts – auch
     * nicht, wenn sich die Beschriftung geändert hat. Ohne diesen Schritt
     * bliebe in älteren Installationen für immer „Orgateam“ stehen.
     *
     * Der Umweg über remove_role() ist ungefährlich: Er entfernt nur die
     * Rollendefinition. Welche Rolle ein Nutzer trägt, steht in seinen
     * eigenen Metadaten und bleibt unberührt.
     */
    public static function ensure_role_labels(): void {
        $vorhanden = wp_roles();

        foreach ( self::roles() as $slug => [ $label, $caps ] ) {
            if ( ! isset( $vorhanden->roles[ $slug ] ) ) {
                add_role( $slug, $label, $caps );
                continue;
            }

            if ( ( $vorhanden->roles[ $slug ]['name'] ?? '' ) === $label ) {
                continue;
            }

            // Vorhandene Rechte übernehmen – ein Verein könnte sie mit einem
            // Rollen-Plugin angepasst haben.
            $bestehende_caps = $vorhanden->roles[ $slug ]['capabilities'] ?? $caps;

            remove_role( $slug );
            add_role( $slug, $label, $bestehende_caps );
        }
    }

    /**
     * Übernimmt Nutzer der früheren Rolle 'vp_board' ins Orgateam und
     * entfernt die alte Rolle. Läuft nur einmal (Option als Marker).
     */
    /**
     * Überträgt Nutzer von früheren Rollenkennungen auf die heutigen.
     *
     * Läuft bei jedem Versionswechsel und ist absichtlich nicht durch eine
     * Option gebremst: Kommt später eine weitere Umbenennung hinzu, greift
     * sie sofort. Wer bereits die richtige Rolle hat, wird nicht angefasst.
     */
    public static function migrate_roles(): void {
        foreach ( self::ROLE_HISTORY as $alt => $neu ) {
            if ( $alt === $neu ) {
                continue;
            }

            foreach ( get_users( [ 'role' => $alt, 'fields' => 'ID' ] ) as $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                    continue;
                }
                $user->add_role( $neu );
                $user->remove_role( $alt );
            }

            remove_role( $alt );
        }
    }
}
