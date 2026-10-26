<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Events\BannerSelector;
use VereinsPlugin\Events\InstanceLocator;
use VereinsPlugin\Photos\PhotoService;
use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Support\Icons;
use VereinsPlugin\Support\PublicLink;

/**
 * Shortcode [vp_banner]
 *
 * Hebt einen Termin auf einer beliebigen Seite hervor – gedacht für die
 * Startseite. Welcher Termin erscheint, entscheidet ein Administrator am
 * Termin selbst („Als Banner auf der Website zeigen“); die Seite muss dafür
 * nicht angefasst werden. Steht gerade kein Termin an, gibt der Shortcode
 * nichts aus und hinterlässt keine leere Fläche.
 *
 * Anders als [vp_events] ist der Banner für alle sichtbar: Er soll gerade
 * die erreichen, die noch nicht angemeldet sind. Ein Klick führt über den
 * signierten Terminlink zur Zusage – derselbe Weg wie beim QR-Code.
 *
 * Parameter:
 *   limit  – wie viele Banner höchstens untereinander (Standard: 1)
 *   button – Beschriftung der Schaltfläche (Standard: „Jetzt zusagen“)
 */
class BannerShortcode {

    private const STYLE = 'vp-banner';

    public static function describe(): array {
        return [
            'tag'         => 'vp_banner',
            'title'       => __( 'Terminbanner', 'vereins-plugin' ),
            'description' => __( 'Hebt Termine hervor, an denen „Als Banner auf der Website zeigen“ gesetzt ist – mit Titelbild, Datum, Ort und einem Knopf zur Zusage. Für alle Besucher sichtbar, auch ohne Anmeldung; der Klick führt über den öffentlichen Terminlink zur Zusage. Erscheint ab dem am Termin eingestellten Vorlauf und verschwindet nach Terminende von selbst. Steht nichts an, bleibt die Stelle leer.', 'vereins-plugin' ),
            'atts'        => [
                [ 'limit',  '1', __( 'Wie viele Banner höchstens gleichzeitig erscheinen – der nächste Termin zuerst.', 'vereins-plugin' ) ],
                [ 'button', __( 'Jetzt zusagen', 'vereins-plugin' ), __( 'Beschriftung der Schaltfläche.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_banner limit="2"]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_banner', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
    }

    public static function register_assets(): void {
        if ( wp_style_is( self::STYLE, 'registered' ) ) {
            return;
        }
        wp_register_style(
            self::STYLE,
            plugins_url( 'assets/css/banner.css', VP_PLUGIN_FILE ),
            [],
            VP_VERSION
        );
    }

    public function render( $atts ): string {
        $atts = shortcode_atts(
            [ 'limit' => 1, 'button' => __( 'Jetzt zusagen', 'vereins-plugin' ) ],
            (array) $atts
        );
        $limit = max( 1, min( 5, (int) $atts['limit'] ) );

        $now       = current_time( 'Y-m-d H:i:s' );
        $instances = BannerSelector::current( $limit, $now );

        if ( ! $instances ) {
            return '';
        }

        // Nur anfordern, wenn tatsächlich etwas erscheint.
        self::register_assets();
        wp_enqueue_style( self::STYLE );

        // Ohne Terminseite gäbe es kein sinnvolles Ziel – der Banner bleibt
        // dann ein reiner Hinweis ohne Link.
        $base = PublicLink::events_page();

        ob_start();
        echo '<div class="vp-banners">';
        foreach ( $instances as $instance ) {
            $this->render_banner( $instance, $base, (string) $atts['button'], $now );
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    private function render_banner( array $i, string $base, string $button, string $now ): void {
        $event_id = (int) $i['event_id'];
        $date     = InstanceLocator::date_of( $i );
        $full_day = ! empty( $i['is_full_day'] );
        $badge    = DateRange::badge( $i['start_at'], $i['end_at'] );
        $cover    = PhotoService::cover_urls( (int) ( $i['image_attachment_id'] ?? 0 ) );
        $text     = wp_trim_words( wp_strip_all_tags( (string) ( $i['description'] ?? '' ) ), 28 );

        // Die Farbe kommt aus dem Termintyp; nur echte Hex-Werte gelangen ins
        // style-Attribut.
        $color = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $i['type_color'] ?? '' ) )
            ? $i['type_color']
            : '#3B82F6';

        $url = $base
            ? PublicLink::url( $base, $event_id, $date ) . '#' . EventsShortcode::anchor( $event_id, $date )
            : '';

        $tag   = $url ? 'a' : 'div';
        $attrs = sprintf(
            ' class="vp-banner%s" style="--vp-accent:%s"',
            $cover ? ' has-image' : '',
            esc_attr( $color )
        );
        if ( $url ) {
            $attrs .= ' href="' . esc_url( $url ) . '"';
        }
        ?>
        <<?php echo $tag . $attrs; ?>>
            <span class="vp-banner__media"<?php
                if ( $cover ) {
                    echo ' style="background-image:url(' . esc_url( $cover['tile'] ) . ')"';
                }
            ?>>
                <span class="vp-banner__date<?php echo DateRange::is_multi_day( $i['start_at'], $i['end_at'] ) ? ' is-span' : ''; ?>">
                    <span class="vp-banner__day"><?php echo esc_html( $badge['day'] ); ?></span>
                    <span class="vp-banner__month"><?php echo esc_html( $badge['month'] ); ?></span>
                </span>
            </span>

            <span class="vp-banner__body">
                <span class="vp-banner__eyebrow">
                    <span class="vp-banner__countdown"><?php echo esc_html( BannerSelector::countdown( $i, $now ) ); ?></span>
                    <?php if ( ! empty( $i['type_name'] ) ) : ?>
                        <span class="vp-banner__type"><?php echo esc_html( $i['type_name'] ); ?></span>
                    <?php endif; ?>
                </span>

                <span class="vp-banner__title"><?php echo esc_html( $i['title'] ); ?></span>

                <span class="vp-banner__meta">
                    <span class="vp-banner__metaitem">
                        <?php echo Icons::svg( 'clock' ); ?>
                        <?php echo esc_html( DateRange::detail( $i['start_at'], $i['end_at'], $full_day ) ); ?>
                    </span>
                    <?php if ( ! empty( $i['location'] ) ) : ?>
                        <span class="vp-banner__metaitem">
                            <?php echo Icons::svg( 'pin' ); ?>
                            <?php echo esc_html( $i['location'] ); ?>
                        </span>
                    <?php endif; ?>
                </span>

                <?php if ( $text !== '' ) : ?>
                    <span class="vp-banner__text"><?php echo esc_html( $text ); ?></span>
                <?php endif; ?>

                <?php if ( $url ) : ?>
                    <span class="vp-banner__button">
                        <?php echo esc_html( $button ); ?>
                        <?php echo Icons::svg( 'arrow' ); ?>
                    </span>
                <?php endif; ?>
            </span>
        </<?php echo $tag; ?>>
        <?php
    }
}
