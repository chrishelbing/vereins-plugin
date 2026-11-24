<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\Settings;
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
        add_action( 'wp_body_open', [ $this, 'notice_bar' ], 5 );
    }

    /**
     * Hinweisleiste: eine schmale Zeile ganz oben auf der Website (vor dem
     * Kopf des Themes, über wp_body_open) – gedacht fürs Handy, wo ein
     * zweiter großer Banner zu viel Platz kostet. Zeigt die Umfrage mit
     * „Hinweisleiste“, die zuerst endet. Wegklicken (×) merkt sich der
     * Browser für diese Umfrage. Auf der Zielseite selbst entfällt sie.
     */
    public function notice_bar(): void {
        if ( is_admin() || ( Settings::get( 'notice_bar_scope', 'all' ) === 'home' && ! is_front_page() ) ) {
            return;
        }
        $now  = current_time( 'Y-m-d H:i:s' );
        $wahl = null;
        foreach ( ( new PollModel() )->all( 'open' ) as $p ) {
            if ( (int) $p['banner'] !== 2 || ( ! empty( $p['closes_at'] ) && $p['closes_at'] <= $now ) ) {
                continue;
            }
            $ende = (string) ( $p['closes_at'] ?: '9999' );
            if ( ! $wahl || $ende < (string) ( $wahl['closes_at'] ?: '9999' ) ) {
                $wahl = $p;
            }
        }
        if ( ! $wahl ) {
            return;
        }
        $tag   = $wahl['kind'] === 'entry' ? EntriesShortcode::TAG : PollsShortcode::TAG;
        $seite = PublicLink::page_with_shortcode( $tag );
        if ( $seite === '' || ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), $tag ) ) ) {
            return;
        }
        $arten = [ 'date' => __( 'Terminumfrage', 'vereins-plugin' ), 'choice' => __( 'Abstimmung', 'vereins-plugin' ), 'text' => __( 'Ideensammlung', 'vereins-plugin' ), 'entry' => __( 'Wettkampfmeldung', 'vereins-plugin' ) ];
        $key   = 'vpBar' . (int) $wahl['id'];
        ?>
        <div class="vp-noticebar" data-key="<?php echo esc_attr( $key ); ?>" role="region" aria-label="<?php esc_attr_e( 'Hinweis', 'vereins-plugin' ); ?>">
            <style>
            .vp-noticebar { position: relative; z-index: 9999; background: var(--wp--preset--color--primary, var(--ast-global-color-0, #2563eb)); color: #fff; font-size: 14px; line-height: 1.3; }
            .vp-noticebar[hidden] { display: none; }
            .vp-noticebar__link { display: flex; align-items: center; gap: 10px; max-width: 1200px; margin: 0 auto; padding: 9px 48px 9px 16px; color: #fff !important; text-decoration: none !important; }
            .vp-noticebar__link:hover .vp-noticebar__go, .vp-noticebar__link:focus .vp-noticebar__go { text-decoration: underline; }
            .vp-noticebar__kind { flex: none; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,.2); }
            .vp-noticebar__title { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
            .vp-noticebar__when { flex: none; opacity: .85; }
            .vp-noticebar__go { flex: none; font-weight: 700; white-space: nowrap; }
            .vp-noticebar__close { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); width: 32px; height: 32px; border: 0; border-radius: 50%; background: transparent; color: #fff; font-size: 20px; line-height: 1; cursor: pointer; padding: 0; }
            .vp-noticebar__close:hover, .vp-noticebar__close:focus { background: rgba(255,255,255,.2); color: #fff; }
            @media (max-width: 600px) { .vp-noticebar__kind, .vp-noticebar__when { display: none; } .vp-noticebar__link { padding: 8px 44px 8px 12px; } }
            </style>
            <a class="vp-noticebar__link" href="<?php echo esc_url( $seite . '#umfrage-' . (int) $wahl['id'] ); ?>">
                <span class="vp-noticebar__kind"><?php echo esc_html( $arten[ $wahl['kind'] ] ?? __( 'Umfrage', 'vereins-plugin' ) ); ?></span>
                <span class="vp-noticebar__title"><?php echo esc_html( $wahl['title'] ); ?></span>
                <?php if ( ! empty( $wahl['closes_at'] ) ) : ?><span class="vp-noticebar__when"><?php echo esc_html( self::countdown( $wahl['closes_at'], $now ) ); ?></span><?php endif; ?>
                <span class="vp-noticebar__go"><?php echo esc_html( self::button( (string) $wahl['kind'] ) ); ?> →</span>
            </a>
            <button type="button" class="vp-noticebar__close" aria-label="<?php esc_attr_e( 'Hinweis schließen', 'vereins-plugin' ); ?>">×</button>
            <script>
            (function () {
                var bar = document.currentScript.closest('.vp-noticebar');
                var k = bar.getAttribute('data-key');
                try { if (localStorage.getItem(k)) { bar.hidden = true; return; } } catch (e) {}
                bar.querySelector('.vp-noticebar__close').addEventListener('click', function () {
                    bar.hidden = true;
                    try { localStorage.setItem(k, '1'); } catch (e) {}
                });
            })();
            </script>
        </div>
        <?php
    }

    /** @return array<int, array{sort:string, html:string}> */
    public function items( $items, $now = null ): array {
        $items = (array) $items;
        $now   = $now ?: current_time( 'Y-m-d H:i:s' );
        foreach ( ( new PollModel() )->all( 'open' ) as $p ) {
            if ( (int) $p['banner'] !== 1 || ( ! empty( $p['closes_at'] ) && $p['closes_at'] <= $now ) ) {
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
