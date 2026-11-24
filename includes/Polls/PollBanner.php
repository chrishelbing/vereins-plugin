<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Support\Icons;
use VereinsPlugin\Support\PublicLink;

/**
 * Umfragen im Banner ([vp_banner]): offene Umfragen mit „Als Banner auf der
 * Website zeigen“ teilen sich den Platz mit den Termin-Bannern.
 *
 * Geliefert wird über den Filter 'vp_banner_items' (Basis-Vertrag, siehe
 * Frontend\BannerShortcode) – fertiges HTML in den Klassen des Banners und
 * ein Sortierschlüssel: das Ende der Umfrage, bei Wettkampfmeldungen der
 * Meldeschluss; ohne Ende „jetzt“ (dann steht sie vorn).
 *
 * Ziel des Klicks ist die erste veröffentlichte Seite mit [vp_umfragen] bzw.
 * [vp_wettkampfmeldungen], dort die Umfrage aufgeklappt (#umfrage-ID). Gibt es
 * keine solche Seite, erscheint kein Banner – ein Banner ohne Ziel hilft
 * niemandem.
 */
class PollBanner {

    public function register(): void {
        add_filter( 'vp_banner_items', [ $this, 'items' ], 10, 2 );
    }

    /** @return array<int, array{sort:string, html:string}> */
    public function items( $items, $now = null ): array {
        $items = (array) $items;
        $now   = $now ?: current_time( 'Y-m-d H:i:s' );
        foreach ( ( new PollModel() )->all( 'open' ) as $p ) {
            if ( empty( $p['banner'] ) || ( ! empty( $p['closes_at'] ) && $p['closes_at'] <= $now ) ) {
                continue;
            }
            $seite = PublicLink::page_with_shortcode( $p['kind'] === 'entry' ? EntriesShortcode::TAG : PollsShortcode::TAG );
            if ( $seite === '' ) {
                continue;
            }
            $items[] = [
                'sort' => ! empty( $p['closes_at'] ) ? (string) $p['closes_at'] : $now,
                'html' => self::html( $p, $seite . '#umfrage-' . (int) $p['id'], $now ),
            ];
        }
        return $items;
    }

    /** Knopf je Art: abstimmen, Termin wählen, Idee einreichen, melden. */
    public static function button( string $kind ): string {
        switch ( $kind ) {
            case 'date':
                return __( 'Termin mit aussuchen', 'vereins-plugin' );
            case 'text':
                return __( 'Idee einreichen', 'vereins-plugin' );
            case 'entry':
                return __( 'Jetzt melden', 'vereins-plugin' );
            default:
                return __( 'Jetzt abstimmen', 'vereins-plugin' );
        }
    }

    /** „Endet heute“, „Endet morgen“, „Noch 5 Tage“, ohne Ende „Läuft“. */
    public static function countdown( ?string $ende, string $now ): string {
        if ( ! $ende ) {
            return __( 'Läuft', 'vereins-plugin' );
        }
        $tage = (int) ( new \DateTime( substr( $now, 0, 10 ) ) )->diff( new \DateTime( substr( $ende, 0, 10 ) ) )->days;
        if ( $tage === 0 ) {
            return __( 'Endet heute', 'vereins-plugin' );
        }
        if ( $tage === 1 ) {
            return __( 'Endet morgen', 'vereins-plugin' );
        }
        /* translators: %d: Anzahl der Tage */
        return sprintf( _n( 'Noch %d Tag', 'Noch %d Tage', $tage, 'vereins-plugin' ), $tage );
    }

    private static function html( array $p, string $url, string $now ): string {
        $arten = [
            'date'  => __( 'Terminumfrage', 'vereins-plugin' ),
            'choice'=> __( 'Abstimmung', 'vereins-plugin' ),
            'text'  => __( 'Ideensammlung', 'vereins-plugin' ),
            'entry' => __( 'Wettkampfmeldung', 'vereins-plugin' ),
        ];
        // Datum im Farbfeld: bei Meldungen der Wettkampftag, sonst das Ende.
        $tag = $p['kind'] === 'entry' && ! empty( $p['event_date'] ) ? $p['event_date'] . ' 00:00:00' : ( $p['closes_at'] ?? '' );
        $text = wp_trim_words( wp_strip_all_tags( (string) ( $p['description'] ?? '' ) ), 28 );
        $bis  = ! empty( $p['closes_at'] )
            /* translators: %s: Datum und Uhrzeit */
            ? sprintf( $p['kind'] === 'entry' ? __( 'Meldeschluss %s', 'vereins-plugin' ) : __( 'Mitmachen bis %s', 'vereins-plugin' ), mysql2date( 'd.m.Y, H:i', $p['closes_at'] ) )
            : '';
        ob_start();
        ?>
        <a class="vp-banner vp-banner--poll" href="<?php echo esc_url( $url ); ?>">
            <span class="vp-banner__media">
                <?php if ( $tag ) : ?>
                    <span class="vp-banner__date">
                        <span class="vp-banner__day"><?php echo esc_html( mysql2date( 'j', $tag ) . '.' ); ?></span>
                        <span class="vp-banner__month"><?php echo esc_html( mysql2date( 'M', $tag ) ); ?></span>
                    </span>
                <?php endif; ?>
            </span>
            <span class="vp-banner__body">
                <span class="vp-banner__eyebrow">
                    <span class="vp-banner__countdown"><?php echo esc_html( self::countdown( $p['closes_at'] ?? null, $now ) ); ?></span>
                    <span class="vp-banner__type"><?php echo esc_html( $arten[ $p['kind'] ] ?? __( 'Umfrage', 'vereins-plugin' ) ); ?></span>
                </span>
                <span class="vp-banner__title"><?php echo esc_html( $p['title'] ); ?></span>
                <?php if ( $bis !== '' ) : ?>
                    <span class="vp-banner__meta"><span class="vp-banner__metaitem"><?php echo Icons::svg( 'clock' ); // phpcs:ignore ?> <?php echo esc_html( $bis ); ?></span></span>
                <?php endif; ?>
                <?php if ( $text !== '' ) : ?>
                    <span class="vp-banner__text"><?php echo esc_html( $text ); ?></span>
                <?php endif; ?>
                <span class="vp-banner__button"><?php echo esc_html( self::button( (string) $p['kind'] ) ); ?> <?php echo Icons::svg( 'arrow' ); // phpcs:ignore ?></span>
            </span>
        </a>
        <?php
        return (string) ob_get_clean();
    }
}
