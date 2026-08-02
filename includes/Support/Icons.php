<?php

namespace VereinsPlugin\Support;

/**
 * Inline-SVG-Icons.
 *
 * Bewusst als PHP-Konstanten statt Icon-Font oder externem Sprite:
 * keine zusätzliche HTTP-Anfrage, keine Abhängigkeit, und die Icons erben
 * über currentColor die Textfarbe des umgebenden Elements.
 *
 * Die Pfade stammen aus dem Feather-Icon-Set (MIT-Lizenz,
 * https://github.com/feathericons/feather) – schlichte Strichzeichnungen,
 * die zum zurückhaltenden Stil des Plugins passen.
 */
class Icons {

    private const PATHS = [
        'user-check' => '<path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        'package'    => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'camera'     => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'upload'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'download'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'image'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'trash'      => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'share'      => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
        'alert'      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        // Drei Suchmuster plus angedeutete Datenmodule – im selben Strichstil
        // wie die übrigen Symbole, damit es im Knopf nicht heraussticht.
        'qr'         => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M20 14v.01"/><path d="M14 20v.01"/><path d="M20 17v4h-3"/>',
    ];

    /**
     * Ein Icon als Inline-SVG.
     *
     * Rückgabe ist fertiges Markup und darf nicht noch einmal escaped werden.
     */
    public static function svg( string $name, string $class = 'vp-icon' ): string {
        if ( ! isset( self::PATHS[ $name ] ) ) {
            return '';
        }

        return sprintf(
            '<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr( $class ),
            self::PATHS[ $name ]
        );
    }

    public static function exists( string $name ): bool {
        return isset( self::PATHS[ $name ] );
    }

    /**
     * Gemeinsames CSS für alle Icons – wird von den Stellen eingebunden,
     * die Icons ausgeben.
     */
    public static function css(): string {
        return '
        .vp-icon { width: 1.05em; height: 1.05em; flex-shrink: 0; vertical-align: -0.15em; }
        ';
    }
}
