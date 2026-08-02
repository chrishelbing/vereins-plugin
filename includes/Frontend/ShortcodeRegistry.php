<?php

namespace VereinsPlugin\Frontend;

/**
 * Einzige Quelle für die Shortcodes des Plugins.
 *
 * Sowohl die Registrierung bei WordPress als auch die Übersicht im Backend
 * lesen von hier. Wer einen Shortcode ergänzt, trägt seine Klasse einmal in
 * CLASSES ein – Registrierung und Dokumentation bleiben dadurch zwangsläufig
 * synchron.
 */
class ShortcodeRegistry {

    /**
     * Alle Shortcode-Klassen. Jede muss register() und describe() haben.
     */
    private const CLASSES = [
        EventsShortcode::class,
        CreateEventShortcode::class,
        RegisterShortcode::class,
        LoginShortcode::class,
    ];

    /**
     * Shortcodes bei WordPress anmelden.
     */
    public static function register_all(): void {
        foreach ( self::CLASSES as $class ) {
            ( new $class() )->register();
        }
    }

    /**
     * Beschreibungen aller Shortcodes – für die Übersicht im Backend.
     *
     * @return array<int, array{tag:string, title:string, description:string, atts:array, example:string}>
     */
    public static function all(): array {
        $list = [];

        foreach ( self::CLASSES as $class ) {
            if ( method_exists( $class, 'describe' ) ) {
                $list[] = $class::describe();
            }
        }

        usort( $list, fn( $a, $b ) => strcmp( $a['tag'], $b['tag'] ) );
        return $list;
    }

    /**
     * Shortcodes, die zwar bei WordPress registriert sind, hier aber fehlen.
     *
     * Dient als Warnhinweis in der Übersicht: So fällt auf, wenn jemand einen
     * Shortcode ergänzt, ohne ihn zu dokumentieren.
     *
     * @return string[]
     */
    public static function undocumented(): array {
        global $shortcode_tags;

        $known = array_column( self::all(), 'tag' );
        $found = [];

        foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
            if ( strpos( $tag, 'vp_' ) === 0 && ! in_array( $tag, $known, true ) ) {
                $found[] = $tag;
            }
        }

        return $found;
    }
}
