<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Support\Capabilities;

/**
 * Shortcode [vp_create_event]
 *
 * Rendert ein Formular zum Anlegen von Terminen direkt auf der WordPress-Seite.
 * Die Felder selbst kommen aus EventForm – derselbe Satz, den der
 * Bearbeiten-Dialog der Terminliste zeigt.
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

        EventForm::enqueue_style();

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

                <?php EventForm::felder( $types ); ?>

                <div id="vp-form-msg" class="vp-form__msg" aria-live="polite"></div>

                <button type="submit" class="vp-btn"><?php esc_html_e( 'Termin speichern', 'vereins-plugin' ); ?></button>

            </form>
        </div>

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

            // Ganztägig: Start und Ende nur als Datum, ohne Uhrzeit.
            const ganz = form.querySelector('[data-vp-fullday]');
            const nurTage = () => {
                ['start_at', 'end_at'].forEach((name, i) => {
                    const f = form.querySelector('[name="' + name + '"]');
                    if ( ganz.checked && f.type !== 'date' ) {
                        f.dataset.zeit = f.value.slice(11, 16);
                        const tag = f.value.slice(0, 10);
                        f.type = 'date';
                        f.value = tag;
                    } else if ( ! ganz.checked && f.type === 'date' ) {
                        const tag = f.value;
                        f.type = 'datetime-local';
                        f.value = tag ? tag + 'T' + (f.dataset.zeit || (i ? '17:00' : '09:00')) : '';
                    }
                });
            };
            ganz.addEventListener('change', nurTage);
            // Reines Datum → ganztägig 00:00 bzw. 23:59.
            const zeitpunkt = (name, istEnde) => {
                const f = form.querySelector('[name="' + name + '"]');
                if ( f.type === 'date' ) { return f.value + (istEnde ? ' 23:59:00' : ' 00:00:00'); }
                return f.value.replace('T', ' ') + ':00';
            };

            // Tags des gewählten Termintyps zeigen, die übrigen ausblenden.
            const typeSel = form.querySelector('[name="event_type_id"]');
            const zeigeTags = () => {
                form.querySelectorAll('[data-vp-tagset]').forEach(set => {
                    const an = set.dataset.vpTagset === typeSel.value;
                    set.hidden = !an;
                    set.querySelectorAll('input').forEach(i => { i.disabled = !an; });
                });
            };
            typeSel.addEventListener('change', zeigeTags);
            zeigeTags();

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
                    start_at:      zeitpunkt('start_at', false),
                    end_at:        zeitpunkt('end_at', true),
                    is_full_day:   fd.get('is_full_day') === 'on',
                    tags:          [...form.querySelectorAll('[data-vp-tagset]:not([hidden]) input:checked')].map(el => el.value),
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
                        zeigeTags();
                        nurTage();
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
