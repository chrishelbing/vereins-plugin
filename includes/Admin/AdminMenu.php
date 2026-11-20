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

    /** Der eine Menüpunkt „Verein“: Startseite mit Kacheln zu allen Bereichen. */
    public const HOME = 'vp-start';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        // Erst nach der Rechteprüfung ausblenden (admin_head läuft danach,
        // aber vor dem Zeichnen des Menüs). Früher entfernt, fände WordPress
        // die Seiten nicht mehr und verweigerte den Zugriff.
        add_action( 'admin_head', [ $this, 'hide_submenu' ] );
        add_filter( 'parent_file', [ $this, 'parent_file' ] );
        add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
        add_action( 'all_admin_notices', [ $this, 'breadcrumb' ], 1 );

        $this->event_types_page->register();
        $this->templates_page->register();
        $this->events_page->register();
        $this->attendance_page->register();
        $this->members_page->register();
        $this->settings_page->register();
    }

    /**
     * Ein einziger Menüpunkt „Verein“ mit einer Startseite aus Kacheln.
     * Alle Seiten hängen weiter unter diesem Punkt (gleiche Slugs, Links und
     * Lesezeichen gelten weiter, Rechte prüft WordPress wie gewohnt); im
     * Menü selbst werden die Unterpunkte ausgeblendet (hide_submenu).
     */
    public function add_menu(): void {
        add_menu_page(
            __( 'Verein', 'vereins-plugin' ),
            __( 'Verein', 'vereins-plugin' ),
            'manage_options',
            self::HOME,
            [ $this, 'render_home' ],
            'dashicons-groups',
            26
        );
        add_submenu_page( self::HOME, __( 'Termine', 'vereins-plugin' ),       __( 'Termine', 'vereins-plugin' ),       'manage_options', 'vp-events',      [ $this->events_page, 'render' ] );
        add_submenu_page( self::HOME, __( 'Termintypen', 'vereins-plugin' ),   __( 'Termintypen', 'vereins-plugin' ),   'manage_options', 'vp-event-types', [ $this->event_types_page, 'render' ] );
        add_submenu_page( self::HOME, __( 'Termin-Vorlagen', 'vereins-plugin' ), __( 'Termin-Vorlagen', 'vereins-plugin' ), 'manage_options', 'vp-templates', [ $this->templates_page, 'render' ] );
        add_submenu_page( self::HOME, __( 'Mitglieder', 'vereins-plugin' ),    __( 'Mitglieder', 'vereins-plugin' ),    'manage_options', 'vp-members',     [ $this->members_page, 'render' ] );
        add_submenu_page( self::HOME, __( 'Einstellungen', 'vereins-plugin' ), __( 'Einstellungen', 'vereins-plugin' ), 'manage_options', 'vp-settings',    [ $this->settings_page, 'render' ] );

        // Die Listen-Ansicht bekommt bewusst KEINE eigene Seite: sie läuft
        // über die Terminübersicht mit ?view=lists.
    }

    /** Unterpunkte im Menü ausblenden – die Seiten bleiben erreichbar. */
    public function hide_submenu(): void {
        global $submenu;
        foreach ( array_column( $submenu[ self::HOME ] ?? [], 2 ) as $slug ) {
            if ( $slug !== self::HOME ) {
                remove_submenu_page( self::HOME, $slug );
            }
        }
        remove_submenu_page( self::HOME, self::HOME );
    }

    /** Auf allen Plugin-Seiten bleibt „Verein“ im Menü markiert. */
    public function parent_file( $datei ) {
        return self::is_plugin_page() ? self::HOME : $datei;
    }

    public function submenu_file( $datei ) {
        return self::is_plugin_page() ? self::HOME : $datei;
    }

    private static function is_plugin_page(): bool {
        $seite = sanitize_key( $_GET['page'] ?? '' );
        return $seite !== '' && str_starts_with( $seite, 'vp-' );
    }

    /** Auf den Unterseiten: Weg zurück zur Startseite. */
    public function breadcrumb(): void {
        $seite = sanitize_key( $_GET['page'] ?? '' );
        if ( ! self::is_plugin_page() || $seite === self::HOME ) {
            return;
        }
        $titel = '';
        foreach ( self::tiles() as $kacheln ) {
            foreach ( $kacheln as $k ) {
                if ( $k['slug'] === $seite ) {
                    $titel = $k['title'];
                }
            }
        }
        printf(
            '<p class="vp-crumb" style="margin:12px 0 -4px;font-size:13px;"><a href="%s" style="text-decoration:none;"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px;vertical-align:text-bottom;" aria-hidden="true"></span>%s</a>%s</p>',
            esc_url( admin_url( 'admin.php?page=' . self::HOME ) ),
            esc_html__( 'Verein', 'vereins-plugin' ),
            $titel !== '' ? ' <span class="description">/ ' . esc_html( $titel ) . '</span>' : ''
        );
    }

    /**
     * Kacheln der Startseite, nach Bereichen. Module hängen ihre eigenen über
     * den Filter 'vp_admin_tiles' ein (Bereich => Liste von Kacheln mit slug,
     * title, text, icon).
     *
     * @return array<string, array<int, array{slug:string, title:string, text:string, icon:string}>>
     */
    public static function tiles(): array {
        $kacheln = [
            __( 'Termine', 'vereins-plugin' ) => [
                [ 'slug' => 'vp-events', 'title' => __( 'Termine', 'vereins-plugin' ), 'text' => __( 'Trainings, Turniere und Feiern anlegen – mit Zusagen, Mitbringliste und Helfern.', 'vereins-plugin' ), 'icon' => 'dashicons-calendar-alt' ],
                [ 'slug' => 'vp-event-types', 'title' => __( 'Termintypen', 'vereins-plugin' ), 'text' => __( 'Arten von Terminen mit Farbe, etwa Training, Wettkampf oder Gürtelprüfung.', 'vereins-plugin' ), 'icon' => 'dashicons-tag' ],
                [ 'slug' => 'vp-templates', 'title' => __( 'Termin-Vorlagen', 'vereins-plugin' ), 'text' => __( 'Wiederkehrende Termine mit wenigen Klicks anlegen.', 'vereins-plugin' ), 'icon' => 'dashicons-admin-page' ],
            ],
        ];
        $kacheln = (array) apply_filters( 'vp_admin_tiles', $kacheln );
        $kacheln[ __( 'Verein', 'vereins-plugin' ) ] = [
            [ 'slug' => 'vp-members', 'title' => __( 'Mitglieder', 'vereins-plugin' ), 'text' => __( 'Mitglieder und Gäste, Freischaltungen, Rollen und doppelte Einträge.', 'vereins-plugin' ), 'icon' => 'dashicons-groups' ],
            [ 'slug' => 'vp-settings', 'title' => __( 'Einstellungen', 'vereins-plugin' ), 'text' => __( 'Absender, Flyer, Fotos, Mail-Vorlagen, Datenschutz und Shortcodes.', 'vereins-plugin' ), 'icon' => 'dashicons-admin-generic' ],
        ];
        return $kacheln;
    }

    public function render_home(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ), 403 );
        }
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Verein', 'vereins-plugin' ); ?></h1>
            <style>
            .vp-home h2 { margin: 24px 0 8px; font-size: 15px; color: #50575e; font-weight: 600; }
            .vp-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; max-width: 1100px; }
            .vp-tile { display: flex; flex-direction: column; gap: 8px; padding: 20px; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; text-decoration: none; color: #1d2327; transition: border-color .15s, box-shadow .15s; }
            .vp-tile:hover, .vp-tile:focus { border-color: #2271b1; box-shadow: 0 4px 14px rgba(0,0,0,.07); color: #1d2327; }
            .vp-tile__icon { width: 40px; height: 40px; border-radius: 50%; background: #f0f6fc; color: #2271b1; display: flex; align-items: center; justify-content: center; }
            .vp-tile__icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
            .vp-tile__title { font-size: 15px; font-weight: 600; margin: 4px 0 0; }
            .vp-tile__text { margin: 0; color: #50575e; line-height: 1.5; }
            </style>
            <div class="vp-home">
                <?php foreach ( self::tiles() as $bereich => $kacheln ) : ?>
                    <h2><?php echo esc_html( $bereich ); ?></h2>
                    <div class="vp-tiles">
                        <?php foreach ( $kacheln as $k ) : ?>
                            <a class="vp-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $k['slug'] ) ); ?>">
                                <span class="vp-tile__icon"><span class="dashicons <?php echo esc_attr( $k['icon'] ); ?>" aria-hidden="true"></span></span>
                                <span class="vp-tile__title"><?php echo esc_html( $k['title'] ); ?></span>
                                <span class="vp-tile__text"><?php echo esc_html( $k['text'] ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
