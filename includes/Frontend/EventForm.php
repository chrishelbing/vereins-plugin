<?php

namespace VereinsPlugin\Frontend;

/**
 * Die Felder eines Termin-Formulars – einmal, für beide Seiten.
 *
 * [vp_create_event] legt damit neue Termine an, der Bearbeiten-Dialog der
 * Terminliste ändert damit bestehende. Vorher stand das Markup nur im
 * Anlegen-Formular. Ein zweites, handgepflegtes Formular daneben wäre
 * genau die Sorte Doppelung, die beim nächsten neuen Feld auseinanderläuft
 * – und zwar lautlos, weil beide Formulare für sich weiter funktionieren.
 *
 * Werte trägt hier niemand ein: Der Dialog holt den Termin beim Öffnen
 * über GET /events/{id}. Die Felder sind deshalb immer leer und werden
 * über ihr name-Attribut angesprochen, nicht über die ID. IDs gibt es nur,
 * damit die Beschriftungen ein Ziel haben; sie tragen ein Präfix, weil
 * Anlegen-Formular und Dialog auf derselben Seite stehen können.
 */
class EventForm {

    /** Stil-Kennung; beide Seiten fordern dieselbe Datei an. */
    public const STYLE = 'vp-form';

    /**
     * Meldet den Stil an und fordert ihn an. Mehrfach aufrufbar.
     */
    public static function enqueue_style(): void {
        if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
            wp_register_style(
                self::STYLE,
                plugins_url( 'assets/css/form.css', VP_PLUGIN_FILE ),
                [],
                VP_VERSION
            );
        }
        wp_enqueue_style( self::STYLE );
    }

    /**
     * Alle Felder eines Termins, ohne das <form> selbst.
     *
     * @param array  $types  Termintypen für die Auswahlliste.
     * @param string $prefix Wird jeder ID vorangestellt. Der Standard 'vp-'
     *                       erzeugt genau die IDs, die das Anlegen-Formular
     *                       seit jeher benutzt.
     */
    public static function felder( array $types, string $prefix = 'vp-' ): void {
        $id = fn( string $name ): string => $prefix . $name;
        ?>
        <div class="vp-form__group">
            <label for="<?php echo esc_attr( $id( 'type' ) ); ?>"><?php esc_html_e( 'Termintyp', 'vereins-plugin' ); ?></label>
            <select id="<?php echo esc_attr( $id( 'type' ) ); ?>" name="event_type_id" required>
                <option value=""><?php esc_html_e( '– Bitte wählen –', 'vereins-plugin' ); ?></option>
                <?php foreach ( $types as $t ) : ?>
                    <option value="<?php echo esc_attr( $t['id'] ); ?>">
                        <?php echo esc_html( $t['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php
        // Tags: je Termintyp eine eigene Auswahl. Alle stehen im Markup und
        // sind zunächst verborgen; das Skript des jeweiligen Formulars zeigt
        // die des gewählten Typs (data-vp-tagset = Typ-ID) und deaktiviert
        // die übrigen. Der Server prüft die Auswahl ohnehin gegen den Typ.
        foreach ( $types as $t ) :
            $typ_tags = \VereinsPlugin\Events\EventTypeModel::tags_of( $t );
            if ( ! $typ_tags ) {
                continue;
            }
        ?>
        <div class="vp-form__group vp-tagset" data-vp-tagset="<?php echo esc_attr( $t['id'] ); ?>" hidden>
            <label><?php esc_html_e( 'Tags', 'vereins-plugin' ); ?></label>
            <div class="vp-tags">
                <?php foreach ( $typ_tags as $tag ) : ?>
                    <label class="vp-tags__btn">
                        <input type="checkbox" name="tags[]" value="<?php echo esc_attr( $tag ); ?>" disabled>
                        <?php echo esc_html( $tag ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="vp-form__group">
            <label for="<?php echo esc_attr( $id( 'title' ) ); ?>"><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></label>
            <input type="text" id="<?php echo esc_attr( $id( 'title' ) ); ?>" name="title" required
                   placeholder="<?php esc_attr_e( 'z. B. Dienstags-Training', 'vereins-plugin' ); ?>">
        </div>

        <div class="vp-form__group">
            <label for="<?php echo esc_attr( $id( 'location' ) ); ?>"><?php esc_html_e( 'Ort', 'vereins-plugin' ); ?></label>
            <input type="text" id="<?php echo esc_attr( $id( 'location' ) ); ?>" name="location"
                   placeholder="<?php esc_attr_e( 'z. B. Sporthalle', 'vereins-plugin' ); ?>">
        </div>

        <div class="vp-form__group">
            <label for="<?php echo esc_attr( $id( 'description' ) ); ?>"><?php esc_html_e( 'Beschreibung', 'vereins-plugin' ); ?></label>
            <textarea id="<?php echo esc_attr( $id( 'description' ) ); ?>" name="description" rows="3"
                      placeholder="<?php esc_attr_e( 'Optional…', 'vereins-plugin' ); ?>"></textarea>
        </div>

        <div class="vp-form__row">
            <div class="vp-form__group">
                <label for="<?php echo esc_attr( $id( 'start' ) ); ?>"><?php esc_html_e( 'Start', 'vereins-plugin' ); ?></label>
                <input type="datetime-local" id="<?php echo esc_attr( $id( 'start' ) ); ?>" name="start_at" required>
            </div>
            <div class="vp-form__group">
                <label for="<?php echo esc_attr( $id( 'end' ) ); ?>"><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></label>
                <input type="datetime-local" id="<?php echo esc_attr( $id( 'end' ) ); ?>" name="end_at" required>
            </div>
        </div>

        <div class="vp-form__group vp-form__check">
            <label>
                <input type="checkbox" id="<?php echo esc_attr( $id( 'fullday' ) ); ?>" name="is_full_day" data-vp-fullday>
                <?php esc_html_e( 'Ganztägig', 'vereins-plugin' ); ?>
            </label>
        </div>

        <?php // --- Wiederholung ------------------------------------- ?>
        <div class="vp-form__group vp-form__check">
            <label>
                <input type="checkbox" id="<?php echo esc_attr( $id( 'recurring' ) ); ?>" name="is_recurring" data-vp-recurring>
                <?php esc_html_e( 'Wiederkehrender Termin', 'vereins-plugin' ); ?>
            </label>
        </div>

        <div id="<?php echo esc_attr( $id( 'recurrence-panel' ) ); ?>" class="vp-recurrence" data-vp-recurrence style="display:none">

            <div class="vp-form__row">
                <div class="vp-form__group">
                    <label for="<?php echo esc_attr( $id( 'freq' ) ); ?>"><?php esc_html_e( 'Häufigkeit', 'vereins-plugin' ); ?></label>
                    <select id="<?php echo esc_attr( $id( 'freq' ) ); ?>" name="recurrence[frequency]" data-vp-freq>
                        <option value="DAILY"><?php esc_html_e( 'Täglich', 'vereins-plugin' ); ?></option>
                        <option value="WEEKLY" selected><?php esc_html_e( 'Wöchentlich', 'vereins-plugin' ); ?></option>
                        <option value="MONTHLY"><?php esc_html_e( 'Monatlich', 'vereins-plugin' ); ?></option>
                        <option value="YEARLY"><?php esc_html_e( 'Jährlich', 'vereins-plugin' ); ?></option>
                    </select>
                </div>
                <div class="vp-form__group">
                    <label for="<?php echo esc_attr( $id( 'interval' ) ); ?>"><?php esc_html_e( 'Alle … Einheiten', 'vereins-plugin' ); ?></label>
                    <input type="number" id="<?php echo esc_attr( $id( 'interval' ) ); ?>" name="recurrence[interval]" min="1" max="52" value="1">
                </div>
            </div>

            <?php // Wochentage – nur bei WEEKLY sinnvoll, das Skript blendet um. ?>
            <div id="<?php echo esc_attr( $id( 'weekdays-panel' ) ); ?>" class="vp-form__group" data-vp-weekdays>
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
                    <label for="<?php echo esc_attr( $id( 'until' ) ); ?>"><?php esc_html_e( 'Ende der Serie (Datum)', 'vereins-plugin' ); ?></label>
                    <input type="date" id="<?php echo esc_attr( $id( 'until' ) ); ?>" name="recurrence[until]">
                </div>
                <div class="vp-form__group">
                    <label for="<?php echo esc_attr( $id( 'count' ) ); ?>"><?php esc_html_e( 'oder max. Anzahl', 'vereins-plugin' ); ?></label>
                    <input type="number" id="<?php echo esc_attr( $id( 'count' ) ); ?>" name="recurrence[count]" min="1" placeholder="∞">
                </div>
            </div>
        </div>
        <?php // --- /Wiederholung ------------------------------------ ?>
        <?php
    }
}
