<?php

namespace VereinsPlugin\Support;

use VereinsPlugin\Admin\Settings;

/**
 * Absenderangaben für Aushänge und Flyer.
 *
 * Gebündelt an einer Stelle, damit Flyer, Druckansicht und spätere Ausgaben
 * dieselben Angaben verwenden und niemand vergisst, auf leere Felder zu
 * prüfen. Alle Werte sind freiwillig – ein Verein, der nichts hinterlegt,
 * bekommt einen Flyer ohne Fußzeile statt einer mit Lücken.
 */
class Branding {

    /**
     * @return array{name:string, contact:string, footnote:string, logo:string, logo_alt:string}
     */
    public static function all(): array {
        $logo_id = (int) Settings::get( 'club_logo_id', 0 );

        return [
            'name'     => (string) Settings::get( 'club_name', '' ),
            'contact'  => (string) Settings::get( 'flyer_contact', '' ),
            'footnote' => (string) Settings::get( 'flyer_footnote', '' ),
            'logo'     => self::logo_url( $logo_id ),
            'logo_alt' => $logo_id ? (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true ) : '',
        ];
    }

    /**
     * Adresse des Logos in druckbarer Größe – oder leer.
     *
     * 'medium' genügt: Auf dem Flyer steht das Logo klein in der Fußzeile,
     * und ein Originalbild aus der Handykamera würde die Druckansicht nur
     * unnötig verlangsamen.
     */
    public static function logo_url( int $attachment_id = 0 ): string {
        $attachment_id = $attachment_id ?: (int) Settings::get( 'club_logo_id', 0 );

        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            return '';
        }

        return (string) ( wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: '' );
    }

    /**
     * Hat der Verein überhaupt etwas hinterlegt? Wenn nicht, entfällt die
     * Fußzeile im Flyer ersatzlos.
     */
    public static function has_any(): bool {
        $b = self::all();
        return $b['name'] !== '' || $b['contact'] !== '' || $b['footnote'] !== '' || $b['logo'] !== '';
    }
}
