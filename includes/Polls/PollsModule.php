<?php

namespace VereinsPlugin\Polls;

/**
 * Modul Umfragen – alles, was beim Laden des Plugins angemeldet wird.
 * Schnittstelle in Api\RestApi, Shortcode in Frontend\ShortcodeRegistry.
 */
class PollsModule {

    public function register(): void {
        ( new PollsAdmin() )->register();
        ( new ClassesAdmin() )->register();
        ( new EntryCleanup() )->register();
        ( new PollBanner() )->register();

        // Die Basis löscht und übernimmt Personen über eine Tabellenliste.
        // Stimmen und Meldungen gehören dazu – sonst blieben sie nach dem
        // Löschen einer Person stehen.
        add_filter( 'vp_person_tables', static function ( $tabellen ) {
            $tabellen['vp_poll_votes']   = 'person_id';
            $tabellen['vp_poll_entries'] = 'person_id';
            $tabellen['vp_poll_texts']   = 'person_id';
            return $tabellen;
        } );
    }
}
