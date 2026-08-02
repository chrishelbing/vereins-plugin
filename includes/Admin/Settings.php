<?php

namespace VereinsPlugin\Admin;

/**
 * Liest/schreibt die Plugin-Einstellungen (ein Options-Array unter 'vp_settings').
 */
class Settings {

    private const OPTION = 'vp_settings';

    public static function defaults(): array {
        return [
            'notify_email'     => get_option( 'admin_email' ),
            'sender_name'      => get_bloginfo( 'name' ) ?: 'Vereins-Plugin',
            'require_approval' => 1,

            // Fotos
            'photo_upload_days'    => 14,   // Tage nach Terminende, in denen hochgeladen werden darf
            'photo_retention_days' => 90,   // Tage nach Terminende bis zur Löschung
            'photo_warn_days'      => 7,    // Vorwarnung so viele Tage vorher
            'photo_quota_mb'       => 50,   // je Mitglied und Termin
            'photo_max_upload_mb'  => 15,   // je Einzeldatei (vor dem Verkleinern)
            'photo_max_edge'       => 2000, // längste Kante nach dem Verkleinern

            // Offene Gast-Kontakte (vp_people): Tage ohne Zusage/Aktivität bis
            // zur automatischen Löschung. 0 = nie automatisch löschen.
            'people_retention_days' => 180,

            // Angaben für Aushänge und Flyer. Ein Aushang ohne Absender wirkt
            // anonym – wer nachfragen will, braucht einen Anhaltspunkt.
            'club_name'      => get_bloginfo( 'name' ) ?: '',
            'club_logo_id'   => 0,    // Anhang-ID aus der Mediathek
            'flyer_contact'  => '',   // Ansprechperson, frei formuliert
            'flyer_footnote' => '',   // Zusatzzeile, etwa Vereinsanschrift
        ];
    }

    public static function all(): array {
        $stored = get_option( self::OPTION, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        return array_merge( self::defaults(), $stored );
    }

    public static function get( string $key, $default = null ) {
        $all = self::all();
        return $all[ $key ] ?? $default;
    }

    public static function update( array $values ): void {
        $current = self::all();
        update_option( self::OPTION, array_merge( $current, $values ) );
    }

    public static function option_name(): string {
        return self::OPTION;
    }
}
