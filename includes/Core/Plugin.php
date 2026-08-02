<?php

namespace VereinsPlugin\Core;

use VereinsPlugin\Admin\AdminMenu;
use VereinsPlugin\Api\RestApi;
use VereinsPlugin\Auth\OtpCleanup;
use VereinsPlugin\Database\Schema;
use VereinsPlugin\Flyer\FlyerPage;
use VereinsPlugin\Frontend\ShortcodeRegistry;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\PeopleCleanup;
use VereinsPlugin\Members\PeopleMigration;
use VereinsPlugin\Photos\PhotoCleanup;
use VereinsPlugin\Photos\PhotoDownload;
use VereinsPlugin\Privacy\PersonalData;
use VereinsPlugin\Support\BackendGuard;
use VereinsPlugin\Support\Capabilities;

/**
 * Hauptklasse – verdrahtet alle Komponenten über WordPress-Hooks.
 *
 * Primärer Nutzungsweg ist die Website (wp-admin ausschließlich für Admins,
 * Shortcodes für Mitglieder). Die REST-API bleibt bestehen und wird
 * nachgelagert für eine App genutzt.
 */
class Plugin {

    public function run(): void {
        $this->maybe_upgrade();

        add_action( 'init',          [ $this, 'load_textdomain' ] );
        add_action( 'init',          [ $this, 'register_shortcodes' ] );
        add_action( 'init',          [ Capabilities::class, 'ensure_admin_role_caps' ] );
        add_action( 'rest_api_init', [ $this, 'register_api'    ] );

        // AdminMenu::register() hängt sich selbst an 'admin_menu' – das muss
        // vor dem Feuern dieses Hooks passieren, daher direkter Aufruf hier.
        ( new AdminMenu() )->register();

        // wp-admin ist Administratoren vorbehalten.
        ( new BackendGuard() )->register();

        // Aushänge und Flyer zu einem Termin.
        ( new FlyerPage() )->register();

        // Fotos: Sammel-Download und täglicher Aufräumjob.
        ( new PhotoDownload() )->register();
        ( new PhotoCleanup()  )->register();

        // Abgelaufene Einmalcode-Anfragen (samt IP-Adresse) täglich entfernen.
        ( new OtpCleanup() )->register();

        // Offene Gast-Kontakte ohne Aktivität nach einer Aufbewahrungsfrist entfernen.
        ( new PeopleCleanup() )->register();

        // Anbindung an die WordPress-Bordmittel für Auskunft/Löschung
        // (Werkzeuge → Persönliche Daten exportieren/löschen).
        ( new PersonalData() )->register();

        // Freischaltungsstatus greift bei jedem klassischen Passwort-Login
        // (wp-login.php UND unser eigenes [vp_login]-Formular via wp_signon()).
        add_filter( 'wp_authenticate_user', [ new MemberService(), 'block_unapproved_login' ] );
    }

    /**
     * Führt dbDelta erneut aus, wenn sich das Schema seit der letzten aktiven
     * Version geändert hat – nötig, damit bereits aktive Installationen neue
     * Spalten (z. B. approval_status) ohne Deaktivieren/Reaktivieren bekommen.
     */
    private function maybe_upgrade(): void {
        if ( get_option( 'vp_db_version' ) !== VP_VERSION ) {
            Schema::create_tables();
            Activator::register_roles();
            // Vor der Personenübernahme: Die prüft die Rolle eines Nutzers,
            // und die muss dann schon die aktuelle Kennung tragen.
            Activator::migrate_roles();
            Capabilities::ensure_admin_role_caps();

            // Einmalig: bereits angelegte, noch nicht freigegebene Nutzer aus
            // wp_users nach vp_people überführen. Läuft nach dem Schema-
            // Abgleich, weil sie die neuen Spalten braucht.
            if ( ! get_option( 'vp_people_migrated' ) ) {
                PeopleMigration::run();
                update_option( 'vp_people_migrated', 1 );
            }

            // Startdaten auch hier anbieten: Wird das Plugin über ein Update
            // eingespielt, statt neu aktiviert, läuft activate() nicht. Der
            // Seeder rührt eine Installation mit vorhandenen Termintypen
            // nicht an, deshalb ist der Aufruf hier gefahrlos.
            Seeder::maybe_seed();
        }
    }

    public function register_shortcodes(): void {
        // Registry ist die einzige Quelle – die Übersicht im Backend liest
        // dieselbe Liste, damit sie nicht veraltet.
        ShortcodeRegistry::register_all();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'vereins-plugin',
            false,
            dirname( plugin_basename( VP_PLUGIN_FILE ) ) . '/languages'
        );
    }

    public function register_api(): void {
        $api = new RestApi();
        $api->register_routes();
    }
}
