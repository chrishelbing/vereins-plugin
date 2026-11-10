<?php
/**
 * Updates aus GitHub-Releases: was aus der Antwort der API wird.
 *
 * Aufruf im Plugin-Ordner:   php tools/updatertest.php
 *
 * Geprüft wird ohne Netz, nur die Auswertung (Updater::parse()). Ob GitHub
 * erreichbar ist und WordPress das Update anbietet, zeigt erst die echte
 * Website unter Plugins.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

stub_load( [ 'Support/Updater.php' ] );

use VereinsPlugin\Support\Updater;

$asset = [
    'name'                 => 'vereins-plugin.zip',
    'url'                  => 'https://api.github.com/repos/chrishelbing/vereins-plugin/releases/assets/1',
    'browser_download_url' => 'https://github.com/chrishelbing/vereins-plugin/releases/download/v1.12.0/vereins-plugin.zip',
];
$release = [
    'tag_name' => 'v1.12.0', 'html_url' => 'https://github.com/x', 'body' => 'Neu: …',
    'published_at' => '2026-11-01T10:00:00Z', 'assets' => [ $asset ],
];

echo "\nAuswertung\n";
$r = Updater::parse( $release );
Pruefung::gleich( 'Version ohne v',              $r['version'] ?? '', '1.12.0' );
Pruefung::gleich( 'öffentlich: direkter Link',   $r['package'] ?? '', $asset['browser_download_url'] );
Pruefung::gleich( 'Hinweise übernommen',         $r['notes'] ?? '', 'Neu: …' );

$ohne = $release;
$ohne['assets'] = [ [ 'name' => 'anderes.zip', 'browser_download_url' => 'x' ] ];
Pruefung::gleich( 'ohne Paket: kein Download',   Updater::parse( $ohne )['package'] ?? 'fehlt', '' );

Pruefung::gleich( 'Vorabversion zählt nicht',    Updater::parse( [ 'prerelease' => true ] + $release ), [] );
Pruefung::gleich( 'Entwurf zählt nicht',         Updater::parse( [ 'draft' => true ] + $release ), [] );
Pruefung::gleich( 'Tag ohne Versionsnummer',     Updater::parse( [ 'tag_name' => 'testlauf' ] + $release ), [] );
Pruefung::gleich( 'leere Antwort',               Updater::parse( [] ), [] );

Pruefung::abschluss( 'Updates' );
