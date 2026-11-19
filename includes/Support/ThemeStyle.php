<?php

namespace VereinsPlugin\Support;

/**
 * Gemeinsame Farbdatei assets/css/theme.css („vp-theme“) – Basis.
 *
 * Leitet die Farben des Plugins aus der Palette des Themes ab. Jedes
 * Stylesheet des Plugins gibt HANDLE als Abhängigkeit an; Shortcodes mit
 * Inline-Stil (Anmeldung, Registrierung) fordern sie selbst an.
 */
class ThemeStyle {

    public const HANDLE = 'vp-theme';

    /** Anmelden (mehrfach unschädlich) und Handle zurückgeben – für Abhängigkeiten. */
    public static function handle(): string {
        if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
            wp_register_style( self::HANDLE, plugins_url( 'assets/css/theme.css', VP_PLUGIN_FILE ), [], VP_VERSION );
        }
        return self::HANDLE;
    }

    /** Für Shortcodes ohne eigenes Stylesheet. */
    public static function enqueue(): void {
        wp_enqueue_style( self::handle() );
    }
}
