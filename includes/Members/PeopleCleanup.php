<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Admin\Settings;

/**
 * Täglicher Aufräumjob für offene Gast-Kontakte (vp_people).
 *
 * Ohne diesen Job blieb ein Gast, der einmal über einen Terminlink zugesagt
 * hat und nie wiederkam, unbegrenzt mit Name, E-Mail und Telefonnummer
 * stehen. Gelöscht wird über dieselbe Stelle wie eine manuelle Ablehnung
 * (MemberService::reject_person()) – Zusagen und übernommene Aufgaben
 * fallen mit weg, damit nichts halb verwaist zurückbleibt.
 *
 * Bewusst per Voreinstellung aktiv (180 Tage), aber jederzeit auf 0 (nie
 * automatisch löschen) stellbar – siehe Einstellungen → Datenschutz.
 */
class PeopleCleanup {

    private const HOOK = 'vp_people_cleanup';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // Versetzt zu den anderen Aufräumjobs, damit nicht alles gleichzeitig läuft.
            wp_schedule_event( strtotime( 'tomorrow 3:45' ), 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public function run(): void {
        $days = (int) Settings::get( 'people_retention_days', 180 );
        if ( $days <= 0 ) {
            return;
        }

        $service = new MemberService();

        foreach ( ( new PeopleModel() )->stale( $days ) as $person ) {
            $service->reject_person( Person::from_guest( (int) $person['id'] ) );
        }
    }
}
