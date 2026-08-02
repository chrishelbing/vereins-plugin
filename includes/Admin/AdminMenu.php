<?php

namespace VereinsPlugin\Admin;

/**
 * Registriert das Top-Level-Menü "Verein" im wp-admin und hängt
 * alle Unterseiten ein. Jede Page-Klasse kümmert sich selbst um ihre
 * admin-post-Handler (Speichern/Löschen).
 */
class AdminMenu {

    private EventsPage      $events_page;
    private EventTypesPage  $event_types_page;
    private TemplatesPage   $templates_page;
    private AttendancePage  $attendance_page;
    private MembersPage     $members_page;
    private SettingsPage    $settings_page;

    public function __construct() {
        // Je Seite genau EINE Instanz – wird sowohl für add_menu_page() als
        // auch add_submenu_page() (gleicher Slug) verwendet. Zwei verschiedene
        // Instanzen würden WordPress dazu bringen, den Seiten-Hook doppelt zu
        // feuern, weil [obj1,'render'] und [obj2,'render'] als unterschiedliche
        // Callbacks gelten – die Seite würde dann zweimal gerendert.
        $this->events_page      = new EventsPage();
        $this->event_types_page = new EventTypesPage();
        $this->templates_page   = new TemplatesPage();
        $this->attendance_page  = new AttendancePage();
        $this->members_page     = new MembersPage();
        $this->settings_page    = new SettingsPage();
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );

        $this->event_types_page->register();
        $this->templates_page->register();
        $this->events_page->register();
        $this->attendance_page->register();
        $this->members_page->register();
        $this->settings_page->register();
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'Verein', 'vereins-plugin' ),
            __( 'Verein', 'vereins-plugin' ),
            'manage_options',
            'vp-events',
            [ $this->events_page, 'render' ],
            'dashicons-groups',
            26
        );

        add_submenu_page( 'vp-events', __( 'Termine', 'vereins-plugin' ),       __( 'Termine', 'vereins-plugin' ),       'manage_options', 'vp-events',      [ $this->events_page, 'render' ] );
        add_submenu_page( 'vp-events', __( 'Termintypen', 'vereins-plugin' ),   __( 'Termintypen', 'vereins-plugin' ),   'manage_options', 'vp-event-types', [ $this->event_types_page, 'render' ] );
        add_submenu_page( 'vp-events', __( 'Vorlagen', 'vereins-plugin' ),      __( 'Vorlagen', 'vereins-plugin' ),      'manage_options', 'vp-templates',   [ $this->templates_page, 'render' ] );
        add_submenu_page( 'vp-events', __( 'Mitglieder', 'vereins-plugin' ),    __( 'Mitglieder', 'vereins-plugin' ),    'manage_options', 'vp-members',     [ $this->members_page, 'render' ] );
        add_submenu_page( 'vp-events', __( 'Einstellungen', 'vereins-plugin' ), __( 'Einstellungen', 'vereins-plugin' ), 'manage_options', 'vp-settings',    [ $this->settings_page, 'render' ] );

        // Die Listen-Ansicht bekommt bewusst KEINE eigene Seite: sie läuft
        // über die Terminübersicht mit ?view=lists. Versteckte Menüseiten
        // sind in WordPress erfahrungsgemäß eine Quelle für Zugriffsfehler.
    }
}
