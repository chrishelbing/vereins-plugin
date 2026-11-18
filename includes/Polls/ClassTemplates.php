<?php

namespace VereinsPlugin\Polls;

/**
 * Klassen-Vorlagen für Wettkampfmeldungen: Alters- und Gewichtsklassen.
 *
 * Eine Klasse:
 *   key    Kennung, z. B. „U13“ – eindeutig innerhalb der Vorlage
 *   from   jüngstes Alter, to ältestes Alter (im Wettkampfjahr erreicht)
 *   sex    'mw', 'm' oder 'w'
 *   near   true = gewichtsnahe Gruppen, eingeteilt beim Wiegen (keine Auswahl)
 *   m, w   Gewichtsklassen als Text: '-28' … '+55'
 *
 * Maßgeblich ist der Jahrgang, nicht das Alter am Wettkampftag (DJB-WKO
 * 3.1.2): Alter = Wettkampfjahr − Geburtsjahr.
 *
 * Mitgeliefert werden zwei Vorlagen. Gespeichert wird in einer Option; was
 * dort fehlt, kommt aus der Auslieferung – geänderte Vorlagen bleiben
 * erhalten, „Zurücksetzen“ holt die ausgelieferte Fassung zurück.
 */
class ClassTemplates {

    private const OPTION = 'vp_poll_class_templates';

    /** Ausgelieferte Vorlagen. */
    public static function defaults(): array {
        $m_jugend    = [ '-46', '-50', '-55', '-60', '-66', '-73', '-81', '-90', '+90' ];
        $w_jugend    = [ '-40', '-44', '-48', '-52', '-57', '-63', '-70', '-78', '+78' ];
        $m_u15       = [ '-34', '-37', '-40', '-43', '-46', '-50', '-55', '-60', '-66', '+66' ];
        $w_u15       = [ '-33', '-36', '-40', '-44', '-48', '-52', '-57', '-63', '+63' ];
        $m_erwachsen = [ '-60', '-66', '-73', '-81', '-90', '-100', '+100' ];
        $w_erwachsen = [ '-48', '-52', '-57', '-63', '-70', '-78', '+78' ];

        return [
            'bjv' => [
                'name'    => 'BJV Brandenburg',
                'note'    => 'Wettkampfordnung des Brandenburgischen Judo-Verbands, Stand Januar 2024 (Teil C §§ 1–2). U9 ist keine BJV-Klasse, wird aber auf vielen Turnieren ausgeschrieben.',
                'classes' => [
                    [ 'key' => 'U9',  'from' => 7,  'to' => 8,  'sex' => 'mw', 'near' => true,  'm' => [], 'w' => [] ],
                    [ 'key' => 'U11', 'from' => 9,  'to' => 10, 'sex' => 'mw', 'near' => false,
                      'm' => [ '-23', '-25', '-28', '-31', '-34', '-37', '-40', '-43', '-46', '+46' ],
                      'w' => [ '-24', '-26', '-28', '-30', '-33', '-36', '-40', '-44', '-48', '+48' ] ],
                    [ 'key' => 'U13', 'from' => 11, 'to' => 12, 'sex' => 'mw', 'near' => false,
                      'm' => [ '-28', '-31', '-34', '-37', '-40', '-43', '-46', '-50', '-55', '+55' ],
                      'w' => [ '-27', '-30', '-33', '-36', '-40', '-44', '-48', '-52', '-57', '+57' ] ],
                    [ 'key' => 'U15', 'from' => 12, 'to' => 14, 'sex' => 'mw', 'near' => false, 'm' => $m_u15, 'w' => $w_u15 ],
                    [ 'key' => 'U18', 'from' => 15, 'to' => 17, 'sex' => 'mw', 'near' => false, 'm' => $m_jugend, 'w' => $w_jugend ],
                    [ 'key' => 'U21', 'from' => 17, 'to' => 20, 'sex' => 'mw', 'near' => false, 'm' => $m_erwachsen, 'w' => $w_erwachsen ],
                    [ 'key' => 'Erwachsene', 'from' => 17, 'to' => 99, 'sex' => 'mw', 'near' => false, 'm' => $m_erwachsen, 'w' => $w_erwachsen ],
                ],
            ],
            'djb' => [
                'name'    => 'DJB',
                'note'    => 'Wettkampfordnung des Deutschen Judo-Bundes, Stand Dezember 2025 (3.1/3.2). U11 und U13 in gewichtsnahen Gruppen; Ü30 mit den Gewichtsklassen der Erwachsenen.',
                'classes' => [
                    [ 'key' => 'U11', 'from' => 8,  'to' => 10, 'sex' => 'mw', 'near' => true,  'm' => [], 'w' => [] ],
                    [ 'key' => 'U13', 'from' => 10, 'to' => 12, 'sex' => 'mw', 'near' => true,  'm' => [], 'w' => [] ],
                    [ 'key' => 'U15', 'from' => 12, 'to' => 14, 'sex' => 'mw', 'near' => false, 'm' => $m_u15, 'w' => $w_u15 ],
                    [ 'key' => 'U18', 'from' => 15, 'to' => 17, 'sex' => 'mw', 'near' => false, 'm' => $m_jugend, 'w' => $w_jugend ],
                    [ 'key' => 'U21', 'from' => 17, 'to' => 20, 'sex' => 'mw', 'near' => false, 'm' => $m_erwachsen, 'w' => $w_erwachsen ],
                    [ 'key' => 'Erwachsene', 'from' => 17, 'to' => 99, 'sex' => 'mw', 'near' => false, 'm' => $m_erwachsen, 'w' => $w_erwachsen ],
                    [ 'key' => 'Ü30', 'from' => 30, 'to' => 99, 'sex' => 'mw', 'near' => false, 'm' => $m_erwachsen, 'w' => $w_erwachsen ],
                ],
            ],
        ];
    }

    /** Alle Vorlagen – gespeicherte, ergänzt um fehlende ausgelieferte. */
    public static function all(): array {
        $gespeichert = get_option( self::OPTION, [] );
        $gespeichert = is_array( $gespeichert ) ? $gespeichert : [];
        $alle        = $gespeichert + self::defaults();
        foreach ( (array) get_option( self::OPTION . '_removed', [] ) as $slug ) {
            unset( $alle[ $slug ] );
        }
        return $alle;
    }

    public static function find( string $slug ): ?array {
        return self::all()[ $slug ] ?? null;
    }

    public static function save( string $slug, array $template ): void {
        $gespeichert = get_option( self::OPTION, [] );
        $gespeichert = is_array( $gespeichert ) ? $gespeichert : [];
        $gespeichert[ $slug ] = [
            'name'    => (string) ( $template['name'] ?? $slug ),
            'note'    => (string) ( $template['note'] ?? '' ),
            'classes' => self::clean( (array) ( $template['classes'] ?? [] ) ),
        ];
        update_option( self::OPTION, $gespeichert, false );
        self::set_removed( $slug, false );
    }

    /** Ausgelieferte Fassung zurückholen bzw. eigene Vorlage entfernen. */
    public static function reset( string $slug ): void {
        $gespeichert = get_option( self::OPTION, [] );
        $gespeichert = is_array( $gespeichert ) ? $gespeichert : [];
        unset( $gespeichert[ $slug ] );
        update_option( self::OPTION, $gespeichert, false );
        self::set_removed( $slug, false );
    }

    /** Vorlage entfernen – auch eine ausgelieferte (bleibt per reset() zurückholbar). */
    public static function remove( string $slug ): void {
        self::reset( $slug );
        if ( isset( self::defaults()[ $slug ] ) ) {
            self::set_removed( $slug, true );
        }
    }

    public static function is_shipped( string $slug ): bool {
        return isset( self::defaults()[ $slug ] );
    }

    public static function is_changed( string $slug ): bool {
        $gespeichert = get_option( self::OPTION, [] );
        return is_array( $gespeichert ) && isset( $gespeichert[ $slug ] ) && self::is_shipped( $slug );
    }

    /** Ausgelieferte, aber entfernte Vorlagen – zum Zurückholen. */
    public static function removed(): array {
        return array_values( array_intersect( (array) get_option( self::OPTION . '_removed', [] ), array_keys( self::defaults() ) ) );
    }

    private static function set_removed( string $slug, bool $entfernt ): void {
        $liste = array_values( array_diff( (array) get_option( self::OPTION . '_removed', [] ), [ $slug ] ) );
        if ( $entfernt ) {
            $liste[] = $slug;
        }
        update_option( self::OPTION . '_removed', $liste, false );
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    /**
     * Klassenliste bereinigen: leere Zeilen weg, Alter geordnet, Gewichte
     * vereinheitlicht, Kennungen eindeutig.
     */
    public static function clean( array $classes ): array {
        $out  = [];
        $keys = [];
        foreach ( $classes as $c ) {
            $key = mb_substr( trim( sanitize_text_field( (string) ( $c['key'] ?? '' ) ) ), 0, 20 );
            if ( $key === '' || isset( $keys[ mb_strtolower( $key ) ] ) ) {
                continue;
            }
            $from = max( 0, min( 99, (int) ( $c['from'] ?? 0 ) ) );
            $to   = max( 0, min( 99, (int) ( $c['to'] ?? 99 ) ) );
            if ( $from > $to ) {
                [ $from, $to ] = [ $to, $from ];
            }
            $sex  = in_array( $c['sex'] ?? 'mw', [ 'mw', 'm', 'w' ], true ) ? $c['sex'] : 'mw';
            $near = ! empty( $c['near'] );
            $m    = self::parse_weights( $c['m'] ?? [] );
            $w    = self::parse_weights( $c['w'] ?? [] );
            // Ohne Gewichtsklassen bleibt nur die gewichtsnahe Einteilung.
            if ( ( $sex !== 'w' && ! $m ) && ( $sex !== 'm' && ! $w ) ) {
                $near = true;
            }
            $keys[ mb_strtolower( $key ) ] = true;
            $out[] = [ 'key' => $key, 'from' => $from, 'to' => $to, 'sex' => $sex, 'near' => $near, 'm' => $m, 'w' => $w ];
        }
        return $out;
    }

    /**
     * Gewichtsklassen aus Text oder Liste: „28, 31 -34 +55 Ü60“ wird zu
     * ['-28','-31','-34','+55','+60']. Eine Zahl ohne Zeichen gilt als „bis“.
     *
     * @param string|array $eingabe
     * @return string[]
     */
    public static function parse_weights( $eingabe ): array {
        $text = is_array( $eingabe ) ? implode( ' ', $eingabe ) : (string) $eingabe;
        $text = str_ireplace( [ 'ü', 'Ü', 'ue' ], '+', $text );
        preg_match_all( '/([+-]?)\s*(\d+(?:[.,]\d+)?)/u', $text, $m, PREG_SET_ORDER );
        $out = [];
        foreach ( $m as $t ) {
            $zahl = str_replace( ',', '.', $t[2] );
            $wert = ( $t[1] === '+' ? '+' : '-' ) . $zahl;
            if ( ! in_array( $wert, $out, true ) ) {
                $out[] = $wert;
            }
        }
        return $out;
    }

    /** Gewichtsklasse lesbar: „-28“ → „-28 kg“, „+55“ → „+55 kg“. */
    public static function weight_label( string $w ): string {
        return $w === '' ? '' : str_replace( '.', ',', $w ) . ' kg';
    }

    /** Jahrgänge einer Klasse für ein Wettkampfjahr: [ältester, jüngster]. */
    public static function years( array $class, int $year ): array {
        return [ $year - (int) $class['to'], $year - (int) $class['from'] ];
    }

    /** „2014–2015“, „2014“ oder „2009 und älter“. */
    public static function years_label( array $class, int $year ): string {
        [ $alt, $jung ] = self::years( $class, $year );
        if ( (int) $class['to'] >= 99 ) {
            return sprintf( __( '%d und älter', 'vereins-plugin' ), $jung );
        }
        return $alt === $jung ? (string) $alt : $alt . '–' . $jung;
    }
}
