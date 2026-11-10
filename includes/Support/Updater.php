<?php

namespace VereinsPlugin\Support;

/**
 * Updates aus GitHub-Releases – damit unter Plugins „Jetzt aktualisieren“
 * erscheint wie bei Plugins aus dem Verzeichnis.
 *
 * Grundlage ist der Header „Update URI: https://github.com/…“ (seit
 * WordPress 5.8). Er sagt WordPress, dass dieses Plugin nicht im
 * wordpress.org-Verzeichnis nachgesehen werden soll, und ruft stattdessen
 * den Filter update_plugins_github.com auf. Hier wird das neueste Release
 * des Repos abgefragt; ist dessen Versionsnummer höher als VP_VERSION, meldet
 * das Plugin ein Update.
 *
 * Erwartet am Release ein angehängtes Paket vereins-plugin.zip mit dem
 * Ordner vereins-plugin/ darin – genau das baut .github/workflows/release.yml
 * beim Pushen eines Tags v1.2.3. Ohne angehängtes Paket gibt es kein Update:
 * Das automatische Quellcode-Zip von GitHub hätte einen Ordnernamen mit
 * Commit-Kennung und würde das Plugin beim Aktualisieren deaktivieren.
 *
 * Privates Repository: In der wp-config.php
 *     define( 'VP_GITHUB_TOKEN', 'github_pat_…' );
 * eintragen (Fine-grained Token, nur dieses Repo, Recht „Contents: Read“).
 * Der Token wird dann bei der Abfrage und beim Herunterladen mitgeschickt –
 * und nur an api.github.com.
 */
class Updater {

    public const REPO = 'chrishelbing/vereins-plugin';

    /** Name des Pakets am Release. */
    public const ASSET = 'vereins-plugin.zip';

    /** Merkt sich die Antwort von GitHub, damit nicht jeder Seitenaufruf fragt. */
    private const CACHE     = 'vp_update_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public function register(): void {
        add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
        add_filter( 'plugins_api',               [ $this, 'details' ], 10, 3 );
        add_filter( 'http_request_args',         [ $this, 'auth_header' ], 10, 2 );

        // „Erneut prüfen“ unter Dashboard → Aktualisierungen soll wirklich
        // neu fragen, nicht die gemerkte Antwort zeigen.
        //
        // Priorität 1, nicht 10: WordPress hängt wp_update_plugins() selbst an
        // denselben Haken (Priorität 10), und zwar früher als jedes Plugin.
        // Bei gleicher Priorität lief die Prüfung also VOR dem Löschen und
        // fand die alte Antwort – etwa ein gemerktes „nichts gefunden“ aus
        // der Zeit, als das Repo noch privat war. „Erneut prüfen“ blieb dann
        // bis zu einer Stunde wirkungslos.
        add_action( 'load-update-core.php', static function (): void {
            if ( ! empty( $_GET['force-check'] ) ) {
                delete_site_transient( self::CACHE );
            }
        }, 1 );
        // Nach einem Update ist die gemerkte Antwort veraltet.
        add_action( 'upgrader_process_complete', static function (): void {
            delete_site_transient( self::CACHE );
        } );
    }

    /**
     * Antwort auf update_plugins_github.com. $update ist, was andere Filter
     * schon gefunden haben (normalerweise false).
     */
    public function check( $update, array $plugin_data, string $plugin_file ) {
        if ( $plugin_file !== plugin_basename( VP_PLUGIN_FILE ) ) {
            return $update;
        }

        $release = $this->latest_release();
        if ( ! $release ) {
            return $update;
        }

        return [
            'id'           => 'github.com/' . self::REPO,
            'slug'         => dirname( $plugin_file ),
            'plugin'       => $plugin_file,
            'version'      => $release['version'],
            'url'          => $release['html_url'],
            'package'      => $release['package'],
            'tested'       => '',
            'requires_php' => '8.0',
        ];
    }

    /**
     * „Details ansehen“ in der Plugin-Liste: Versionshinweise aus dem
     * Release-Text.
     */
    public function details( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== dirname( plugin_basename( VP_PLUGIN_FILE ) ) ) {
            return $result;
        }

        $release = $this->latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'Vereins-Plugin',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'Chris Helbing',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'changelog' => $release['notes'] !== ''
                    ? wpautop( esc_html( $release['notes'] ) )
                    : esc_html__( 'Keine Versionshinweise.', 'vereins-plugin' ),
            ],
        ];
    }

    /**
     * Token an Anfragen an die GitHub-API hängen – nur dorthin. Beim
     * Herunterladen eines Pakets aus einem privaten Repo verlangt GitHub
     * außerdem den Typ application/octet-stream, sonst kommt JSON zurück.
     */
    public function auth_header( array $args, string $url ): array {
        if ( ! self::token() || ! str_starts_with( $url, 'https://api.github.com/repos/' . self::REPO . '/' ) ) {
            return $args;
        }
        $args['headers'] = (array) ( $args['headers'] ?? [] );
        $args['headers']['Authorization'] = 'Bearer ' . self::token();
        if ( str_contains( $url, '/releases/assets/' ) ) {
            $args['headers']['Accept'] = 'application/octet-stream';
        }
        return $args;
    }

    // ------------------------------------------------------------------

    /**
     * Das neueste Release, aufbereitet – oder null, wenn es keins gibt, es
     * nicht neuer ist als die installierte Fassung oder kein Paket daran hängt.
     * Fehlschläge werden ebenfalls (kürzer) gemerkt, damit eine nicht
     * erreichbare API die Plugin-Seite nicht bei jedem Aufruf bremst.
     */
    private function latest_release(): ?array {
        $cached = get_site_transient( self::CACHE );
        if ( is_array( $cached ) ) {
            return $this->newer( $cached );
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [ 'Accept' => 'application/vnd.github+json' ],
            ]
        );

        $release = [];
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $release = self::parse( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
        }

        set_site_transient( self::CACHE, $release, $release ? self::CACHE_TTL : HOUR_IN_SECONDS );
        return $this->newer( $release );
    }

    private function newer( array $release ): ?array {
        if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
            return null;
        }
        return version_compare( $release['version'], VP_VERSION, '>' ) ? $release : null;
    }

    /**
     * Antwort der API → was das Plugin braucht. Öffentlich und statisch,
     * damit tools/updatertest.php es ohne Netz prüfen kann.
     */
    public static function parse( array $json ): array {
        $version = ltrim( (string) ( $json['tag_name'] ?? '' ), 'vV' );
        if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
            return [];
        }

        $package = '';
        foreach ( (array) ( $json['assets'] ?? [] ) as $asset ) {
            if ( ( $asset['name'] ?? '' ) === self::ASSET ) {
                // Privat: über die API mit Token. Öffentlich: direkter Link.
                $package = self::token() ? (string) ( $asset['url'] ?? '' ) : (string) ( $asset['browser_download_url'] ?? '' );
                break;
            }
        }

        return [
            'version'      => $version,
            'package'      => $package,
            'html_url'     => (string) ( $json['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
            'notes'        => (string) ( $json['body'] ?? '' ),
            'published_at' => (string) ( $json['published_at'] ?? '' ),
        ];
    }

    private static function token(): string {
        return defined( 'VP_GITHUB_TOKEN' ) ? (string) VP_GITHUB_TOKEN : '';
    }
}
