<?php

namespace VereinsPlugin\Admin;

/**
 * Formulare als Dialogfenster statt als Karte unter der Tabelle.
 *
 * Bisher stand jedes Formular unterhalb der Liste. Beim Bearbeiten war kaum
 * zu erkennen, welcher Eintrag gerade gemeint war – man klickte auf
 * „Bearbeiten“, die Seite lud neu, und weit unten füllte sich ein Formular,
 * das genauso aussah wie das zum Anlegen. Ein Dialog nimmt diese Frage weg:
 * Er liegt über der Seite, trägt den Namen des Eintrags in der Überschrift
 * und lässt sich nur bewusst verlassen.
 *
 * Umgesetzt mit dem `<dialog>`-Element des Browsers, ohne Bibliothek:
 * Tastaturbedienung, Fokusfalle und Abdunkeln des Hintergrunds bringt es
 * selbst mit. Fällt das Element aus (sehr alter Browser), bleibt der Dialog
 * als gewöhnlicher Kasten sichtbar – das Formular ist dann unschön
 * platziert, aber vollständig bedienbar. Das ist der Grund, warum das
 * Öffnen über `showModal()` läuft und nicht über eine eigene Sichtbarkeits-
 * Umschaltung: Ohne Unterstützung bliebe es sonst unerreichbar.
 *
 * Der Inhalt bleibt serverseitig gerendert. Beim Bearbeiten lädt die Seite
 * weiterhin mit `?edit=…` neu und der Dialog geht von selbst auf. Das kostet
 * einen Seitenaufbau, hält aber Formularaufbau, Vorbelegung und Prüfung an
 * genau einer Stelle – im PHP, das es ohnehin schon gibt.
 */
class AdminModal {

    /**
     * Öffnet die Hülle. Alles bis close() landet im Dialog.
     *
     * @param string $id        Kennung, auf die die Auslöser zeigen (data-vp-modal).
     * @param string $title     Überschrift im Kopf des Dialogs.
     * @param bool   $open      true = beim Laden sofort öffnen (Bearbeiten-Fall).
     * @param string $subtitle  Kleiner Zusatz unter der Überschrift, etwa der Name des Eintrags.
     */
    public static function open( string $id, string $title, bool $open = false, string $subtitle = '' ): void {
        ?>
        <dialog class="vp-modal" id="<?php echo esc_attr( $id ); ?>" <?php echo $open ? 'data-vp-open="1"' : ''; ?>>
            <div class="vp-modal__head">
                <div>
                    <h2><?php echo esc_html( $title ); ?></h2>
                    <?php if ( $subtitle !== '' ) : ?>
                        <p class="vp-modal__subtitle"><?php echo esc_html( $subtitle ); ?></p>
                    <?php endif; ?>
                </div>
                <button type="button" class="vp-modal__close" data-vp-modal-close
                        aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
            </div>
            <div class="vp-modal__body">
        <?php
    }

    public static function close(): void {
        ?>
            </div>
        </dialog>
        <?php
    }

    /**
     * Knopf, der einen Dialog öffnet.
     *
     * @param string $target  Kennung des Dialogs.
     * @param string $label   Beschriftung.
     * @param string $class   Zusätzliche Klassen, z. B. 'button-primary'.
     */
    public static function trigger( string $target, string $label, string $class = 'button-primary' ): void {
        printf(
            '<button type="button" class="button %s" data-vp-modal="%s">%s</button>',
            esc_attr( $class ),
            esc_attr( $target ),
            esc_html( $label )
        );
    }

    /**
     * CSS und Skript. Wird von AdminStyles::print_inline() mit ausgegeben,
     * damit keine Seite es vergessen kann.
     */
    public static function print_assets(): void {
        ?>
        <style>
        .vp-modal { width: min(720px, calc(100vw - 40px)); max-height: calc(100vh - 80px); padding: 0; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 18px 50px rgba(0,0,0,.22); }
        .vp-modal::backdrop { background: rgba(15,23,42,.45); }
        .vp-modal__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; position: sticky; top: 0; }
        .vp-modal__head h2 { margin: 0; font-size: 1.15rem; }
        .vp-modal__subtitle { margin: 2px 0 0; color: #646970; font-size: 13px; }
        .vp-modal__close { border: 0; background: transparent; font-size: 26px; line-height: 1; cursor: pointer; color: #646970; padding: 0 4px; }
        .vp-modal__close:hover { color: #1d2327; }
        .vp-modal__body { padding: 20px; overflow-y: auto; max-height: calc(100vh - 190px); }
        /* Im Dialog ist die Karte schon der Dialog – kein Rahmen im Rahmen. */
        .vp-modal .vp-form-card { border: 0; padding: 0; margin: 0; max-width: none; background: transparent; }
        .vp-modal .vp-form-card h2 { display: none; }
        .vp-modal .vp-field input[type=text], .vp-modal .vp-field input[type=email], .vp-modal .vp-field input[type=tel],
        .vp-modal .vp-field input[type=number], .vp-modal .vp-field input[type=datetime-local], .vp-modal .vp-field input[type=date],
        .vp-modal .vp-field input[type=time], .vp-modal .vp-field select, .vp-modal .vp-field textarea { max-width: none; }
        .vp-modal .submit { position: sticky; bottom: -20px; margin: 20px -20px -20px; padding: 14px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; }

        /* Kopf über der Tabelle: erst die Knöpfe, darunter Filter und
           Hinweise auf einer Linie.
           Die Filterliste ist WordPress' eigene .subsubsub – sie schwebt
           standardmäßig (float) und stellte sich dadurch neben die Knöpfe,
           mit eigenem Zeilenabstand und sichtbar anderer Grundlinie. Hier
           wird sie zu einem gewöhnlichen Flex-Element. */
        .vp-admin-wrap .vp-head { margin: 16px 0 14px; }
        .vp-admin-wrap .vp-head__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .vp-admin-wrap .vp-head__meta { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px 20px; margin-top: 12px; }
        .vp-admin-wrap .vp-head__meta .subsubsub { float: none; margin: 0; padding: 0; }
        .vp-admin-wrap .vp-head__meta .description { margin: 0; }

        /* Die Zeile, die gerade bearbeitet wird – auch sichtbar, wenn der
           Dialog geschlossen wurde. */
        /* Nur die Tönung, kein Rand: Ein inset-Schatten am <td> zöge an jeder
           Zellgrenze einen dunkelgelben Strich statt einer Markierung links
           an der Zeile. */
        .vp-admin-wrap .vp-table tr.is-editing > td { background: #fff8e5; }
        </style>
        <script>
        (function () {
            function dialogVon( el ) { return el ? el.closest( 'dialog' ) : null; }

            function oeffne( dlg ) {
                if ( ! dlg ) { return; }
                if ( typeof dlg.showModal === 'function' ) {
                    if ( ! dlg.open ) { dlg.showModal(); }
                } else {
                    // Kein <dialog>-Support: wenigstens sichtbar lassen.
                    dlg.setAttribute( 'open', 'open' );
                }
                var erstes = dlg.querySelector( 'input:not([type=hidden]), select, textarea' );
                if ( erstes ) { erstes.focus(); }
            }

            document.addEventListener( 'click', function ( e ) {
                // Ein Klick kann auch außerhalb von Elementen landen; ohne
                // closest() bräche der Rest des Handlers samt aller anderen
                // Knöpfe auf der Seite.
                if ( ! e.target || typeof e.target.closest !== 'function' ) { return; }

                var ausloeser = e.target.closest( '[data-vp-modal]' );
                if ( ausloeser ) {
                    e.preventDefault();
                    oeffne( document.getElementById( ausloeser.getAttribute( 'data-vp-modal' ) ) );
                    return;
                }

                if ( e.target.closest( '[data-vp-modal-close]' ) ) {
                    e.preventDefault();
                    var dlg = dialogVon( e.target );
                    if ( dlg ) { dlg.close(); }
                    return;
                }

                // Klick auf den abgedunkelten Rand schließt ebenfalls. Der
                // Vergleich mit dem Dialog selbst genügt: Klicks auf den
                // Inhalt melden ein Kindelement als Ziel.
                if ( e.target.tagName === 'DIALOG' && e.target.classList.contains( 'vp-modal' ) ) {
                    e.target.close();
                }
            } );

            // Erst öffnen, wenn die Seite fertig gelesen ist.
            //
            // Dieses Skript steht im Seitenkopf – AdminStyles::print_inline()
            // gibt es aus, bevor die Seite ihre Tabellen und Dialoge rendert.
            // Direkt hier zu suchen fände deshalb nichts, und ein Klick auf
            // „Bearbeiten“ landete auf einer Seite mit geschlossenem Dialog.
            function autoOeffnen() {
                document.querySelectorAll( 'dialog.vp-modal[data-vp-open]' ).forEach( oeffne );
            }

            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', autoOeffnen );
            } else {
                autoOeffnen();
            }
        })();
        </script>
        <?php
    }
}
