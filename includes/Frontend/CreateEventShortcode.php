<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Support\Capabilities;

/**
 * Shortcode [vp_create_event]
 *
 * Rendert ein Formular zum Anlegen von Terminen direkt auf der WordPress-Seite.
 * Offen für jedes freigeschaltete Mitglied. Sichtbar wird der Termin aber erst
 * nach Freigabe durch einen Administrator – außer ein Admin legt ihn selbst an.
 * Submit läuft via fetch() gegen die REST API mit WP-Nonce-Auth.
 */
class CreateEventShortcode {

    public static function describe(): array {
        return [
            'tag'         => 'vp_create_event',
            'title'       => __( 'Termin anlegen', 'vereins-plugin' ),
            'description' => __( 'Formular zum Anlegen eines Termins, offen für jedes freigeschaltete Mitglied. Sichtbar wird der Termin erst nach Freigabe durch einen Administrator – außer ein Admin legt ihn selbst an.', 'vereins-plugin' ),
            'atts'        => [],
            'example'     => '[vp_create_event]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_create_event', [ $this, 'render' ] );
    }

    public function render( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="vp-notice">' . esc_html__( 'Bitte melde dich an.', 'vereins-plugin' ) . '</p>';
        }

        if ( ! Capabilities::is_active_member() ) {
            return '<p class="vp-notice">' . esc_html__( 'Dein Zugang wurde noch nicht freigeschaltet oder ist deaktiviert.', 'vereins-plugin' ) . '</p>';
        }

        $types = ( new EventTypeModel() )->all();
        $nonce = wp_create_nonce( 'wp_rest' );
        $api   = esc_url( rest_url( 'vereins-plugin/v1' ) );

        // Nur was ein Admin selbst anlegt, ist sofort sichtbar.
        $needs_approval = ! Capabilities::is_admin_user();

        ob_start();
        ?>
        <div class="vp-form-wrap" id="vp-create-event">

            <?php if ( $needs_approval ) : ?>
                <p class="vp-notice"><?php esc_html_e( 'Dein Termin wird nach dem Speichern geprüft und erst nach Freigabe für alle sichtbar.', 'vereins-plugin' ); ?></p>
            <?php endif; ?>

            <form id="vp-event-form" class="vp-form" novalidate>

                <div class="vp-form__group">
                    <label for="vp-type"><?php esc_html_e( 'Termintyp', 'vereins-plugin' ); ?></label>
                    <select id="vp-type" name="event_type_id" required>
                        <option value=""><?php esc_html_e( '– Bitte wählen –', 'vereins-plugin' ); ?></option>
                        <?php foreach ( $types as $t ) : ?>
                            <option value="<?php echo esc_attr( $t['id'] ); ?>">
                                <?php echo esc_html( $t['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="vp-form__group">
                    <label for="vp-title"><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></label>
                    <input type="text" id="vp-title" name="title" required placeholder="<?php esc_attr_e( 'z. B. Dienstags-Training', 'vereins-plugin' ); ?>">
                </div>

                <div class="vp-form__group">
                    <label for="vp-location"><?php esc_html_e( 'Ort', 'vereins-plugin' ); ?></label>
                    <input type="text" id="vp-location" name="location" placeholder="<?php esc_attr_e( 'z. B. Sporthalle', 'vereins-plugin' ); ?>">
                </div>

                <div class="vp-form__group">
                    <label for="vp-description"><?php esc_html_e( 'Beschreibung', 'vereins-plugin' ); ?></label>
                    <textarea id="vp-description" name="description" rows="3" placeholder="<?php esc_attr_e( 'Optional…', 'vereins-plugin' ); ?>"></textarea>
                </div>

                <div class="vp-form__row">
                    <div class="vp-form__group">
                        <label for="vp-start"><?php esc_html_e( 'Start', 'vereins-plugin' ); ?></label>
                        <input type="datetime-local" id="vp-start" name="start_at" required>
                    </div>
                    <div class="vp-form__group">
                        <label for="vp-end"><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></label>
                        <input type="datetime-local" id="vp-end" name="end_at" required>
                    </div>
                </div>

                <div class="vp-form__group vp-form__check">
                    <label>
                        <input type="checkbox" id="vp-fullday" name="is_full_day">
                        <?php esc_html_e( 'Ganztägig', 'vereins-plugin' ); ?>
                    </label>
                </div>

                <!-- Wiederholung -->
                <div class="vp-form__group vp-form__check">
                    <label>
                        <input type="checkbox" id="vp-recurring" name="is_recurring">
                        <?php esc_html_e( 'Wiederkehrender Termin', 'vereins-plugin' ); ?>
                    </label>
                </div>

                <div id="vp-recurrence-panel" class="vp-recurrence" style="display:none">

                    <div class="vp-form__row">
                        <div class="vp-form__group">
                            <label for="vp-freq"><?php esc_html_e( 'Häufigkeit', 'vereins-plugin' ); ?></label>
                            <select id="vp-freq" name="recurrence[frequency]">
                                <option value="DAILY"><?php esc_html_e( 'Täglich', 'vereins-plugin' ); ?></option>
                                <option value="WEEKLY" selected><?php esc_html_e( 'Wöchentlich', 'vereins-plugin' ); ?></option>
                                <option value="MONTHLY"><?php esc_html_e( 'Monatlich', 'vereins-plugin' ); ?></option>
                                <option value="YEARLY"><?php esc_html_e( 'Jährlich', 'vereins-plugin' ); ?></option>
                            </select>
                        </div>
                        <div class="vp-form__group">
                            <label for="vp-interval"><?php esc_html_e( 'Alle … Einheiten', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-interval" name="recurrence[interval]" min="1" max="52" value="1">
                        </div>
                    </div>

                    <!-- Wochentage (nur bei WEEKLY) -->
                    <div id="vp-weekdays-panel" class="vp-form__group">
                        <label><?php esc_html_e( 'Wochentage', 'vereins-plugin' ); ?></label>
                        <div class="vp-weekdays">
                            <?php
                            $days = [ 'MO' => 'Mo', 'TU' => 'Di', 'WE' => 'Mi', 'TH' => 'Do', 'FR' => 'Fr', 'SA' => 'Sa', 'SU' => 'So' ];
                            foreach ( $days as $val => $label ) :
                            ?>
                            <label class="vp-weekdays__btn">
                                <input type="checkbox" name="recurrence[weekdays][]" value="<?php echo esc_attr( $val ); ?>">
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="vp-form__row">
                        <div class="vp-form__group">
                            <label for="vp-until"><?php esc_html_e( 'Ende der Serie (Datum)', 'vereins-plugin' ); ?></label>
                            <input type="date" id="vp-until" name="recurrence[until]">
                        </div>
                        <div class="vp-form__group">
                            <label for="vp-count"><?php esc_html_e( 'oder max. Anzahl', 'vereins-plugin' ); ?></label>
                            <input type="number" id="vp-count" name="recurrence[count]" min="1" placeholder="∞">
                        </div>
                    </div>
                </div>
                <!-- /Wiederholung -->

                <div id="vp-form-msg" class="vp-form__msg" aria-live="polite"></div>

                <button type="submit" class="vp-btn"><?php esc_html_e( 'Termin speichern', 'vereins-plugin' ); ?></button>

            </form>
        </div>

        <style>
        .vp-form-wrap{max-width:600px;font-family:inherit}
        .vp-form__group{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
        .vp-form__group label{font-size:.85rem;font-weight:600;color:#374151}
        .vp-form__group input,.vp-form__group select,.vp-form__group textarea{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;font-family:inherit}
        .vp-form__group input:focus,.vp-form__group select:focus,.vp-form__group textarea:focus{outline:2px solid #3b82f6;border-color:transparent}
        .vp-form__row{display:flex;gap:16px}
        .vp-form__row .vp-form__group{flex:1}
        .vp-form__check label{display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer}
        .vp-recurrence{background:#f3f4f6;border-radius:8px;padding:16px;margin-bottom:14px}
        .vp-weekdays{display:flex;gap:8px;flex-wrap:wrap}
        .vp-weekdays__btn{display:flex;align-items:center;gap:4px;font-size:.85rem;cursor:pointer}
        .vp-btn{background:#3b82f6;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
        .vp-btn:hover{background:#2563eb}
        .vp-btn:disabled{opacity:.6;cursor:not-allowed}
        .vp-form__msg{min-height:24px;font-size:.9rem;padding:4px 0}
        .vp-form__msg--ok{color:#059669}
        .vp-form__msg--err{color:#dc2626}
        </style>

        <script>
        (function(){
            const form    = document.getElementById('vp-event-form');
            const msg     = document.getElementById('vp-form-msg');
            const recurCb = document.getElementById('vp-recurring');
            const panel   = document.getElementById('vp-recurrence-panel');
            const freqSel = document.getElementById('vp-freq');
            const wdPanel = document.getElementById('vp-weekdays-panel');
            const API     = <?php echo json_encode( $api ); ?>;
            const NONCE   = <?php echo json_encode( $nonce ); ?>;

            recurCb.addEventListener('change', () => {
                panel.style.display = recurCb.checked ? 'block' : 'none';
            });

            freqSel.addEventListener('change', () => {
                wdPanel.style.display = freqSel.value === 'WEEKLY' ? 'block' : 'none';
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                msg.textContent = '';
                msg.className   = 'vp-form__msg';

                const fd = new FormData(form);
                const body = {
                    event_type_id: parseInt( fd.get('event_type_id') ),
                    title:         fd.get('title'),
                    location:      fd.get('location') || undefined,
                    description:   fd.get('description') || undefined,
                    start_at:      fd.get('start_at').replace('T', ' ') + ':00',
                    end_at:        fd.get('end_at').replace('T', ' ') + ':00',
                    is_full_day:   fd.get('is_full_day') === 'on',
                };

                if ( recurCb.checked ) {
                    const weekdays = [...form.querySelectorAll('[name="recurrence[weekdays][]"]:checked')]
                                     .map(el => el.value);
                    body.recurrence = {
                        frequency: fd.get('recurrence[frequency]'),
                        interval:  parseInt( fd.get('recurrence[interval]') ) || 1,
                        weekdays:  weekdays.length ? weekdays : undefined,
                        until:     fd.get('recurrence[until]') || undefined,
                        count:     fd.get('recurrence[count]') ? parseInt(fd.get('recurrence[count]')) : undefined,
                    };
                }

                const btn = form.querySelector('.vp-btn');
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Wird gespeichert…', 'vereins-plugin' ) ); ?>';

                try {
                    const res = await fetch( API + '/events', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                        body:    JSON.stringify(body),
                    });
                    const data = await res.json();

                    if ( res.ok ) {
                        msg.textContent = data.approval_status === 'pending'
                            ? '<?php echo esc_js( __( 'Danke! Dein Termin wird erst nach Freigabe sichtbar.', 'vereins-plugin' ) ); ?>'
                            : '<?php echo esc_js( __( 'Termin gespeichert!', 'vereins-plugin' ) ); ?>';
                        msg.className   = 'vp-form__msg vp-form__msg--ok';
                        form.reset();
                        panel.style.display = 'none';
                    } else {
                        msg.textContent = data.message || '<?php echo esc_js( __( 'Fehler beim Speichern.', 'vereins-plugin' ) ); ?>';
                        msg.className   = 'vp-form__msg vp-form__msg--err';
                    }
                } catch(err) {
                    msg.textContent = '<?php echo esc_js( __( 'Netzwerkfehler.', 'vereins-plugin' ) ); ?>';
                    msg.className   = 'vp-form__msg vp-form__msg--err';
                } finally {
                    btn.disabled    = false;
                    btn.textContent = '<?php echo esc_js( __( 'Termin speichern', 'vereins-plugin' ) ); ?>';
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
