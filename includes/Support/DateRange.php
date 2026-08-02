<?php

namespace VereinsPlugin\Support;

/**
 * Einheitliche Darstellung von Terminzeiträumen.
 *
 * Hintergrund: Ein Camp von Freitag 16 Uhr bis Sonntag 17 Uhr ist nicht
 * „ganztägig“ – es geht über mehrere Tage, und beide Eckpunkte sind wichtig.
 * Wer nur „Ganztägig“ liest, packt womöglich für einen Tag.
 *
 * Deshalb an einer Stelle gebündelt: Frontend-Kachel, Admin-Liste und
 * Teilnehmerlisten sollen denselben Zeitraum gleich beschreiben.
 */
class DateRange {

    /**
     * Geht der Termin über mehr als einen Kalendertag?
     *
     * Ein Termin, der punktgenau um Mitternacht endet (20:00–00:00), gehört
     * noch zum Vortag – sonst gälte jede lange Feier als mehrtägig.
     */
    public static function is_multi_day( string $start, string $end ): bool {
        return self::start_date( $start ) !== self::end_date( $end );
    }

    /**
     * Anzahl betroffener Kalendertage, mindestens 1.
     */
    public static function days( string $start, string $end ): int {
        $a = new \DateTime( self::start_date( $start ) );
        $b = new \DateTime( self::end_date( $end ) );

        return (int) $a->diff( $b )->days + 1;
    }

    /**
     * Kurzform für die Kachel und die Listenzeile.
     *
     *   eintägig, ganztägig   „Ganztägig“
     *   eintägig, mit Zeit    „16:00 – 17:00 Uhr“
     *   mehrtägig             „Mehrtägig · 3 Tage“
     */
    public static function short( string $start, string $end, bool $full_day = false ): string {
        if ( self::is_multi_day( $start, $end ) ) {
            return sprintf(
                /* translators: %d: Anzahl der Tage */
                _n( 'Mehrtägig · %d Tag', 'Mehrtägig · %d Tage', self::days( $start, $end ), 'vereins-plugin' ),
                self::days( $start, $end )
            );
        }

        if ( $full_day ) {
            return __( 'Ganztägig', 'vereins-plugin' );
        }

        return sprintf(
            /* translators: 1: Startzeit, 2: Endzeit */
            __( '%1$s – %2$s Uhr', 'vereins-plugin' ),
            mysql2date( 'H:i', $start ),
            mysql2date( 'H:i', $end )
        );
    }

    /**
     * Ausführliche Angabe mit Wochentagen – nur sinnvoll bei mehrtägigen
     * Terminen, dort aber unverzichtbar.
     *
     *   mit Uhrzeit   „Fr, 12. Sep., 16:00 Uhr – So, 14. Sep., 17:00 Uhr“
     *   ganztägig     „Fr, 12. Sep. – So, 14. Sep.“
     */
    public static function detail( string $start, string $end, bool $full_day = false ): string {
        $day = 'D, j. M.';

        // Eintägig: Den Tag nur einmal nennen. „Fr, 11. Sep. – Fr, 11. Sep.“
        // liest sich wie ein Fehler.
        if ( ! self::is_multi_day( $start, $end ) ) {
            if ( $full_day ) {
                return mysql2date( $day, $start );
            }
            return sprintf(
                /* translators: 1: Tag, 2: Startzeit, 3: Endzeit */
                __( '%1$s, %2$s – %3$s Uhr', 'vereins-plugin' ),
                mysql2date( $day, $start ),
                mysql2date( 'H:i', $start ),
                mysql2date( 'H:i', $end )
            );
        }

        if ( $full_day ) {
            return sprintf(
                /* translators: 1: Starttag, 2: Endtag */
                __( '%1$s – %2$s', 'vereins-plugin' ),
                mysql2date( $day, $start ),
                mysql2date( $day, self::end_date( $end ) )
            );
        }

        return sprintf(
            /* translators: 1: Starttag mit Uhrzeit, 2: Endtag mit Uhrzeit */
            __( '%1$s – %2$s Uhr', 'vereins-plugin' ),
            mysql2date( $day . ' H:i', $start ),
            mysql2date( $day . ' H:i', $end )
        );
    }

    /**
     * Beschriftung des Datumsfelds auf der Kachel.
     *
     * Bei mehrtägigen Terminen die Spanne, damit schon die Kachel zeigt, dass
     * es um mehr als einen Tag geht.
     *
     * @return array{day:string, month:string}
     */
    public static function badge( string $start, string $end ): array {
        $from = mysql2date( 'j', $start );
        $to   = mysql2date( 'j', self::end_date( $end ) );

        if ( ! self::is_multi_day( $start, $end ) ) {
            return [ 'day' => $from . '.', 'month' => mysql2date( 'M', $start ) ];
        }

        $month_from = mysql2date( 'M', $start );
        $month_to   = mysql2date( 'M', self::end_date( $end ) );

        return [
            'day'   => $from . '.–' . $to . '.',
            // Über den Monatswechsel hinweg beide Monate nennen.
            'month' => $month_from === $month_to ? $month_from : $month_from . '/' . $month_to,
        ];
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private static function start_date( string $start ): string {
        return substr( $start, 0, 10 );
    }

    /**
     * Endet der Termin genau um 00:00, zählt der Vortag als letzter Tag.
     *
     * Bewusst rein auf der Zeichenkette gerechnet: Die Werte stehen als lokale
     * Vereinszeit in der Datenbank. Ein Umweg über strtotime()/gmdate() würde
     * sie durch die Server-Zeitzone schicken und das Datum verschieben.
     */
    private static function end_date( string $end ): string {
        $date = substr( $end, 0, 10 );
        $time = substr( $end, 11, 8 );

        if ( $time !== '00:00:00' ) {
            return $date;
        }

        return ( new \DateTime( $date ) )->modify( '-1 day' )->format( 'Y-m-d' );
    }
}
