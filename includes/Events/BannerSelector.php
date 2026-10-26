<?php

namespace VereinsPlugin\Events;

/**
 * Welche Termine gerade als Banner auf der Website stehen.
 *
 * Am Termin legt ein Administrator fest, ob er als Banner erscheint und ab
 * wie vielen Tagen vor Beginn (banner_days_before, NULL = nie). Sichtbar ist
 * der Banner vom Beginn jenes Tages bis zum Terminende – danach verschwindet
 * er von selbst, niemand muss ihn von der Startseite nehmen.
 *
 * Bei einer Serie steht immer nur die nächste Instanz im Banner. Ein
 * wöchentliches Training mit Banner soll nicht vier Kacheln gleichzeitig
 * belegen.
 *
 * Die Auswahl selbst (pick) ist ohne Datenbank prüfbar – siehe
 * tools/bannertest.php.
 */
class BannerSelector {

    /** Längster erlaubter Vorlauf in Tagen – so weit wird vorausgeschaut. */
    public const MAX_DAYS = 365;

    /**
     * Aktuell sichtbare Banner, der früheste Termin zuerst.
     *
     * @return array[]  Termininstanzen wie aus RecurrenceExpander::expand()
     */
    public static function current( int $limit = 1, ?string $now = null ): array {
        $now = $now ?? current_time( 'Y-m-d H:i:s' );
        $to  = ( new \DateTime( $now ) )->modify( '+' . ( self::MAX_DAYS + 1 ) . ' days' )->format( 'Y-m-d H:i:s' );

        // Nur freigegebene Termine – in_range() filtert das bereits.
        $raw = array_values( array_filter(
            ( new EventModel() )->in_range( $now, $to ),
            [ self::class, 'has_banner' ]
        ) );

        if ( ! $raw ) {
            return [];
        }

        return self::pick( RecurrenceExpander::expand( $raw, $now, $to ), $now, $limit );
    }

    /**
     * Wählt aus expandierten Instanzen die sichtbaren Banner aus.
     *
     * @param array[] $instances Instanzen, gleich in welcher Reihenfolge
     * @param string  $now       Y-m-d H:i:s, lokale Vereinszeit
     */
    public static function pick( array $instances, string $now, int $limit = 1 ): array {
        usort( $instances, fn( $a, $b ) => strcmp( $a['start_at'], $b['start_at'] ) );

        $seen = [];
        $out  = [];

        foreach ( $instances as $instance ) {
            if ( ! self::has_banner( $instance ) ) {
                continue;
            }
            // Vorbei ist vorbei – auch wenn die Serie weiterläuft.
            if ( $instance['end_at'] <= $now ) {
                continue;
            }

            $event_id = (int) $instance['event_id'];

            // Serie: Nur die nächste Instanz zählt. Ist deren Fenster noch
            // nicht offen, sind es die späteren erst recht nicht.
            if ( isset( $seen[ $event_id ] ) ) {
                continue;
            }
            $seen[ $event_id ] = true;

            if ( self::opens_at( $instance['start_at'], (int) $instance['banner_days_before'] ) > $now ) {
                continue;
            }

            $out[] = $instance;

            if ( count( $out ) >= $limit ) {
                break;
            }
        }

        return $out;
    }

    /**
     * Ab wann der Banner sichtbar ist: Tagesbeginn, $days Tage vor dem Start.
     *
     * Bewusst auf dem Datum gerechnet, nicht auf Stunden – „ab 14 Tagen
     * vorher“ soll morgens beginnen, nicht zur Uhrzeit des Termins.
     */
    public static function opens_at( string $start_at, int $days ): string {
        $days = max( 0, min( self::MAX_DAYS, $days ) );

        return ( new \DateTime( substr( $start_at, 0, 10 ) ) )
            ->modify( "-{$days} days" )
            ->format( 'Y-m-d 00:00:00' );
    }

    /**
     * Relative Angabe für den Banner: „Läuft gerade“, „Heute“, „Morgen“,
     * „In 5 Tagen“.
     */
    public static function countdown( array $instance, string $now ): string {
        if ( $instance['start_at'] <= $now ) {
            return __( 'Läuft gerade', 'vereins-plugin' );
        }

        $today = new \DateTime( substr( $now, 0, 10 ) );
        $day   = new \DateTime( substr( $instance['start_at'], 0, 10 ) );
        $diff  = (int) $today->diff( $day )->days;

        if ( $diff === 0 ) {
            return __( 'Heute', 'vereins-plugin' );
        }
        if ( $diff === 1 ) {
            return __( 'Morgen', 'vereins-plugin' );
        }

        return sprintf(
            /* translators: %d: Anzahl der Tage */
            _n( 'In %d Tag', 'In %d Tagen', $diff, 'vereins-plugin' ),
            $diff
        );
    }

    public static function has_banner( array $event ): bool {
        return isset( $event['banner_days_before'] ) && $event['banner_days_before'] !== null && $event['banner_days_before'] !== '';
    }
}
