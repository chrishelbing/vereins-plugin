<?php

namespace VereinsPlugin\Auth;

/**
 * Täglicher Aufräumjob für Einmalcode-Anfragen.
 *
 * OtpModel::purge_expired() gab es schon lange, hing aber an keinem Cron –
 * abgelaufene Anfragen blieben dauerhaft in vp_otp_tokens stehen, samt
 * IP-Adresse und der Adresse (E-Mail/Telefon), an die der Code ging. Das ist
 * mehr, als für den eigentlichen Zweck (zehn Minuten gültiger Code) nötig
 * ist – dieser Job holt das Aufräumen nach.
 */
class OtpCleanup {

    private const HOOK = 'vp_otp_cleanup';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // Versetzt zum Foto-Cleanup, damit nicht beides gleichzeitig läuft.
            wp_schedule_event( strtotime( 'tomorrow 3:15' ), 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public function run(): void {
        ( new OtpModel() )->purge_expired();
    }
}
