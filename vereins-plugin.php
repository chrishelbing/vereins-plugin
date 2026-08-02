<?php
/**
 * Plugin Name: Vereins-Plugin
 * Plugin URI:  https://github.com/your-org/vereins-plugin
 * Description: Digitaler Vereinsalltag – Termine, Kommunikation, Mitgliederverwaltung.
 * Version:     1.7.1
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: vereins-plugin
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

// --- Konstanten -----------------------------------------------------------
define( 'VP_VERSION',     '1.7.1' );
define( 'VP_PLUGIN_FILE', __FILE__ );
define( 'VP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'VP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'VP_PREFIX',      'vp_' );

// --- Autoloader (PSR-4 light) ---------------------------------------------
spl_autoload_register( function ( string $class ): void {
    $namespace = 'VereinsPlugin\\';
    if ( strpos( $class, $namespace ) !== 0 ) {
        return;
    }
    $relative = str_replace( $namespace, '', $class );
    $file     = VP_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// --- Activation / Deactivation --------------------------------------------
register_activation_hook(   __FILE__, [ 'VereinsPlugin\\Core\\Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'VereinsPlugin\\Core\\Activator',   'deactivate' ] );

// --- Boot -----------------------------------------------------------------
function vp_run(): void {
    $plugin = new VereinsPlugin\Core\Plugin();
    $plugin->run();
}
add_action( 'plugins_loaded', 'vp_run' );
