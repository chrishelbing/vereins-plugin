<?php

namespace VereinsPlugin\Flyer;

use VereinsPlugin\Events\InstanceLocator;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Photos\PhotoService;
use VereinsPlugin\Support\Branding;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Support\PublicLink;
use VereinsPlugin\Support\QrCode;

/**
 * Aushang und Flyer zu einem Termin.
 *
 * Bewusst kein serverseitiger PDF-Erzeuger: Die üblichen Bibliotheken bringen
 * Schrift- und Umlautprobleme mit und blähen das Plugin auf. Stattdessen eine
 * eigenständige Seite mit Druckstilen – der Browser erzeugt das PDF, Schriften
 * stimmen, und man bekommt die Bildschirmfassung gratis dazu.
 *
 * Drei Formate:
 *   a4      Aushang für Schaukasten und Gemeindehaus, gedacht zum Drucken
 *   quadrat 1080x1080 als Bild für WhatsApp-Gruppen und Status
 *   qr      nur der Code, für eigene Gestaltung
 *
 * Die beiden Bildformate werden im Browser auf eine Leinwand gezeichnet und
 * lassen sich als PNG sichern. Das kostet etwas Rechnerei im Frontend, spart
 * aber jede Abhängigkeit auf dem Server.
 */
class FlyerPage {

    private const FORMATS = [ 'a4', 'quadrat', 'qr' ];

    public function register(): void {
        add_action( 'admin_post_vp_flyer', [ $this, 'render' ] );
    }

    /**
     * Adresse des Flyers. Nur fürs Orgateam gedacht, die Rechteprüfung
     * findet beim Aufruf statt.
     */
    public static function url( int $event_id, string $instance_date, string $format = 'a4' ): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'vp_flyer',
                    'event'  => $event_id,
                    'date'   => $instance_date,
                    'format' => $format,
                ],
                admin_url( 'admin-post.php' )
            ),
            'vp_flyer_' . $event_id . '_' . $instance_date
        );
    }

    public function render(): void {
        $event_id = (int) ( $_GET['event'] ?? 0 );
        $date     = substr( sanitize_text_field( $_GET['date'] ?? '' ), 0, 10 );

        check_admin_referer( 'vp_flyer_' . $event_id . '_' . $date );

        // Flyer erzeugen darf, wer auch Termine betreut. Der Link enthält den
        // öffentlichen Schlüssel – er gehört nicht in jede Hand.
        if ( ! Capabilities::is_orga() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $instance = InstanceLocator::find( $event_id, $date );
        if ( ! $instance ) {
            wp_die( esc_html__( 'Termin nicht gefunden oder noch nicht freigegeben.', 'vereins-plugin' ) );
        }

        $format = sanitize_text_field( $_GET['format'] ?? 'a4' );
        if ( ! in_array( $format, self::FORMATS, true ) ) {
            $format = 'a4';
        }

        // Helferbedarf und Mitbringliste stehen standardmäßig nicht drauf:
        // Ein gedruckter Aushang veraltet, sobald jemand zusagt.
        $extras = ! empty( $_GET['extras'] );

        $this->output( $instance, $date, $format, $extras );
        exit;
    }

    // ------------------------------------------------------------------
    // Inhalte einsammeln
    // ------------------------------------------------------------------

    /**
     * Alles, was auf dem Flyer erscheinen kann – schon aufbereitet, damit die
     * Ausgabe unten nur noch Text einsetzt.
     */
    private function content( array $instance, string $date, bool $extras ): array {
        $event_id = (int) $instance['event_id'];
        $start    = $instance['start_at'];
        $end      = $instance['end_at'];
        $full_day = ! empty( $instance['is_full_day'] );

        $link = PublicLink::url( $this->public_base_url(), $event_id, $date );

        $needs = [];
        if ( $extras ) {
            $items = new ItemModel();
            foreach ( [ 'help' => 'needs_helpers', 'bring' => 'allows_items' ] as $kind => $flag ) {
                if ( empty( $instance[ $flag ] ) ) {
                    continue;
                }
                foreach ( $items->for_instance( $event_id, $date, 0, $kind ) as $item ) {
                    if ( (int) $item['open'] > 0 ) {
                        $needs[] = [
                            'kind'  => $kind,
                            'label' => $item['label'],
                            'open'  => (int) $item['open'],
                        ];
                    }
                }
            }
        }

        // Für die Leinwand reicht die große Variante – ein Original aus der
        // Handykamera würde die Seite nur ausbremsen.
        $cover     = PhotoService::cover_urls( (int) ( $instance['image_attachment_id'] ?? 0 ) );
        $cover_url = $cover ? ( $cover['tile'] ?: $cover['full'] ) : '';

        return [
            'title'     => (string) $instance['title'],
            'type'      => (string) ( $instance['type_name'] ?? '' ),
            'color'     => (string) ( $instance['type_color'] ?? '#3B82F6' ),
            'location'  => (string) ( $instance['location'] ?? '' ),
            'text'      => wp_trim_words( \VereinsPlugin\Support\RichText::plain( (string) ( $instance['description'] ?? '' ), true ), 40 ),
            'badge'     => DateRange::badge( $start, $end ),
            'when'      => DateRange::detail( $start, $end, $full_day ),
            'short'     => DateRange::short( $start, $end, $full_day ),
            'multi_day' => DateRange::is_multi_day( $start, $end ),
            'cover'     => $cover_url,
            'link'      => $link,
            'qr'        => QrCode::svg( $link, [ 'quiet' => 2, 'dark' => '#111827' ] ),
            'qr_matrix' => QrCode::matrix( $link ),
            'needs'     => $needs,
            'brand'     => Branding::all(),
            // Reiner Infotermin: Der QR-Code führt zum Termin, nicht zur Zusage.
            'rsvp'      => ! isset( $instance['allows_rsvp'] ) || (bool) $instance['allows_rsvp'],
        ];
    }

    /**
     * Seite, auf der der Terminshortcode steht – Ziel des QR-Codes.
     *
     * Ohne Treffer landet der Link auf der Startseite; dort greift der
     * Shortcode dann nicht, deshalb wird das dem Orgateam angezeigt.
     */
    private function public_base_url(): string {
        return PublicLink::events_page() ?: home_url( '/' );
    }

    // ------------------------------------------------------------------
    // Ausgabe
    // ------------------------------------------------------------------

    private function output( array $instance, string $date, string $format, bool $extras ): void {
        $c        = $this->content( $instance, $date, $extras );
        $event_id = (int) $instance['event_id'];
        $no_page  = $this->public_base_url() === home_url( '/' );

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );

        // Modulmatrix für die Leinwand – dort wird der Code neu gezeichnet.
        $matrix = [];
        foreach ( $c['qr_matrix']['modules'] as $row ) {
            $matrix[] = implode( '', $row );
        }
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( __( 'Flyer: %s', 'vereins-plugin' ), $c['title'] ) ); ?></title>
<style><?php echo $this->styles( $c['color'] ); ?></style>
</head>
<body>

<div class="bar no-print">
    <div class="bar__group">
        <?php foreach ( [
            'a4'      => __( 'A4 zum Aushängen', 'vereins-plugin' ),
            'quadrat' => __( 'Quadratisch für WhatsApp', 'vereins-plugin' ),
            'qr'      => __( 'Nur QR-Code', 'vereins-plugin' ),
        ] as $key => $label ) : ?>
            <a class="bar__tab<?php echo $format === $key ? ' is-active' : ''; ?>"
               href="<?php echo esc_url( add_query_arg( 'extras', $extras ? 1 : 0, self::url( $event_id, $date, $key ) ) ); ?>">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="bar__group">
        <label class="bar__check">
            <input type="checkbox" id="extras" <?php checked( $extras ); ?>>
            <?php esc_html_e( 'Helferbedarf und Mitbringliste zeigen', 'vereins-plugin' ); ?>
        </label>
        <?php if ( $format === 'a4' ) : ?>
            <button type="button" class="bar__btn" onclick="window.print()"><?php esc_html_e( 'Drucken oder als PDF sichern', 'vereins-plugin' ); ?></button>
        <?php else : ?>
            <button type="button" class="bar__btn" id="save"><?php esc_html_e( 'Als Bild speichern', 'vereins-plugin' ); ?></button>
        <?php endif; ?>
    </div>
</div>

<?php if ( $extras && ! $c['needs'] ) : ?>
    <p class="hint no-print"><?php esc_html_e( 'Für diesen Termin ist derzeit nichts offen – es erscheint kein Bedarf auf dem Flyer.', 'vereins-plugin' ); ?></p>
<?php endif; ?>

<?php if ( $no_page ) : ?>
    <p class="hint hint--warn no-print">
        <?php esc_html_e( 'Achtung: Es wurde keine Seite mit dem Shortcode [vp_events] gefunden. Der QR-Code zeigt deshalb auf die Startseite und führt dort ins Leere.', 'vereins-plugin' ); ?>
    </p>
<?php endif; ?>

<?php if ( $format === 'a4' ) : ?>
    <?php $this->sheet_a4( $c ); ?>
<?php else : ?>
    <div class="stage">
        <canvas id="canvas" width="1080" height="1080"></canvas>
    </div>
<?php endif; ?>

<script>
(function(){
    var url = new URL(window.location.href);
    var box = document.getElementById('extras');
    if (box) {
        box.addEventListener('change', function(){
            url.searchParams.set('extras', box.checked ? '1' : '0');
            window.location.href = url.toString();
        });
    }

    var canvas = document.getElementById('canvas');
    if (!canvas) { return; }

    var DATA = <?php echo wp_json_encode( [
        'format'   => $format,
        'title'    => $c['title'],
        'type'     => $c['type'],
        'color'    => $c['color'],
        'when'     => $c['when'],
        'short'    => $c['short'],
        'multi'    => $c['multi_day'],
        'day'      => $c['badge']['day'],
        'month'    => $c['badge']['month'],
        'location' => $c['location'],
        'cover'    => $c['cover'],
        'needs'    => $c['needs'],
        'brand'    => $c['brand'],
        'matrix'   => $matrix,
        'callout'  => $c['rsvp'] ? __( 'Scannen und zusagen', 'vereins-plugin' ) : __( 'Scannen für alle Infos', 'vereins-plugin' ),
        'helpers'  => __( 'Wir suchen noch', 'vereins-plugin' ),
        'bring'    => __( 'Bitte mitbringen', 'vereins-plugin' ),
        'file'      => sanitize_title( $c['title'] ) . '-' . $date,
        'saveError' => __( 'Das Bild lässt sich nicht sichern, weil das Titelbild von einer anderen Domain stammt. Ein Rechtsklick auf die Vorschau und „Grafik speichern unter“ funktioniert trotzdem.', 'vereins-plugin' ),
    ] ); ?>;

    <?php echo $this->canvas_script(); ?>
})();
</script>
</body>
</html>
        <?php
    }

    /**
     * A4-Aushang als reines HTML – der Browser druckt ihn.
     */
    private function sheet_a4( array $c ): void {
        ?>
        <div class="sheet">
            <div class="sheet__cover"<?php echo $c['cover'] ? ' style="background-image:url(' . esc_url( $c['cover'] ) . ')"' : ''; ?>>
                <div class="sheet__date">
                    <span class="sheet__day"><?php echo esc_html( $c['badge']['day'] ); ?></span>
                    <span class="sheet__month"><?php echo esc_html( $c['badge']['month'] ); ?></span>
                </div>
            </div>

            <div class="sheet__body">
                <?php if ( $c['type'] ) : ?>
                    <p class="sheet__type"><?php echo esc_html( $c['type'] ); ?></p>
                <?php endif; ?>

                <h1 class="sheet__title"><?php echo esc_html( $c['title'] ); ?></h1>

                <p class="sheet__when"><?php echo esc_html( $c['when'] ); ?></p>
                <?php if ( $c['location'] ) : ?>
                    <p class="sheet__where"><?php echo esc_html( $c['location'] ); ?></p>
                <?php endif; ?>

                <?php if ( $c['text'] ) : ?>
                    <p class="sheet__text"><?php echo esc_html( $c['text'] ); ?></p>
                <?php endif; ?>

                <?php if ( $c['needs'] ) : ?>
                    <div class="sheet__needs">
                        <?php
                        foreach ( [ 'help' => __( 'Wir suchen noch', 'vereins-plugin' ), 'bring' => __( 'Bitte mitbringen', 'vereins-plugin' ) ] as $kind => $heading ) :
                            $list = array_values( array_filter( $c['needs'], fn( $n ) => $n['kind'] === $kind ) );
                            if ( ! $list ) {
                                continue;
                            }
                            ?>
                            <div class="sheet__need">
                                <strong><?php echo esc_html( $heading ); ?></strong>
                                <ul>
                                    <?php foreach ( array_slice( $list, 0, 6 ) as $n ) : ?>
                                        <li>
                                            <?php echo esc_html( $n['label'] ); ?>
                                            <?php if ( $n['open'] > 1 ) : ?>
                                                <span class="sheet__open"><?php printf( esc_html__( '%dx', 'vereins-plugin' ), $n['open'] ); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="sheet__cta">
                    <div class="sheet__qr"><?php echo $c['qr']; ?></div>
                    <div class="sheet__ctatext">
                        <?php if ( $c['rsvp'] ) : ?>
                            <strong><?php esc_html_e( 'Scannen und zusagen', 'vereins-plugin' ); ?></strong>
                            <span><?php esc_html_e( 'Mit der Handykamera auf den Code halten. Anmelden ist nicht nötig.', 'vereins-plugin' ); ?></span>
                        <?php else : ?>
                            <strong><?php esc_html_e( 'Scannen für alle Infos', 'vereins-plugin' ); ?></strong>
                            <span><?php esc_html_e( 'Mit der Handykamera auf den Code halten.', 'vereins-plugin' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( Branding::has_any() ) : ?>
                    <div class="sheet__foot">
                        <?php if ( $c['brand']['logo'] ) : ?>
                            <img class="sheet__logo" src="<?php echo esc_url( $c['brand']['logo'] ); ?>" alt="<?php echo esc_attr( $c['brand']['logo_alt'] ); ?>">
                        <?php endif; ?>
                        <div class="sheet__foottext">
                            <?php if ( $c['brand']['name'] ) : ?>
                                <strong><?php echo esc_html( $c['brand']['name'] ); ?></strong>
                            <?php endif; ?>
                            <?php if ( $c['brand']['contact'] ) : ?>
                                <span><?php echo esc_html( $c['brand']['contact'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( $c['brand']['footnote'] ) : ?>
                                <span><?php echo esc_html( $c['brand']['footnote'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Gestaltung
    // ------------------------------------------------------------------

    private function styles( string $accent ): string {
        $accent = preg_match( '/^#[0-9A-Fa-f]{3,8}$/', $accent ) ? $accent : '#3B82F6';

        return '
        /* --accent ist die Farbe des Termintyps und gehört auf den Aushang,
           nicht auf die Bedienleiste. Die Leiste ist Werkzeug, kein Teil des
           Druckerzeugnisses – sie nimmt deshalb das Blau, das im Plugin
           überall für Knöpfe gilt. Sonst wechselt das Bedienelement je
           Termintyp die Farbe und wirkt wie ein Zustand. */
        :root { --accent: ' . $accent . '; --vp-btn: #3b82f6; --vp-btn-hover: #2563eb; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #111827;
               font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }

        .bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 12px;
               align-items: center; justify-content: space-between;
               padding: 10px 16px; background: #fff; border-bottom: 1px solid #e5e7eb; }
        .bar__group { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .bar__tab { padding: 6px 14px; border-radius: 999px; font-size: .85rem; font-weight: 600;
                    text-decoration: none; color: #374151; background: #f3f4f6; }
        .bar__tab:hover { background: #e5e7eb; }
        .bar__tab.is-active { background: var(--vp-btn); color: #fff; }
        .bar__tab.is-active:hover { background: var(--vp-btn-hover); }
        .bar__check { display: inline-flex; align-items: center; gap: 6px; font-size: .85rem; color: #374151; }
        .bar__btn { padding: 7px 16px; border: none; border-radius: 999px; background: var(--vp-btn); color: #fff;
                    font-size: .85rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .bar__btn:hover { background: var(--vp-btn-hover); }

        .hint { margin: 12px 16px 0; padding: 10px 14px; border-radius: 8px;
                background: #eff6ff; color: #1e40af; font-size: .85rem; }
        .hint--warn { background: #fef3c7; color: #92400e; }

        .stage { display: flex; justify-content: center; padding: 24px 16px 60px; }
        #canvas { width: min(540px, 92vw); height: auto; border-radius: 12px;
                  box-shadow: 0 12px 30px rgba(0,0,0,.15); background: #fff; }

        /* --- A4-Aushang ------------------------------------------------ */
        .sheet { width: 210mm; min-height: 297mm; margin: 24px auto 60px; background: #fff;
                 box-shadow: 0 12px 30px rgba(0,0,0,.15); display: flex; flex-direction: column; overflow: hidden; }
        .sheet__cover { position: relative; height: 105mm; background-color: var(--accent);
                        background-size: cover; background-position: center; }
        .sheet__date { position: absolute; left: 14mm; bottom: 10mm; display: flex; flex-direction: column;
                       align-items: center; line-height: 1; color: #fff; text-shadow: 0 2px 6px rgba(0,0,0,.45); }
        .sheet__day { font-size: 28mm; font-weight: 800; letter-spacing: -.02em; }
        .sheet__month { margin-top: 2mm; font-size: 8mm; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }

        .sheet__body { flex: 1; display: flex; flex-direction: column; padding: 14mm 14mm 12mm; }
        .sheet__type { margin: 0 0 3mm; color: var(--accent); font-size: 6mm; font-weight: 700;
                       text-transform: uppercase; letter-spacing: .1em; }
        .sheet__title { margin: 0 0 6mm; font-size: 18mm; line-height: 1.05; font-weight: 800; }
        .sheet__when { margin: 0 0 2mm; font-size: 7mm; font-weight: 700; }
        .sheet__where { margin: 0 0 6mm; font-size: 6mm; color: #4b5563; }
        .sheet__text { margin: 0 0 6mm; font-size: 5mm; line-height: 1.5; color: #374151; }

        .sheet__needs { display: flex; gap: 10mm; margin-bottom: 6mm; padding: 5mm 6mm;
                        border-left: 2mm solid var(--accent); background: #f9fafb; }
        .sheet__need strong { display: block; margin-bottom: 2mm; font-size: 4.5mm;
                              text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
        .sheet__need ul { margin: 0; padding-left: 5mm; font-size: 5mm; line-height: 1.5; }
        .sheet__open { color: #6b7280; }

        .sheet__cta { display: flex; align-items: center; gap: 6mm; margin-top: auto;
                      padding: 6mm; border: 0.6mm solid #e5e7eb; border-radius: 3mm; }
        .sheet__qr { flex: none; width: 42mm; }
        .sheet__qr svg { display: block; width: 100%; height: auto; }
        .sheet__ctatext { display: flex; flex-direction: column; gap: 2mm; }
        .sheet__ctatext strong { font-size: 8mm; line-height: 1.1; }
        .sheet__ctatext span { font-size: 4.5mm; color: #4b5563; }

        .sheet__foot { display: flex; align-items: center; gap: 5mm; margin-top: 6mm;
                       padding-top: 4mm; border-top: 0.3mm solid #e5e7eb; }
        .sheet__logo { max-height: 16mm; max-width: 40mm; width: auto; }
        .sheet__foottext { display: flex; flex-direction: column; gap: 1mm; font-size: 4mm; color: #4b5563; }
        .sheet__foottext strong { color: #111827; font-size: 4.5mm; }

        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: 210mm; height: 297mm; margin: 0; box-shadow: none; }
            .sheet__cover { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media screen and (max-width: 900px) {
            .sheet { width: 100%; min-height: 0; }
        }
        ';
    }

    /**
     * Zeichnet die Bildformate auf die Leinwand.
     *
     * Der Umweg über Leinwand statt HTML-Screenshot ist Absicht: So entsteht
     * eine echte PNG-Datei mit fester Kantenlänge, die sich unverändert in
     * eine Gruppe stellen lässt.
     */
    private function canvas_script(): string {
        return <<<'JS'
    var ctx = canvas.getContext('2d');
    var W = 1080;

    function rounded(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y,     x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x,     y + h, r);
        ctx.arcTo(x,     y + h, x,     y,     r);
        ctx.arcTo(x,     y,     x + w, y,     r);
        ctx.closePath();
    }

    // Text umbrechen und die belegte Höhe zurückgeben.
    function wrap(text, x, y, maxWidth, lineHeight, maxLines) {
        var words = String(text).split(/\s+/);
        var line = '', lines = [];
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + ' ' + words[i] : words[i];
            if (ctx.measureText(test).width > maxWidth && line) {
                lines.push(line); line = words[i];
            } else {
                line = test;
            }
        }
        if (line) { lines.push(line); }
        if (maxLines && lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            lines[maxLines - 1] = lines[maxLines - 1].replace(/\s+\S*$/, '') + '…';
        }
        lines.forEach(function(l, i){ ctx.fillText(l, x, y + i * lineHeight); });
        return lines.length * lineHeight;
    }

    function drawQr(x, y, size) {
        var rows = DATA.matrix, n = rows.length;
        var quiet = 2, unit = size / (n + quiet * 2);
        ctx.fillStyle = '#fff';
        rounded(x, y, size, size, 16); ctx.fill();
        ctx.fillStyle = '#111827';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (rows[r][c] === '1') {
                    // Ein halbes Pixel Überstand vermeidet helle Fugen.
                    ctx.fillRect(x + (c + quiet) * unit, y + (r + quiet) * unit, unit + 0.5, unit + 0.5);
                }
            }
        }
    }

    function drawSquare(cover) {
        var H = 1080;
        canvas.width = W; canvas.height = H;

        // Bildhälfte oben, darunter die Angaben.
        var coverH = 470;
        ctx.fillStyle = DATA.color;
        ctx.fillRect(0, 0, W, coverH);

        if (cover) {
            var scale = Math.max(W / cover.width, coverH / cover.height);
            var cw = cover.width * scale, ch = cover.height * scale;
            ctx.drawImage(cover, (W - cw) / 2, (coverH - ch) / 2, cw, ch);
            var grad = ctx.createLinearGradient(0, coverH * 0.35, 0, coverH);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(1, 'rgba(0,0,0,0.55)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, W, coverH);
        }

        // Datumsblock: von unten her gesetzt, deshalb an der Grundlinie.
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#fff';
        ctx.font = '800 ' + (DATA.multi ? 96 : 128) + 'px system-ui, sans-serif';
        ctx.fillText(DATA.day, 64, coverH - 96);
        ctx.font = '700 40px system-ui, sans-serif';
        ctx.fillText(String(DATA.month).toUpperCase(), 64, coverH - 44);

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, coverH, W, H - coverH);

        // Ab hier von der Oberkante aus rechnen. An der Grundlinie musste man
        // die Höhe der FOLGENDEN Zeile im Vorgriff einkalkulieren - genau da
        // rutschten Typ und Titel ineinander.
        ctx.textBaseline = 'top';

        var x = 64;
        var textWidth = 620;      // rechts daneben steht der QR-Code
        var y = coverH + 56;

        if (DATA.type) {
            ctx.fillStyle = DATA.color;
            ctx.font = '700 30px system-ui, sans-serif';
            ctx.fillText(String(DATA.type).toUpperCase(), x, y);
            y += 30 + 18;         // Schrifthöhe plus Abstand
        }

        ctx.fillStyle = '#111827';
        ctx.font = '800 68px system-ui, sans-serif';
        y += wrap(DATA.title, x, y, textWidth, 78, 2) + 20;

        ctx.fillStyle = '#111827';
        ctx.font = '600 34px system-ui, sans-serif';
        y += wrap(DATA.when, x, y, textWidth, 44, 2) + 6;

        if (DATA.location) {
            ctx.fillStyle = '#4b5563';
            ctx.font = '400 32px system-ui, sans-serif';
            y += wrap(DATA.location, x, y, textWidth, 42, 1) + 6;
        }

        // Offener Bedarf, sofern gewünscht und vorhanden
        if (DATA.needs && DATA.needs.length) {
            var pick = function(kind, label) {
                var list = DATA.needs.filter(function(n){ return n.kind === kind; });
                return list.length ? label + ': ' + list.slice(0, 3).map(function(n){ return n.label; }).join(', ') : null;
            };
            var parts = [pick('help', DATA.helpers), pick('bring', DATA.bring)].filter(Boolean);

            if (parts.length) {
                y += 18;
                ctx.fillStyle = DATA.color;
                ctx.fillRect(x, y, 6, parts.length * 40 - 8);
                ctx.fillStyle = '#374151';
                ctx.font = '600 28px system-ui, sans-serif';
                parts.forEach(function(p, i){ wrap(p, x + 24, y + i * 40, textWidth - 24, 36, 1); });
                y += parts.length * 40;
            }
        }

        // QR unten rechts, mit Aufforderung darunter
        var qrSize = 300;
        drawQr(W - qrSize - 64, H - qrSize - 150, qrSize);
        ctx.fillStyle = '#111827';
        ctx.font = '700 30px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(DATA.callout, W - qrSize / 2 - 64, H - 132);
        ctx.textAlign = 'left';

        drawFoot(H);
    }

    function drawQrOnly(logo) {
        var H = 1080;
        canvas.width = W; canvas.height = H;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, W, H);

        drawQr(140, 120, 800);

        ctx.textBaseline = 'top';
        ctx.fillStyle = '#111827';
        ctx.textAlign = 'center';
        ctx.font = '800 46px system-ui, sans-serif';
        wrap(DATA.title, W / 2, 960, 900, 56, 1);
        ctx.textAlign = 'left';
    }

    function drawFoot(H) {
        var brand = DATA.brand || {};
        var line = [brand.name, brand.contact, brand.footnote].filter(Boolean).join('  ·  ');
        if (!line) { return; }
        ctx.textBaseline = 'top';
        ctx.fillStyle = '#6b7280';
        ctx.font = '400 24px system-ui, sans-serif';
        wrap(line, 64, H - 56, W - 460, 30, 1);
    }

    function load(url) {
        return new Promise(function(resolve){
            if (!url) { resolve(null); return; }
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload  = function(){ resolve(img); };
            img.onerror = function(){ resolve(null); };   // ohne Bild geht es auch
            img.src = url;
        });
    }

    load(DATA.format === 'quadrat' ? DATA.cover : '').then(function(cover){
        if (DATA.format === 'quadrat') { drawSquare(cover); } else { drawQrOnly(); }
    });

    document.getElementById('save').addEventListener('click', function(){
        try {
            var a = document.createElement('a');
            a.download = DATA.file + '-' + DATA.format + '.png';
            a.href = canvas.toDataURL('image/png');
            a.click();
        } catch (e) {
            // Liegt das Titelbild auf einer fremden Domain, sperrt der
            // Browser das Auslesen der Leinwand.
            alert(DATA.saveError);
        }
    });
JS;
    }
}
