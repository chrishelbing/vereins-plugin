<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Core\Seeder;
use VereinsPlugin\Frontend\ShortcodeRegistry;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\RichText;

/**
 * wp-admin-Seite "Einstellungen".
 */
class SettingsPage {

    public function register(): void {
        add_action( 'admin_post_vp_save_settings',  [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_seed_defaults',  [ $this, 'handle_seed' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media' ] );
    }

    /**
     * Die Mediathek-Auswahl braucht die Skripte des Medienmanagers. Nur auf
     * dieser einen Seite laden – anderswo wäre es unnötiger Ballast.
     */
    public function enqueue_media( string $hook ): void {
        if ( str_contains( $hook, 'vp-settings' ) ) {
            wp_enqueue_media();
        }
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $settings = Settings::all();

        AdminStyles::print_inline();
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Einstellungen', 'vereins-plugin' ); ?></h1>
            <?php $this->render_notices(); ?>
            <?php $this->render_seed_card(); ?>

            <?php $this->render_layout_styles(); ?>
            <div class="vp-settings">
            <nav class="vp-settings__nav" aria-label="<?php esc_attr_e( 'Bereiche der Einstellungen', 'vereins-plugin' ); ?>">
                <?php foreach ( self::sections() as $gruppe => $eintraege ) : ?>
                    <p class="vp-settings__group"><?php echo esc_html( $gruppe ); ?></p>
                    <?php foreach ( $eintraege as $slug => [ $titel, $icon ] ) : ?>
                        <a href="#<?php echo esc_attr( $slug ); ?>" data-tab="<?php echo esc_attr( $slug ); ?>"><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $titel ); ?></a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="vp-settings__main">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-settings__form">
                    <?php wp_nonce_field( 'vp_save_settings' ); ?>
                    <input type="hidden" name="action" value="vp_save_settings">
                    <input type="hidden" name="vp_tab" value="allgemein" data-tab-field>

                    <section class="vp-panel" id="allgemein">
                    <h2><?php esc_html_e( 'Allgemein', 'vereins-plugin' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Verein, Absender der E-Mails und wie neue Mitglieder dazukommen.', 'vereins-plugin' ); ?></p>

                    <div class="vp-field">
                        <label for="vp-club-name"><?php esc_html_e( 'Vereinsname', 'vereins-plugin' ); ?></label>
                        <input type="text" id="vp-club-name" name="club_name" value="<?php echo esc_attr( $settings['club_name'] ); ?>">
                        <p class="description"><?php esc_html_e( 'Steht auf Flyern, in Meldelisten (CSV, BJV-Excel) und in E-Mails.', 'vereins-plugin' ); ?></p>
                    </div>

                    <div class="vp-field">
                        <label for="vp-notice-scope"><?php esc_html_e( 'Hinweisleiste für Umfragen', 'vereins-plugin' ); ?></label>
                        <select id="vp-notice-scope" name="notice_bar_scope">
                            <option value="all" <?php selected( $settings['notice_bar_scope'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'auf allen Seiten', 'vereins-plugin' ); ?></option>
                            <option value="home" <?php selected( $settings['notice_bar_scope'] ?? 'all', 'home' ); ?>><?php esc_html_e( 'nur auf der Startseite', 'vereins-plugin' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Schmale Zeile ganz oben für Umfragen mit „Hinweisleiste“ – die, die zuerst endet. Besucher können sie wegklicken.', 'vereins-plugin' ); ?></p>
                    </div>

                    <div class="vp-field">
                        <label for="vp-sender-name"><?php esc_html_e( 'Absendername (für E-Mails)', 'vereins-plugin' ); ?></label>
                        <input type="text" id="vp-sender-name" name="sender_name" value="<?php echo esc_attr( $settings['sender_name'] ); ?>">
                    </div>

                    <div class="vp-field">
                        <label for="vp-notify-email"><?php esc_html_e( 'Benachrichtigungs-E-Mail (neue Anmeldungen)', 'vereins-plugin' ); ?></label>
                        <input type="email" id="vp-notify-email" name="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>">
                    </div>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" name="require_approval" value="1" <?php checked( ! empty( $settings['require_approval'] ) ); ?>>
                            <?php esc_html_e( 'Selbstregistrierungen müssen freigeschaltet werden', 'vereins-plugin' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Wenn deaktiviert, sind neue Anmeldungen über [vp_register] sofort aktiv.', 'vereins-plugin' ); ?></p>
                    </div>
                    </section>

                    <section class="vp-panel" id="flyer">
                    <h2><?php esc_html_e( 'Aushänge und Flyer', 'vereins-plugin' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Diese Angaben erscheinen auf den erzeugten Flyern. Ein Aushang ohne Absender wirkt anonym – wer nachfragen möchte, braucht einen Anhaltspunkt.', 'vereins-plugin' ); ?>
                    </p>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-flyer-contact"><?php esc_html_e( 'Ansprechperson', 'vereins-plugin' ); ?></label>
                            <input type="text" id="vp-flyer-contact" name="flyer_contact"
                                   value="<?php echo esc_attr( $settings['flyer_contact'] ); ?>"
                                   placeholder="<?php esc_attr_e( 'z. B. Fragen an Anna Berger, 0170 1234567', 'vereins-plugin' ); ?>">
                        </div>
                    </div>

                    <div class="vp-field">
                        <label for="vp-flyer-footnote"><?php esc_html_e( 'Fußzeile', 'vereins-plugin' ); ?></label>
                        <input type="text" id="vp-flyer-footnote" name="flyer_footnote"
                               value="<?php echo esc_attr( $settings['flyer_footnote'] ); ?>"
                               placeholder="<?php esc_attr_e( 'z. B. Mühlgasse 4, 12345 Musterstadt', 'vereins-plugin' ); ?>">
                    </div>

                    <div class="vp-field">
                        <label><?php esc_html_e( 'Logo', 'vereins-plugin' ); ?></label>
                        <?php $logo = (int) $settings['club_logo_id']; ?>
                        <div class="vp-logo" data-logo>
                            <div class="vp-logo__preview" data-logo-preview>
                                <?php if ( $logo && wp_get_attachment_image_url( $logo, 'medium' ) ) : ?>
                                    <?php echo wp_get_attachment_image( $logo, 'medium' ); ?>
                                <?php else : ?>
                                    <span class="vp-logo__empty"><?php esc_html_e( 'Kein Logo hinterlegt', 'vereins-plugin' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="club_logo_id" value="<?php echo esc_attr( $logo ); ?>" data-logo-id>
                            <p>
                                <button type="button" class="button" data-logo-pick><?php esc_html_e( 'Aus Mediathek wählen', 'vereins-plugin' ); ?></button>
                                <button type="button" class="button-link" data-logo-clear <?php disabled( ! $logo ); ?>><?php esc_html_e( 'Entfernen', 'vereins-plugin' ); ?></button>
                            </p>
                            <p class="description"><?php esc_html_e( 'Am besten mit durchsichtigem Hintergrund. Auf dem Flyer steht es klein in der Fußzeile.', 'vereins-plugin' ); ?></p>
                        </div>
                    </div>

                    </section>

                    <section class="vp-panel" id="fotos">
                    <h2><?php esc_html_e( 'Fotos', 'vereins-plugin' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Fotos werden beim Hochladen automatisch verkleinert und von Standortdaten befreit. Das spart den meisten Speicherplatz.', 'vereins-plugin' ); ?>
                    </p>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-photo-upload"><?php esc_html_e( 'Upload möglich bis (Tage nach dem Termin)', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-photo-upload" name="photo_upload_days" min="0" max="365"
                                   value="<?php echo esc_attr( $settings['photo_upload_days'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Danach ist die Sammlung vollständig und Teilnehmer werden informiert. 0 = ohne Zeitlimit. Am einzelnen Termin übersteuerbar.', 'vereins-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-photo-retention"><?php esc_html_e( 'Aufbewahrung (Tage nach dem Termin)', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-photo-retention" name="photo_retention_days" min="0" max="3650"
                                   value="<?php echo esc_attr( $settings['photo_retention_days'] ); ?>">
                            <p class="description"><?php esc_html_e( '0 = nicht automatisch löschen.', 'vereins-plugin' ); ?></p>
                        </div>
                        <div class="vp-field">
                            <label for="vp-photo-warn"><?php esc_html_e( 'Vorwarnung (Tage vorher)', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-photo-warn" name="photo_warn_days" min="0" max="90"
                                   value="<?php echo esc_attr( $settings['photo_warn_days'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Uploader bekommen vorab eine E-Mail. 0 = keine Vorwarnung.', 'vereins-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-photo-quota"><?php esc_html_e( 'Speicher je Mitglied und Termin (MB)', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-photo-quota" name="photo_quota_mb" min="0" max="5000"
                                   value="<?php echo esc_attr( $settings['photo_quota_mb'] ); ?>">
                            <p class="description"><?php esc_html_e( '0 = kein Limit.', 'vereins-plugin' ); ?></p>
                        </div>
                        <div class="vp-field">
                            <label for="vp-photo-maxupload"><?php esc_html_e( 'Maximale Dateigröße je Foto (MB)', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-photo-maxupload" name="photo_max_upload_mb" min="1" max="100"
                                   value="<?php echo esc_attr( $settings['photo_max_upload_mb'] ); ?>">
                        </div>
                    </div>

                    <div class="vp-field">
                        <label for="vp-photo-edge"><?php esc_html_e( 'Längste Bildkante nach dem Verkleinern (Pixel)', 'vereins-plugin' ); ?></label>
                        <input type="number" id="vp-photo-edge" name="photo_max_edge" min="400" max="6000" step="100"
                               value="<?php echo esc_attr( $settings['photo_max_edge'] ); ?>">
                        <p class="description"><?php esc_html_e( '2000 px reichen für Bildschirm und Fotoabzüge bis etwa 15 × 20 cm.', 'vereins-plugin' ); ?></p>
                    </div>

                    </section>

                    <section class="vp-panel" id="datenschutz">
                    <h2><?php esc_html_e( 'Datenschutz', 'vereins-plugin' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Wann das Plugin Daten von sich aus löscht. Fotos: siehe „Fotos“. Wettkampfmeldungen: an jeder Meldung („Meldungen löschen … Tage nach dem Wettkampf“).', 'vereins-plugin' ); ?></p>
                    <div class="vp-field">
                        <label for="vp-people-retention"><?php esc_html_e( 'Offene Gast-Kontakte aufbewahren (Tage ohne Zusage/Aktivität)', 'vereins-plugin' ); ?></label>
                        <input type="number" id="vp-people-retention" name="people_retention_days" min="0" max="3650"
                               value="<?php echo esc_attr( $settings['people_retention_days'] ); ?>">
                        <p class="description"><?php esc_html_e( 'Betrifft nur Gäste über Terminlink und wartende Registrierungen (vp_people), nie aufgenommene Mitglieder. 0 = nie automatisch löschen.', 'vereins-plugin' ); ?></p>
                    </div>

                    </section>

                    <section class="vp-panel" id="wettkampf">
                    <h2 id="vp-entry-mails"><?php esc_html_e( 'Wettkampfmeldung: E-Mails an die Melder', 'vereins-plugin' ); ?></h2>
                    <p class="description" style="max-width:60em;">
                        <?php esc_html_e( 'Diese Mails verschickt das Orgateam von der Meldeliste aus, wenn die Meldung an den Veranstalter abgeschickt bzw. vom Veranstalter bestätigt wurde. Jeder Melder bekommt nur seine eigenen Starter.', 'vereins-plugin' ); ?>
                        <br><?php esc_html_e( 'Platzhalter:', 'vereins-plugin' ); ?>
                        <code>{name}</code> <?php esc_html_e( 'Melder', 'vereins-plugin' ); ?> ·
                        <code>{wettkampf}</code> <?php esc_html_e( 'Titel', 'vereins-plugin' ); ?> ·
                        <code>{datum}</code> <?php esc_html_e( 'Wettkampftag', 'vereins-plugin' ); ?> ·
                        <code>{starter}</code> <?php esc_html_e( 'Liste der Starter mit Klasse und Gewicht', 'vereins-plugin' ); ?> ·
                        <code>{verein}</code> ·
                        <code>{absender}</code> <?php esc_html_e( 'wer die Mail auslöst', 'vereins-plugin' ); ?>.
                        <?php esc_html_e( 'Ein leeres Feld setzt den Standardtext wieder ein.', 'vereins-plugin' ); ?>
                    </p>
                    <?php foreach ( [
                        'sent'      => [ __( 'Mail „Meldung abgeschickt“', 'vereins-plugin' ), __( 'geht raus beim Abschließen der Meldung oder einer Nachmeldung', 'vereins-plugin' ) ],
                        'confirmed' => [ __( 'Mail „Meldung bestätigt“', 'vereins-plugin' ), __( 'geht raus bei „Als bestätigt vermerken“', 'vereins-plugin' ) ],
                        'withdrawn' => [ __( 'Mail „Abmeldung weitergegeben“', 'vereins-plugin' ), __( 'geht raus, wenn eine Abmeldung beim Veranstalter erledigt ist', 'vereins-plugin' ) ],
                    ] as $art => [ $titel, $wann ] ) : ?>
                        <details class="vp-fold" <?php echo $art === 'sent' ? 'open' : ''; ?>>
                        <summary><strong><?php echo esc_html( $titel ); ?></strong> <span class="description">– <?php echo esc_html( $wann ); ?></span></summary>
                        <div class="vp-field">
                            <label for="vp-mail-<?php echo esc_attr( $art ); ?>-subject"><?php esc_html_e( 'Betreff', 'vereins-plugin' ); ?></label>
                            <input type="text" class="large-text" id="vp-mail-<?php echo esc_attr( $art ); ?>-subject" name="entry_mail_<?php echo esc_attr( $art ); ?>_subject"
                                   value="<?php echo esc_attr( $settings[ "entry_mail_{$art}_subject" ] ); ?>">
                        </div>
                        <div class="vp-field">
                            <label for="vp-mail-<?php echo esc_attr( $art ); ?>-body"><?php esc_html_e( 'Text', 'vereins-plugin' ); ?></label>
                            <textarea class="large-text" rows="12" id="vp-mail-<?php echo esc_attr( $art ); ?>-body" name="entry_mail_<?php echo esc_attr( $art ); ?>_body"><?php echo esc_textarea( $settings[ "entry_mail_{$art}_body" ] ); ?></textarea>
                        </div>
                        </details>
                    <?php endforeach; ?>
                    </section>

                    <div class="vp-settings__save">
                        <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Einstellungen speichern', 'vereins-plugin' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Speichert alle Bereiche auf einmal.', 'vereins-plugin' ); ?></span>
                    </div>
                </form>

                <section class="vp-panel vp-panel--ref" id="shortcodes">
                    <?php $this->render_shortcodes(); ?>
                </section>
                <section class="vp-panel vp-panel--ref" id="formatierung">
                    <?php $this->render_formatting(); ?>
                </section>
            </div>
            </div>
            <?php $this->render_layout_script(); ?>
        </div>
        <?php
    }

    /**
     * Bereiche der Einstellungen für die Seitenleiste: Gruppe → [Slug → [Titel, Dashicon]].
     * Slugs sind zugleich die Anker (#wettkampf) – Links von anderen Seiten zielen darauf.
     */
    public static function sections(): array {
        return [
            __( 'Einstellen', 'vereins-plugin' )   => [
                'allgemein'   => [ __( 'Allgemein', 'vereins-plugin' ), 'dashicons-admin-generic' ],
                'flyer'       => [ __( 'Aushänge und Flyer', 'vereins-plugin' ), 'dashicons-media-document' ],
                'fotos'       => [ __( 'Fotos', 'vereins-plugin' ), 'dashicons-format-gallery' ],
                'wettkampf'   => [ __( 'Wettkampfmeldung', 'vereins-plugin' ), 'dashicons-awards' ],
                'datenschutz' => [ __( 'Datenschutz', 'vereins-plugin' ), 'dashicons-shield' ],
            ],
            __( 'Nachschlagen', 'vereins-plugin' ) => [
                'shortcodes'   => [ __( 'Shortcodes', 'vereins-plugin' ), 'dashicons-shortcode' ],
                'formatierung' => [ __( 'Text formatieren', 'vereins-plugin' ), 'dashicons-editor-bold' ],
            ],
        ];
    }

    private function render_layout_styles(): void {
        ?>
        <style>
        .vp-settings { display: flex; gap: 24px; align-items: flex-start; margin-top: 16px; }
        .vp-settings__nav { flex: 0 0 210px; position: sticky; top: 46px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 0; }
        .vp-settings__group { margin: 10px 16px 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #646970; }
        .vp-settings__nav a { display: flex; align-items: center; gap: 8px; padding: 8px 16px; color: #1d2327; text-decoration: none; border-left: 3px solid transparent; }
        .vp-settings__nav a:hover, .vp-settings__nav a:focus { background: #f6f7f7; color: #2271b1; box-shadow: none; }
        .vp-settings__nav a.is-active { border-left-color: #2271b1; background: #f0f6fc; color: #2271b1; font-weight: 600; }
        .vp-settings__nav .dashicons { font-size: 18px; width: 18px; height: 18px; }
        .vp-settings__main { flex: 1 1 auto; min-width: 0; max-width: 900px; }
        .vp-settings .vp-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 24px 20px; margin-bottom: 16px; }
        .vp-settings .vp-panel > h2:first-child { margin-top: 16px; }
        .vp-settings .vp-panel--ref h2 { margin-top: 16px !important; }
        .vp-settings__save { position: sticky; bottom: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 24px; display: flex; gap: 12px; align-items: center; box-shadow: 0 -2px 8px rgba(0,0,0,.04); }
        .vp-fold { border: 1px solid #e5e7eb; border-radius: 6px; margin: 12px 0; padding: 0 16px; }
        .vp-fold > summary { cursor: pointer; padding: 12px 0; }
        .vp-admin-wrap .vp-fold .vp-field input[type=text], .vp-admin-wrap .vp-fold .vp-field textarea { max-width: none; }
        .vp-fold[open] > summary { border-bottom: 1px solid #f0f0f1; margin-bottom: 8px; }
        /* Mit Script: nur der gewählte Bereich ist zu sehen. */
        .vp-settings.is-tabbed .vp-panel { display: none; }
        .vp-settings.is-tabbed .vp-panel.is-active { display: block; }
        .vp-settings.is-tabbed.is-ref .vp-settings__save { display: none; }
        @media (max-width: 782px) {
            .vp-settings { flex-direction: column; }
            .vp-settings__nav { position: static; flex: none; width: 100%; display: flex; overflow-x: auto; padding: 0; }
            .vp-settings__group { display: none; }
            .vp-settings__nav a { border-left: 0; border-bottom: 3px solid transparent; white-space: nowrap; }
            .vp-settings__nav a.is-active { border-bottom-color: #2271b1; }
            .vp-settings__main { width: 100%; }
        }
        </style>
        <?php
    }

    /**
     * Ohne Script stehen alle Bereiche untereinander, die Seitenleiste springt
     * per Anker. Mit Script zeigt sie je einen Bereich; der Anker in der
     * Adresse (#fotos) wählt ihn, auch nach dem Speichern.
     */
    private function render_layout_script(): void {
        ?>
        <script>
        (function () {
            var box = document.querySelector('.vp-settings');
            if (!box) { return; }
            var feld = box.querySelector('[data-tab-field]');
            var links = box.querySelectorAll('.vp-settings__nav a');
            function zeige(slug, scroll) {
                var ziel = slug ? document.getElementById(slug) : null;
                var panel = ziel ? ziel.closest('.vp-panel') : null;
                if (!panel) { panel = box.querySelector('.vp-panel'); }
                box.classList.add('is-tabbed');
                box.querySelectorAll('.vp-panel').forEach(function (p) { p.classList.toggle('is-active', p === panel); });
                box.classList.toggle('is-ref', panel.classList.contains('vp-panel--ref'));
                links.forEach(function (a) { a.classList.toggle('is-active', a.dataset.tab === panel.id); });
                if (feld && !panel.classList.contains('vp-panel--ref')) { feld.value = panel.id; }
                if (scroll && ziel !== panel && ziel) { ziel.scrollIntoView(); }
            }
            links.forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    history.replaceState(null, '', '#' + a.dataset.tab);
                    zeige(a.dataset.tab, false);
                });
            });
            window.addEventListener('hashchange', function () { zeige(location.hash.slice(1), true); });
            zeige(location.hash.slice(1), true);
        })();
        </script>
        <?php
    }

    /**
     * Fehlende Startdaten anbieten – ganz oben auf der Seite.
     *
     * Angelegt werden sie sonst nur bei der ersten Aktivierung. Der Marker
     * dafür steht in den Optionen und überlebt das Deaktivieren des Plugins –
     * ein Deaktivieren und erneutes Aktivieren bringt die mitgelieferten
     * Termintypen und Vorlagen also nicht zurück. Das ist Absicht: Sonst
     * stünde alles Weggeräumte nach jeder Wartung wieder da. Wer sie doch
     * will, sagt es hier.
     *
     * Der Kasten erscheint nur, wenn tatsächlich etwas fehlt. Eine Meldung
     * „alles vorhanden“ stünde bei jedem Aufruf über den Einstellungen und
     * wäre nach dem ersten Lesen nur noch Vorspann.
     */
    private function render_seed_card(): void {
        $fehlt  = Seeder::missing();
        $anzahl = $fehlt['types'] + $fehlt['templates'];

        if ( $anzahl === 0 ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'Startdaten', 'vereins-plugin' ); ?></h2>

        <div class="vp-form-card">
            <p class="description">
                <?php
                printf(
                    esc_html__( 'Es fehlen %1$d Termintypen und %2$d Vorlagen aus dem Auslieferungszustand. Angelegt wird nur, was noch nicht da ist – vorhandene Einträge gleichen Namens bleiben unverändert, auch wenn sie angepasst wurden.', 'vereins-plugin' ),
                    (int) $fehlt['types'],
                    (int) $fehlt['templates']
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vp_seed_defaults' ); ?>
                <input type="hidden" name="action" value="vp_seed_defaults">
                <p class="submit">
                    <button type="submit" class="button"><?php esc_html_e( 'Fehlende Startdaten anlegen', 'vereins-plugin' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_seed(): void {
        check_admin_referer( 'vp_seed_defaults' );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $neu = Seeder::seed_on_demand();

        wp_safe_redirect( add_query_arg(
            [
                'page'      => 'vp-settings',
                'vp_notice' => 'seeded',
                'vp_types'  => (int) $neu['types'],
                'vp_lists'  => (int) $neu['templates'],
            ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Wie man die Beschreibung eines Termins formatiert. Die Beispiele kommen
     * aus RichText::syntax(), die Spalte „Ergebnis“ aus RichText::html() –
     * was hier steht, ist also genau das, was auf der Kachel erscheint.
     */
    private function render_formatting(): void {
        ?>
        <h2 style="margin-top:34px;"><?php esc_html_e( 'Beschreibung formatieren', 'vereins-plugin' ); ?></h2>
        <p class="description" style="max-width:70em;">
            <?php esc_html_e( 'Die Beschreibung eines Termins versteht eine einfache Schreibweise (ein kleiner Teil von Markdown) – ohne Menü, nur mit Zeichen im Text. Das gilt in allen Formularen: im Backend, beim Termin vorschlagen und beim Bearbeiten an der Kachel. Am Feld „Beschreibung“ zeigt ein ? dieselben Beispiele.', 'vereins-plugin' ); ?>
        </p>
        <table class="vp-table" style="max-width:70em;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Du schreibst', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Wirkung', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Ergebnis', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( RichText::syntax() as [ $eingabe, $bedeutung ] ) : ?>
                <tr>
                    <td><code style="white-space:pre-line;"><?php echo esc_html( $eingabe ); ?></code></td>
                    <td><?php echo esc_html( $bedeutung ); ?></td>
                    <td class="vp-richtext"><?php echo RichText::html( $eingabe ); // maskiert selbst ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="max-width:70em;">
            <?php esc_html_e( 'Zeilenumbrüche bleiben erhalten. HTML wird nie übernommen, und Links führen nur zu http- und https-Adressen. Sternchen und Unterstriche mitten im Wort bleiben stehen – „Sportler*innen“ wird nicht kursiv. Im Banner und auf dem Flyer erscheint der Text ohne Formatierung, Aufzählungen dort mit •.', 'vereins-plugin' ); ?>
        </p>
        <style>.vp-richtext ul, .vp-richtext ol { margin: 0 0 0 1.3em; } .vp-richtext li { margin: 0; }</style>
        <?php
    }

    /**
     * Übersicht aller Shortcodes.
     *
     * Die Liste kommt aus der ShortcodeRegistry – derselben Quelle, aus der
     * die Shortcodes auch bei WordPress angemeldet werden. Sie kann deshalb
     * nicht veralten.
     */
    private function render_shortcodes(): void {
        $shortcodes   = ShortcodeRegistry::all();
        $undocumented = ShortcodeRegistry::undocumented();
        ?>
        <h2 style="margin-top:34px;"><?php esc_html_e( 'Shortcodes', 'vereins-plugin' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Diese Kürzel fügst du in eine Seite oder einen Beitrag ein. Klick auf ein Kürzel kopiert es in die Zwischenablage.', 'vereins-plugin' ); ?>
        </p>

        <table class="vp-table vp-shortcodes">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Shortcode', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Zweck', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Parameter', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $shortcodes as $sc ) : ?>
                <tr>
                    <td>
                        <button type="button" class="vp-copy" data-copy="<?php echo esc_attr( '[' . $sc['tag'] . ']' ); ?>">
                            <code>[<?php echo esc_html( $sc['tag'] ); ?>]</code>
                        </button>
                    </td>
                    <td>
                        <strong><?php echo esc_html( $sc['title'] ); ?></strong><br>
                        <span class="description"><?php echo esc_html( $sc['description'] ); ?></span>
                        <?php if ( ! empty( $sc['example'] ) && $sc['example'] !== '[' . $sc['tag'] . ']' ) : ?>
                            <br><span class="description">
                                <?php esc_html_e( 'Beispiel:', 'vereins-plugin' ); ?>
                                <button type="button" class="vp-copy" data-copy="<?php echo esc_attr( $sc['example'] ); ?>">
                                    <code><?php echo esc_html( $sc['example'] ); ?></code>
                                </button>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( empty( $sc['atts'] ) ) : ?>
                            <span class="description"><?php esc_html_e( '—', 'vereins-plugin' ); ?></span>
                        <?php else : ?>
                            <ul class="vp-shortcodes__atts">
                                <?php foreach ( $sc['atts'] as $att ) : ?>
                                    <li>
                                        <code><?php echo esc_html( $att[0] ); ?></code>
                                        <span class="description">
                                            <?php printf( esc_html__( 'Standard: %s', 'vereins-plugin' ), esc_html( $att[1] ) ); ?>
                                            – <?php echo esc_html( $att[2] ); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $undocumented ) : ?>
            <div class="notice notice-warning inline" style="margin-top:12px;">
                <p>
                    <?php
                    printf(
                        esc_html__( 'Zusätzlich registriert, aber hier nicht beschrieben: %s', 'vereins-plugin' ),
                        '<code>[' . esc_html( implode( '] [', $undocumented ) ) . ']</code>'
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <style>
        .vp-admin-wrap .vp-shortcodes td { vertical-align: top; }
        .vp-admin-wrap .vp-copy { background: none; border: none; padding: 0; cursor: pointer; }
        .vp-admin-wrap .vp-copy code { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; padding: 3px 8px; font-size: .85rem; }
        .vp-admin-wrap .vp-copy:hover code { background: #e5e7eb; }
        .vp-admin-wrap .vp-copy.is-copied code { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
        .vp-admin-wrap .vp-shortcodes__atts { margin: 0; padding: 0; list-style: none; }
        .vp-admin-wrap .vp-shortcodes__atts li { margin-bottom: 4px; }
        .vp-admin-wrap .vp-logo__preview { display: flex; align-items: center; justify-content: center; width: 220px; min-height: 90px; margin-bottom: 8px; padding: 10px; border: 1px dashed #c3c4c7; border-radius: 8px; background: #fff; }
        .vp-admin-wrap .vp-logo__preview img { max-width: 100%; max-height: 110px; height: auto; }
        .vp-admin-wrap .vp-logo__empty { color: #8c8f94; font-size: 12px; }
        </style>

        <script>
        // Logo über den Medienmanager wählen.
        //
        // Erst nach DOMContentLoaded verdrahten: Dieses Skript steht im
        // Seitenkörper, wp.media wird aber im Fußbereich geladen. Zur Laufzeit
        // hier gibt es wp.media also noch nicht.
        document.addEventListener('DOMContentLoaded', function(){
            var box = document.querySelector('[data-logo]');
            if (!box) { return; }

            if (!window.wp || !wp.media) {
                // Nicht stillschweigend aussteigen – sonst sucht man lange.
                console.error('Vereins-Plugin: Medienmanager nicht geladen, Logo-Auswahl deaktiviert.');
                box.querySelector('[data-logo-pick]').disabled = true;
                return;
            }

            var field   = box.querySelector('[data-logo-id]');
            var preview = box.querySelector('[data-logo-preview]');
            var clear   = box.querySelector('[data-logo-clear]');
            var frame;

            box.querySelector('[data-logo-pick]').addEventListener('click', function(){
                if (!frame) {
                    frame = wp.media({
                        title:    <?php echo json_encode( __( 'Logo wählen', 'vereins-plugin' ) ); ?>,
                        button:   { text: <?php echo json_encode( __( 'Übernehmen', 'vereins-plugin' ) ); ?> },
                        library:  { type: 'image' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        field.value = att.id;
                        var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                        preview.innerHTML = '';
                        var img = document.createElement('img');
                        img.src = url;
                        img.alt = '';
                        preview.appendChild(img);
                        clear.disabled = false;
                    });
                }
                frame.open();
            });

            clear.addEventListener('click', function(){
                field.value = '0';
                preview.innerHTML = <?php echo json_encode( '<span class="vp-logo__empty">' . esc_html__( 'Kein Logo hinterlegt', 'vereins-plugin' ) . '</span>' ); ?>;
                clear.disabled = true;
            });
        });

        (function(){
            document.querySelectorAll('.vp-copy').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var text = btn.dataset.copy;
                    var done = function(){
                        btn.classList.add('is-copied');
                        setTimeout(function(){ btn.classList.remove('is-copied'); }, 1200);
                    };

                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(done).catch(function(){});
                        return;
                    }
                    // Rückfallebene für Browser ohne Clipboard-API
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                });
            });
        })();
        </script>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_settings' );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $vorgabe = Settings::defaults();
        $mail    = static function ( string $key, bool $text ) use ( $vorgabe ): string {
            $wert = wp_unslash( (string) ( $_POST[ $key ] ?? '' ) );
            $wert = $text ? sanitize_textarea_field( $wert ) : sanitize_text_field( $wert );
            return trim( $wert ) !== '' ? $wert : (string) $vorgabe[ $key ];
        };

        Settings::update( [
            'entry_mail_sent_subject'      => $mail( 'entry_mail_sent_subject', false ),
            'entry_mail_sent_body'         => $mail( 'entry_mail_sent_body', true ),
            'entry_mail_confirmed_subject' => $mail( 'entry_mail_confirmed_subject', false ),
            'entry_mail_confirmed_body'    => $mail( 'entry_mail_confirmed_body', true ),
            'entry_mail_withdrawn_subject' => $mail( 'entry_mail_withdrawn_subject', false ),
            'entry_mail_withdrawn_body'    => $mail( 'entry_mail_withdrawn_body', true ),

            'sender_name'      => sanitize_text_field( $_POST['sender_name'] ?? '' ),
            'notify_email'     => sanitize_email( $_POST['notify_email'] ?? '' ),
            'require_approval' => ! empty( $_POST['require_approval'] ) ? 1 : 0,

            'photo_upload_days'    => max( 0, min( 365,  (int) ( $_POST['photo_upload_days'] ?? 14 ) ) ),
            'photo_retention_days' => max( 0, min( 3650, (int) ( $_POST['photo_retention_days'] ?? 90 ) ) ),
            'photo_warn_days'      => max( 0, min( 90,   (int) ( $_POST['photo_warn_days'] ?? 7 ) ) ),
            'photo_quota_mb'       => max( 0, min( 5000, (int) ( $_POST['photo_quota_mb'] ?? 50 ) ) ),
            'photo_max_upload_mb'  => max( 1, min( 100,  (int) ( $_POST['photo_max_upload_mb'] ?? 15 ) ) ),
            'photo_max_edge'       => max( 400, min( 6000, (int) ( $_POST['photo_max_edge'] ?? 2000 ) ) ),

            'people_retention_days' => max( 0, min( 3650, (int) ( $_POST['people_retention_days'] ?? 180 ) ) ),

            'club_name'      => sanitize_text_field( $_POST['club_name'] ?? '' ),
            'notice_bar_scope' => ( $_POST['notice_bar_scope'] ?? '' ) === 'home' ? 'home' : 'all',
            'club_logo_id'   => $this->valid_logo_id( (int) ( $_POST['club_logo_id'] ?? 0 ) ),
            'flyer_contact'  => sanitize_text_field( $_POST['flyer_contact'] ?? '' ),
            'flyer_footnote' => sanitize_text_field( $_POST['flyer_footnote'] ?? '' ),
        ] );

        $tab = sanitize_key( $_POST['vp_tab'] ?? '' );
        $tab = array_key_exists( $tab, array_merge( ...array_values( self::sections() ) ) ) ? '#' . $tab : '';
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-settings', 'vp_notice' => 'saved' ], admin_url( 'admin.php' ) ) . $tab );
        exit;
    }

    /**
     * Nur echte Bilder aus der Mediathek zulassen.
     *
     * Das Feld kommt als Zahl aus dem Formular; ohne Prüfung ließe sich damit
     * eine beliebige Anhang-ID einschleusen und im Flyer einbinden.
     */
    private function valid_logo_id( int $id ): int {
        if ( $id <= 0 ) {
            return 0;
        }
        return wp_attachment_is_image( $id ) ? $id : 0;
    }

    private function render_notices(): void {
        $notice = sanitize_text_field( $_GET['vp_notice'] ?? '' );

        if ( $notice === 'saved' ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Einstellungen gespeichert.', 'vereins-plugin' ) );
        }

        if ( $notice === 'seeded' ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf(
                    __( '%1$d Termintypen und %2$d Vorlagen angelegt.', 'vereins-plugin' ),
                    (int) ( $_GET['vp_types'] ?? 0 ),
                    (int) ( $_GET['vp_lists'] ?? 0 )
                ) )
            );
        }
    }
}
