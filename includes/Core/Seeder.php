<?php

namespace VereinsPlugin\Core;

use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\TemplateModel;

/**
 * Startdaten für eine frische Installation.
 *
 * Ein leeres Plugin ist für einen Verein wenig hilfreich: Bevor der erste
 * Termin entstehen kann, müsste jemand erst Termintypen erfinden und
 * Mitbring- und Helferlisten von Hand tippen. Die Vorschläge hier decken
 * ab, was in fast jedem Verein vorkommt – Fest, Versammlung, Stammtisch,
 * Arbeitseinsatz, Fahrt – und sind ausdrücklich als Ausgangspunkt gedacht:
 * alles ist im Backend änderbar oder löschbar.
 *
 * Zwei Sicherungen verhindern, dass die Startdaten ungefragt wiederkehren:
 *
 *   1. Ein Marker in den Optionen (vp_seed_done). Wer die Vorschläge löscht,
 *      bekommt sie beim nächsten Update nicht zurück.
 *   2. Ein Blick in die Tabelle. Wo schon Termintypen stehen, wird nichts
 *      angelegt – eine gewachsene Installation soll das Update nicht mit
 *      Beispieldaten übergießen.
 *
 * Die angelegten Termintypen werden mit ihrem Schlüssel in vp_seed_types
 * hinterlegt: So bleibt auffindbar, welcher Eintrag aus welchem Vorschlag
 * entstanden ist, auch wenn der Verein ihn umbenennt.
 */
class Seeder {

    /** Marker: Startdaten wurden schon einmal angelegt. */
    public const OPTION_DONE = 'vp_seed_done';

    /** Schlüssel → ID der angelegten Termintypen. */
    public const OPTION_TYPES = 'vp_seed_types';

    /**
     * Vorlagen für Mitbringliste ('bring') und Helferaufgaben ('help').
     *
     * Die Mengen sind bewusst kleine, plausible Zahlen: Sie sind für einen
     * mittleren Verein gedacht und sollen zum Anpassen einladen, nicht
     * vorschreiben.
     *
     * @return array<string, array{kind:string, name:string, items:array<int, array{label:string, quantity_needed:int}>}>
     */
    public static function templates(): array {
        return [
            // --- Mitbringliste --------------------------------------------
            'bring_fest' => [
                'kind'  => 'bring',
                'name'  => __( 'Fest – Kuchen und Salate', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Kuchen', 'vereins-plugin' ),            'quantity_needed' => 4 ],
                    [ 'label' => __( 'Salat', 'vereins-plugin' ),             'quantity_needed' => 3 ],
                    [ 'label' => __( 'Fingerfood', 'vereins-plugin' ),        'quantity_needed' => 2 ],
                    [ 'label' => __( 'Brot und Baguette', 'vereins-plugin' ), 'quantity_needed' => 2 ],
                    [ 'label' => __( 'Kaffeekanne', 'vereins-plugin' ),       'quantity_needed' => 2 ],
                ],
            ],
            'bring_arbeitseinsatz' => [
                'kind'  => 'bring',
                'name'  => __( 'Arbeitseinsatz – Werkzeug und Verpflegung', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Schubkarre', 'vereins-plugin' ),          'quantity_needed' => 2 ],
                    [ 'label' => __( 'Rechen oder Harke', 'vereins-plugin' ),   'quantity_needed' => 4 ],
                    [ 'label' => __( 'Astschere', 'vereins-plugin' ),           'quantity_needed' => 2 ],
                    [ 'label' => __( 'Anhänger für Grünschnitt', 'vereins-plugin' ), 'quantity_needed' => 1 ],
                    [ 'label' => __( 'Getränkekiste', 'vereins-plugin' ),       'quantity_needed' => 2 ],
                    [ 'label' => __( 'Kuchen für die Pause', 'vereins-plugin' ), 'quantity_needed' => 2 ],
                ],
            ],
            'bring_fahrt' => [
                'kind'  => 'bring',
                'name'  => __( 'Fahrt und Zeltlager – Verpflegung unterwegs', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Getränkekiste', 'vereins-plugin' ),         'quantity_needed' => 3 ],
                    [ 'label' => __( 'Belegte Brötchen', 'vereins-plugin' ),      'quantity_needed' => 4 ],
                    [ 'label' => __( 'Obst geschnitten', 'vereins-plugin' ),      'quantity_needed' => 2 ],
                    [ 'label' => __( 'Kekse und Snacks', 'vereins-plugin' ),      'quantity_needed' => 2 ],
                    [ 'label' => __( 'Erste-Hilfe-Tasche', 'vereins-plugin' ),    'quantity_needed' => 1 ],
                ],
            ],
            'bring_advent' => [
                'kind'  => 'bring',
                'name'  => __( 'Adventsfeier – Süßes und Warmes', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Plätzchen (Blech)', 'vereins-plugin' ),  'quantity_needed' => 4 ],
                    [ 'label' => __( 'Punsch oder Glühwein', 'vereins-plugin' ), 'quantity_needed' => 2 ],
                    [ 'label' => __( 'Herzhafter Snack', 'vereins-plugin' ),   'quantity_needed' => 2 ],
                    [ 'label' => __( 'Kerzen und Deko', 'vereins-plugin' ),    'quantity_needed' => 1 ],
                ],
            ],

            // --- Helferaufgaben -------------------------------------------
            'help_fest' => [
                'kind'  => 'help',
                'name'  => __( 'Fest – Auf- und Abbau', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Aufbau am Vortag', 'vereins-plugin' ),   'quantity_needed' => 4 ],
                    [ 'label' => __( 'Getränkeausgabe', 'vereins-plugin' ),    'quantity_needed' => 4 ],
                    [ 'label' => __( 'Grill', 'vereins-plugin' ),              'quantity_needed' => 3 ],
                    [ 'label' => __( 'Kuchentheke', 'vereins-plugin' ),        'quantity_needed' => 2 ],
                    [ 'label' => __( 'Einlass und Kasse', 'vereins-plugin' ),  'quantity_needed' => 2 ],
                    [ 'label' => __( 'Abbau', 'vereins-plugin' ),              'quantity_needed' => 4 ],
                    [ 'label' => __( 'Reinigung danach', 'vereins-plugin' ),   'quantity_needed' => 3 ],
                ],
            ],
            'help_versammlung' => [
                'kind'  => 'help',
                'name'  => __( 'Versammlung – Saal und Protokoll', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Bestuhlung', 'vereins-plugin' ),                  'quantity_needed' => 2 ],
                    [ 'label' => __( 'Anwesenheitsliste am Eingang', 'vereins-plugin' ), 'quantity_needed' => 2 ],
                    [ 'label' => __( 'Protokoll', 'vereins-plugin' ),                   'quantity_needed' => 1 ],
                    [ 'label' => __( 'Technik und Beamer', 'vereins-plugin' ),          'quantity_needed' => 1 ],
                    [ 'label' => __( 'Getränke am Platz', 'vereins-plugin' ),           'quantity_needed' => 2 ],
                ],
            ],
            'help_arbeitseinsatz' => [
                'kind'  => 'help',
                'name'  => __( 'Arbeitseinsatz – Einteilung und Gerät', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Einteilung vor Ort', 'vereins-plugin' ),      'quantity_needed' => 1 ],
                    [ 'label' => __( 'Werkzeugausgabe', 'vereins-plugin' ),         'quantity_needed' => 1 ],
                    [ 'label' => __( 'Grünschnitt abfahren', 'vereins-plugin' ),    'quantity_needed' => 2 ],
                    [ 'label' => __( 'Verpflegung', 'vereins-plugin' ),             'quantity_needed' => 2 ],
                    [ 'label' => __( 'Abschluss und Abschließen', 'vereins-plugin' ), 'quantity_needed' => 1 ],
                ],
            ],
            'help_fahrt' => [
                'kind'  => 'help',
                'name'  => __( 'Fahrt und Zeltlager – Betreuung', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Fahrdienst', 'vereins-plugin' ),          'quantity_needed' => 3 ],
                    [ 'label' => __( 'Betreuung tagsüber', 'vereins-plugin' ),  'quantity_needed' => 4 ],
                    [ 'label' => __( 'Küche', 'vereins-plugin' ),               'quantity_needed' => 3 ],
                    [ 'label' => __( 'Materialtransport', 'vereins-plugin' ),   'quantity_needed' => 2 ],
                    [ 'label' => __( 'Nachtaufsicht', 'vereins-plugin' ),       'quantity_needed' => 2 ],
                ],
            ],
            'help_stand' => [
                'kind'  => 'help',
                'name'  => __( 'Stand auf dem Markt – Schichten', 'vereins-plugin' ),
                'items' => [
                    [ 'label' => __( 'Standaufbau', 'vereins-plugin' ),            'quantity_needed' => 3 ],
                    [ 'label' => __( 'Schicht Vormittag', 'vereins-plugin' ),      'quantity_needed' => 2 ],
                    [ 'label' => __( 'Schicht Nachmittag', 'vereins-plugin' ),     'quantity_needed' => 2 ],
                    [ 'label' => __( 'Kasse', 'vereins-plugin' ),                  'quantity_needed' => 1 ],
                    [ 'label' => __( 'Standabbau', 'vereins-plugin' ),             'quantity_needed' => 3 ],
                ],
            ],
        ];
    }

    /**
     * Termintypen. Die letzten beiden Felder verweisen auf Schlüssel aus
     * templates() und werden nach dem Anlegen in IDs übersetzt.
     *
     * @return array<string, array{name:string, color:string, allows_items:int, needs_helpers:int, allows_photos:int, is_resource:int, bring?:string, help?:string, note:string}>
     */
    public static function event_types(): array {
        return [
            'fest' => [
                'name'          => __( 'Fest', 'vereins-plugin' ),
                'color'         => '#F97316',
                'allows_items'  => 1,
                'needs_helpers' => 1,
                'allows_photos' => 1,
                'is_resource'   => 0,
                'bring'         => 'bring_fest',
                'help'          => 'help_fest',
                'note'          => __( 'Sommerfest, Jubiläum, Sonnwendfeier – alles, wozu mitgebracht, geholfen und fotografiert wird.', 'vereins-plugin' ),
            ],
            'versammlung' => [
                'name'          => __( 'Versammlung', 'vereins-plugin' ),
                'color'         => '#2563EB',
                'allows_items'  => 0,
                'needs_helpers' => 1,
                'allows_photos' => 0,
                'is_resource'   => 0,
                'help'          => 'help_versammlung',
                'note'          => __( 'Jahreshauptversammlung und Mitgliederversammlung. Keine Mitbringliste, keine Fotos.', 'vereins-plugin' ),
            ],
            'stammtisch' => [
                'name'          => __( 'Stammtisch', 'vereins-plugin' ),
                'color'         => '#7C3AED',
                'allows_items'  => 0,
                'needs_helpers' => 0,
                'allows_photos' => 0,
                'is_resource'   => 0,
                'note'          => __( 'Regelmäßiges Treffen ohne Organisation drumherum – nur die Frage, wer kommt.', 'vereins-plugin' ),
            ],
            'arbeitseinsatz' => [
                'name'          => __( 'Arbeitseinsatz', 'vereins-plugin' ),
                'color'         => '#16A34A',
                'allows_items'  => 1,
                'needs_helpers' => 1,
                'allows_photos' => 0,
                'is_resource'   => 0,
                'bring'         => 'bring_arbeitseinsatz',
                'help'          => 'help_arbeitseinsatz',
                'note'          => __( 'Gelände, Vereinsheim, Gerätehaus. Werkzeug wird mitgebracht, Aufgaben werden verteilt.', 'vereins-plugin' ),
            ],
            'fahrt' => [
                'name'          => __( 'Fahrt und Zeltlager', 'vereins-plugin' ),
                'color'         => '#0891B2',
                'allows_items'  => 1,
                'needs_helpers' => 1,
                'allows_photos' => 1,
                'is_resource'   => 0,
                'bring'         => 'bring_fahrt',
                'help'          => 'help_fahrt',
                'note'          => __( 'Mehrtägige Unternehmungen: Ausflug, Zeltlager, Vereinsfahrt.', 'vereins-plugin' ),
            ],
            'training' => [
                'name'          => __( 'Training und Übung', 'vereins-plugin' ),
                'color'         => '#64748B',
                'allows_items'  => 0,
                'needs_helpers' => 0,
                'allows_photos' => 0,
                'is_resource'   => 0,
                'note'          => __( 'Der wöchentliche Termin. Bewusst schlank, sonst wird die Terminliste unübersichtlich.', 'vereins-plugin' ),
            ],
            'sitzung' => [
                'name'          => __( 'Sitzung', 'vereins-plugin' ),
                'color'         => '#475569',
                'allows_items'  => 0,
                'needs_helpers' => 0,
                'allows_photos' => 0,
                'is_resource'   => 0,
                'note'          => __( 'Vorstandssitzung und Ausschuss. Nur Zu- und Absage.', 'vereins-plugin' ),
            ],
            'markt' => [
                'name'          => __( 'Stand und Markt', 'vereins-plugin' ),
                'color'         => '#DB2777',
                'allows_items'  => 1,
                'needs_helpers' => 1,
                'allows_photos' => 1,
                'is_resource'   => 0,
                'bring'         => 'bring_advent',
                'help'          => 'help_stand',
                'note'          => __( 'Weihnachtsmarkt, Dorffest, Tag der offenen Tür – Schichten statt Aufgaben.', 'vereins-plugin' ),
            ],
            'ressource_heim' => [
                'name'          => __( 'Vereinsheim (Belegung)', 'vereins-plugin' ),
                'color'         => '#94A3B8',
                'allows_items'  => 0,
                'needs_helpers' => 0,
                'allows_photos' => 0,
                'is_resource'   => 1,
                'note'          => __( 'Ressource: hält fest, wann der Raum vergeben ist. Ohne Belegungsprüfung.', 'vereins-plugin' ),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Anlegen
    // ------------------------------------------------------------------

    /**
     * Legt die Startdaten an, sofern es noch keine gibt.
     *
     * @return bool true, wenn tatsächlich etwas angelegt wurde
     */
    public static function maybe_seed(): bool {
        if ( get_option( self::OPTION_DONE ) ) {
            return false;
        }

        $types = new EventTypeModel();

        // Eine gewachsene Installation nicht anfassen. Der Marker wird
        // trotzdem gesetzt, sonst liefe diese Prüfung bei jedem Start.
        if ( ! empty( $types->all() ) ) {
            update_option( self::OPTION_DONE, 'skipped' );
            return false;
        }

        self::seed( $types, new TemplateModel(), self::author_id() );
        update_option( self::OPTION_DONE, VP_VERSION );

        return true;
    }

    /**
     * Startdaten auf ausdrückliche Anforderung anlegen (Knopf im Backend).
     *
     * Bewusst ohne den Marker: Der überlebt das Deaktivieren des Plugins,
     * weil Optionen dabei stehen bleiben. Ein erneutes Aktivieren bringt die
     * Vorschläge deshalb nicht zurück – und soll es auch nicht, sonst käme
     * Weggeräumtes bei jeder Wartung wieder. Wer sie doch will, sagt es hier.
     *
     * Doppelte entstehen dabei nicht: Was dem Namen nach schon da ist, wird
     * übersprungen und stattdessen verwendet.
     *
     * @return array{types:int, templates:int}  Anzahl der NEU angelegten Einträge
     */
    public static function seed_on_demand(): array {
        $vorher_typen    = count( ( new EventTypeModel() )->all() );
        $vorher_vorlagen = count( ( new TemplateModel() )->all() );

        self::seed( new EventTypeModel(), new TemplateModel(), self::author_id() );

        update_option( self::OPTION_DONE, VP_VERSION );

        return [
            'types'     => count( ( new EventTypeModel() )->all() ) - $vorher_typen,
            'templates' => count( ( new TemplateModel() )->all() ) - $vorher_vorlagen,
        ];
    }

    /**
     * Der eigentliche Vorgang – ohne Marker und ohne Vorprüfung, damit die
     * Tests und der Knopf im Backend ihn direkt aufrufen können.
     *
     * Was namensgleich schon existiert, bleibt unangetastet: Ein Verein, der
     * „Fest“ umgebaut oder mit einer eigenen Vorlage verknüpft hat, soll das
     * behalten. Nur fehlende Einträge kommen hinzu.
     *
     * @return array{types:array<string,int>, templates:array<string,int>}
     */
    public static function seed( EventTypeModel $types, TemplateModel $templates, int $author_id ): array {
        $vorhandene_vorlagen = [];
        foreach ( $templates->all() as $row ) {
            $vorhandene_vorlagen[ $row['kind'] . '|' . $row['name'] ] = (int) $row['id'];
        }

        $template_ids = [];
        foreach ( self::templates() as $key => $tpl ) {
            $kennung = $tpl['kind'] . '|' . $tpl['name'];

            $template_ids[ $key ] = $vorhandene_vorlagen[ $kennung ] ?? $templates->create( [
                'kind'       => $tpl['kind'],
                'name'       => $tpl['name'],
                'items'      => $tpl['items'],
                'created_by' => $author_id,
            ] );
        }

        $vorhandene_typen = [];
        foreach ( $types->all() as $row ) {
            $vorhandene_typen[ $row['name'] ] = (int) $row['id'];
        }

        $type_ids = [];
        foreach ( self::event_types() as $key => $type ) {
            $type_ids[ $key ] = $vorhandene_typen[ $type['name'] ] ?? $types->create( [
                'name'          => $type['name'],
                'color'         => $type['color'],
                'is_resource'   => $type['is_resource'],
                'allows_items'  => $type['allows_items'],
                'needs_helpers' => $type['needs_helpers'],
                'allows_photos' => $type['allows_photos'],
                'default_item_template_id' => $template_ids[ $type['bring'] ?? '' ] ?? 0,
                'default_help_template_id' => $template_ids[ $type['help']  ?? '' ] ?? 0,
                'created_by'    => $author_id,
            ] );
        }

        update_option( self::OPTION_TYPES, $type_ids );

        return [ 'types' => $type_ids, 'templates' => $template_ids ];
    }

    /**
     * Was von den Startdaten dem Namen nach noch fehlt – für den Hinweis am
     * Knopf, damit vor dem Klick klar ist, was passiert.
     *
     * @return array{types:int, templates:int}
     */
    public static function missing(): array {
        $typen = [];
        foreach ( ( new EventTypeModel() )->all() as $row ) {
            $typen[ $row['name'] ] = true;
        }

        $vorlagen = [];
        foreach ( ( new TemplateModel() )->all() as $row ) {
            $vorlagen[ $row['kind'] . '|' . $row['name'] ] = true;
        }

        $fehlende_typen = 0;
        foreach ( self::event_types() as $type ) {
            if ( ! isset( $typen[ $type['name'] ] ) ) {
                $fehlende_typen++;
            }
        }

        $fehlende_vorlagen = 0;
        foreach ( self::templates() as $tpl ) {
            if ( ! isset( $vorlagen[ $tpl['kind'] . '|' . $tpl['name'] ] ) ) {
                $fehlende_vorlagen++;
            }
        }

        return [ 'types' => $fehlende_typen, 'templates' => $fehlende_vorlagen ];
    }

    /**
     * ID des angelegten Termintyps zu einem Schlüssel – oder 0.
     *
     * Wird nur zum Vorauswählen benutzt. Ein gelöschter oder nie angelegter
     * Typ darf hier fehlen; die Oberfläche fällt dann auf die freie Auswahl
     * zurück.
     */
    public static function type_id( string $key ): int {
        $map = get_option( self::OPTION_TYPES, [] );
        return is_array( $map ) ? (int) ( $map[ $key ] ?? 0 ) : 0;
    }

    /**
     * Wem die Startdaten zugeschrieben werden.
     *
     * Bei der Aktivierung ist das der Administrator, der sie ausgelöst hat.
     * Läuft der Vorgang ohne angemeldeten Nutzer (Aktivierung über WP-CLI),
     * wird der erste Administrator genommen – created_by ist Pflichtfeld,
     * und eine 0 zeigte später auf niemanden.
     */
    private static function author_id(): int {
        $current = get_current_user_id();
        if ( $current ) {
            return $current;
        }

        $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
        return (int) ( $admins[0] ?? 0 );
    }
}
