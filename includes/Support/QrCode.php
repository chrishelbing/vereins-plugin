<?php

namespace VereinsPlugin\Support;

/**
 * QR-Code-Erzeugung ohne externe Abhängigkeit.
 *
 * Bewusst selbst gebaut statt eine Bibliothek einzubinden: Das Plugin kommt
 * ohne Composer aus, und ein QR-Code ist ein abgeschlossenes, gut geprüftes
 * Stück Rechnerei. Die Ausgabe ist SVG – damit bleibt der Code auf einem
 * Flyer bis zum Plakatformat scharf, ohne dass GD ins Spiel kommt.
 *
 * Umfang: Byte-Modus (also beliebiger UTF-8-Text), Fehlerkorrektur M
 * (verträgt rund 15 Prozent Beschädigung – der übliche Kompromiss für
 * Gedrucktes), Versionen 1 bis 20. Das reicht für Adressen bis etwa
 * 660 Zeichen und damit weit über unsere Terminlinks hinaus.
 *
 * Der Algorithmus wurde gegen eine Referenzimplementierung geprüft: gleiche
 * Matrix, und die erzeugten Codes ließen sich mit einem echten Scanner
 * zurücklesen.
 */
class QrCode {

    /**
     * Blockaufteilung je Version für Fehlerkorrekturstufe M.
     * [ Version => [ EC-Codewörter je Block, [ [Blockzahl, Datencodewörter], … ] ] ]
     */
    private const ECC_M = [
        1  => [ 10, [ [ 1, 16 ] ] ],
        2  => [ 16, [ [ 1, 28 ] ] ],
        3  => [ 26, [ [ 1, 44 ] ] ],
        4  => [ 18, [ [ 2, 32 ] ] ],
        5  => [ 24, [ [ 2, 43 ] ] ],
        6  => [ 16, [ [ 4, 27 ] ] ],
        7  => [ 18, [ [ 4, 31 ] ] ],
        8  => [ 22, [ [ 2, 38 ], [ 2, 39 ] ] ],
        9  => [ 22, [ [ 3, 36 ], [ 2, 37 ] ] ],
        10 => [ 26, [ [ 4, 43 ], [ 1, 44 ] ] ],
        11 => [ 30, [ [ 1, 50 ], [ 4, 51 ] ] ],
        12 => [ 22, [ [ 6, 36 ], [ 2, 37 ] ] ],
        13 => [ 22, [ [ 8, 37 ], [ 1, 38 ] ] ],
        14 => [ 24, [ [ 4, 40 ], [ 5, 41 ] ] ],
        15 => [ 24, [ [ 5, 41 ], [ 5, 42 ] ] ],
        16 => [ 28, [ [ 7, 45 ], [ 3, 46 ] ] ],
        17 => [ 28, [ [ 10, 46 ], [ 1, 47 ] ] ],
        18 => [ 26, [ [ 9, 43 ], [ 4, 44 ] ] ],
        19 => [ 26, [ [ 3, 44 ], [ 11, 45 ] ] ],
        20 => [ 26, [ [ 3, 41 ], [ 13, 42 ] ] ],
    ];

    /** Mittelpunkte der Ausrichtungsmuster je Version. */
    private const ALIGN = [
        1 => [], 2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ],
        6 => [ 6, 34 ], 7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ],
        10 => [ 6, 28, 50 ], 11 => [ 6, 30, 54 ], 12 => [ 6, 32, 58 ], 13 => [ 6, 34, 62 ],
        14 => [ 6, 26, 46, 66 ], 15 => [ 6, 26, 48, 70 ], 16 => [ 6, 26, 50, 74 ],
        17 => [ 6, 30, 54, 78 ], 18 => [ 6, 30, 56, 82 ], 19 => [ 6, 30, 58, 86 ],
        20 => [ 6, 34, 62, 90 ],
    ];

    /** @var int[] Potenz- und Logarithmentafel des Galois-Körpers GF(256). */
    private static array $exp = [];
    private static array $log = [];

    // ------------------------------------------------------------------
    // Öffentliche Schnittstelle
    // ------------------------------------------------------------------

    /**
     * Fertiges SVG. Ohne feste Größe – die Darstellung bestimmt das CSS.
     *
     * @param string $text  Inhalt, üblicherweise eine Adresse
     * @param array{quiet?:int, dark?:string, class?:string} $args
     */
    public static function svg( string $text, array $args = [] ): string {
        $quiet = isset( $args['quiet'] ) ? max( 0, (int) $args['quiet'] ) : 4;
        $dark  = $args['dark']  ?? '#111827';
        $class = $args['class'] ?? 'vp-qr';

        try {
            $qr = self::matrix( $text );
        } catch ( \InvalidArgumentException $e ) {
            // Lieber gar kein Bild als ein unlesbares auf dem Flyer.
            return '';
        }

        $size  = $qr['size'];
        $total = $size + 2 * $quiet;

        // Waagerechte Läufe zu einem Rechteck zusammenfassen – das hält den
        // Pfad kurz, ein Modul je Rechteck bläht die Datei unnötig auf.
        $path = '';
        for ( $r = 0; $r < $size; $r++ ) {
            $c = 0;
            while ( $c < $size ) {
                if ( ! $qr['modules'][ $r ][ $c ] ) {
                    $c++;
                    continue;
                }
                $run = 0;
                while ( $c + $run < $size && $qr['modules'][ $r ][ $c + $run ] ) {
                    $run++;
                }
                $path .= 'M' . ( $c + $quiet ) . ' ' . ( $r + $quiet ) . 'h' . $run . 'v1h-' . $run . 'z';
                $c    += $run;
            }
        }

        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" '
            . 'shape-rendering="crispEdges" role="img" aria-label="%s">'
            . '<rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="%s"/></svg>',
            esc_attr( $class ),
            $total, $total,
            esc_attr__( 'QR-Code zum Termin', 'vereins-plugin' ),
            $total, $total,
            $path,
            esc_attr( $dark )
        );
    }

    /**
     * Rohe Modulmatrix.
     *
     * @return array{size:int, version:int, mask:int, modules:array<int,array<int,int>>}
     */
    public static function matrix( string $text ): array {
        $data    = array_values( unpack( 'C*', $text ) ?: [] );
        $version = self::pick_version( count( $data ) );

        $codewords = self::interleave( self::bitstream( $data, $version ), $version );
        [ $modules, $fixed, $size ] = self::build( $version, $codewords );

        $best = null;
        for ( $k = 0; $k < 8; $k++ ) {
            $candidate = self::apply_mask( $modules, $fixed, $size, $k, $version );
            $score     = self::penalty( $candidate, $size );
            if ( $best === null || $score < $best['score'] ) {
                $best = [ 'score' => $score, 'modules' => $candidate, 'mask' => $k ];
            }
        }

        return [
            'size'    => $size,
            'version' => $version,
            'mask'    => $best['mask'],
            'modules' => $best['modules'],
        ];
    }

    // ------------------------------------------------------------------
    // Galois-Körper und Reed-Solomon
    // ------------------------------------------------------------------

    private static function init_tables(): void {
        if ( self::$exp ) {
            return;
        }

        $x = 1;
        for ( $i = 0; $i < 255; $i++ ) {
            self::$exp[ $i ]   = $x;
            self::$log[ $x ]   = $i;
            $x <<= 1;
            if ( $x & 0x100 ) {
                $x ^= 0x11D;   // erzeugendes Polynom des Körpers
            }
        }
        for ( $i = 255; $i < 512; $i++ ) {
            self::$exp[ $i ] = self::$exp[ $i - 255 ];
        }
    }

    private static function gmul( int $a, int $b ): int {
        if ( $a === 0 || $b === 0 ) {
            return 0;
        }
        return self::$exp[ self::$log[ $a ] + self::$log[ $b ] ];
    }

    /**
     * Generatorpolynom vom Grad n.
     *
     * Der Aufbau liefert die Koeffizienten aufsteigend; die Division unten
     * erwartet sie absteigend mit führender Eins – daher am Ende gedreht.
     */
    private static function gen_poly( int $n ): array {
        $p = [ 1 ];
        for ( $i = 0; $i < $n; $i++ ) {
            array_unshift( $p, 0 );
            for ( $j = 0, $len = count( $p ) - 1; $j < $len; $j++ ) {
                $p[ $j ] ^= self::gmul( $p[ $j + 1 ], self::$exp[ $i ] );
            }
        }
        return array_reverse( $p );
    }

    /** Fehlerkorrektur-Codewörter zu einem Datenblock. */
    private static function reed_solomon( array $data, int $n ): array {
        $res = array_merge( $data, array_fill( 0, $n, 0 ) );
        $gp  = self::gen_poly( $n );

        for ( $i = 0, $c = count( $data ); $i < $c; $i++ ) {
            $factor = $res[ $i ];
            if ( ! $factor ) {
                continue;
            }
            for ( $j = 0, $g = count( $gp ); $j < $g; $j++ ) {
                $res[ $i + $j ] ^= self::gmul( $gp[ $j ], $factor );
            }
        }

        return array_slice( $res, count( $data ) );
    }

    // ------------------------------------------------------------------
    // Datenaufbereitung
    // ------------------------------------------------------------------

    private static function capacity( int $version ): int {
        $sum = 0;
        foreach ( self::ECC_M[ $version ][1] as [ $count, $dsize ] ) {
            $sum += $count * $dsize;
        }
        return $sum;
    }

    /**
     * Kleinste Version, in die der Inhalt passt.
     *
     * Passt er in keine, wird abgebrochen statt stillschweigend die größte
     * genommen – ein zu kurz geratener Code sieht gültig aus, führt aber
     * beim Scannen ins Leere. Das fällt erst auf dem gedruckten Flyer auf.
     */
    private static function pick_version( int $length ): int {
        for ( $v = 1; $v <= 20; $v++ ) {
            // Modusanzeige und Längenfeld belegen zusammen höchstens 3 bzw.
            // 4 Byte; großzügig gerechnet, das kostet nur im Grenzfall.
            $overhead = $v < 10 ? 3 : 4;
            if ( self::capacity( $v ) >= $length + $overhead ) {
                return $v;
            }
        }

        throw new \InvalidArgumentException(
            sprintf( 'QR-Code: %d Byte überschreiten die unterstützte Länge.', $length )
        );
    }

    /** Bitstrom aufbauen und auf die Kapazität auffüllen. */
    private static function bitstream( array $data, int $version ): array {
        $bits = [];
        $put  = static function ( int $value, int $count ) use ( &$bits ): void {
            for ( $i = $count - 1; $i >= 0; $i-- ) {
                $bits[] = ( $value >> $i ) & 1;
            }
        };

        $put( 0b0100, 4 );                                   // Byte-Modus
        $put( count( $data ), $version < 10 ? 8 : 16 );       // Längenfeld
        foreach ( $data as $byte ) {
            $put( $byte, 8 );
        }

        $capacity = self::capacity( $version ) * 8;
        $put( 0, min( 4, $capacity - count( $bits ) ) );      // Abschluss

        while ( count( $bits ) % 8 ) {
            $bits[] = 0;
        }

        $pads = [ 0xEC, 0x11 ];
        $i    = 0;
        while ( count( $bits ) < $capacity ) {
            $put( $pads[ $i % 2 ], 8 );
            $i++;
        }

        $codewords = [];
        for ( $i = 0, $c = count( $bits ); $i < $c; $i += 8 ) {
            $byte = 0;
            for ( $j = 0; $j < 8; $j++ ) {
                $byte = ( $byte << 1 ) | $bits[ $i + $j ];
            }
            $codewords[] = $byte;
        }

        return $codewords;
    }

    /** Blöcke bilden, Fehlerkorrektur anhängen, verschränkt ausgeben. */
    private static function interleave( array $codewords, int $version ): array {
        self::init_tables();

        [ $ec_count, $groups ] = self::ECC_M[ $version ];

        $blocks = [];
        $pos    = 0;
        foreach ( $groups as [ $count, $dsize ] ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $blocks[] = array_slice( $codewords, $pos, $dsize );
                $pos     += $dsize;
            }
        }

        $ecs = [];
        foreach ( $blocks as $block ) {
            $ecs[] = self::reed_solomon( $block, $ec_count );
        }

        $longest = 0;
        foreach ( $blocks as $block ) {
            $longest = max( $longest, count( $block ) );
        }

        $out = [];
        for ( $i = 0; $i < $longest; $i++ ) {
            foreach ( $blocks as $block ) {
                if ( $i < count( $block ) ) {
                    $out[] = $block[ $i ];
                }
            }
        }
        for ( $i = 0; $i < $ec_count; $i++ ) {
            foreach ( $ecs as $ec ) {
                $out[] = $ec[ $i ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Format- und Versionsinformation (BCH-gesichert)
    // ------------------------------------------------------------------

    private static function bch_format( int $format ): int {
        $d = $format << 10;
        for ( $i = 4; $i >= 0; $i-- ) {
            if ( $d & ( 1 << ( $i + 10 ) ) ) {
                $d ^= 0x537 << $i;
            }
        }
        return ( ( $format << 10 ) | $d ) ^ 0x5412;
    }

    private static function bch_version( int $version ): int {
        $d = $version << 12;
        for ( $i = 5; $i >= 0; $i-- ) {
            if ( $d & ( 1 << ( $i + 12 ) ) ) {
                $d ^= 0x1F25 << $i;
            }
        }
        return ( $version << 12 ) | $d;
    }

    // ------------------------------------------------------------------
    // Matrix
    // ------------------------------------------------------------------

    /**
     * Funktionsmuster setzen und Daten einweben.
     *
     * Die zweite Rückgabe merkt sich, welche Felder zu den Funktionsmustern
     * gehören – nur die übrigen dürfen später maskiert werden.
     *
     * @return array{0:array, 1:array, 2:int}
     */
    private static function build( int $version, array $codewords ): array {
        $size    = $version * 4 + 17;
        $modules = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
        $fixed   = array_fill( 0, $size, array_fill( 0, $size, false ) );

        $put = static function ( int $r, int $c, int $value ) use ( &$modules, &$fixed, $size ): void {
            if ( $r < 0 || $c < 0 || $r >= $size || $c >= $size ) {
                return;
            }
            $modules[ $r ][ $c ] = $value;
            $fixed[ $r ][ $c ]   = true;
        };

        // Suchmuster samt Trennstreifen
        foreach ( [ [ 0, 0 ], [ 0, $size - 7 ], [ $size - 7, 0 ] ] as [ $fr, $fc ] ) {
            for ( $i = -1; $i <= 7; $i++ ) {
                for ( $j = -1; $j <= 7; $j++ ) {
                    $on = ( $i >= 0 && $i <= 6 && ( $j === 0 || $j === 6 ) )
                       || ( $j >= 0 && $j <= 6 && ( $i === 0 || $i === 6 ) )
                       || ( $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4 );
                    $put( $fr + $i, $fc + $j, $on ? 1 : 0 );
                }
            }
        }

        // Taktlinien
        for ( $i = 8; $i < $size - 8; $i++ ) {
            $bit = 1 - ( $i % 2 );
            $put( 6, $i, $bit );
            $put( $i, 6, $bit );
        }

        // Ausrichtungsmuster. Ausgelassen werden nur die drei Ecken, an denen
        // die Suchmuster stehen. Ein Mittelpunkt auf der Taktlinie ist dagegen
        // völlig regulär und muss gezeichnet werden.
        $coords = self::ALIGN[ $version ];
        if ( $coords ) {
            $first = $coords[0];
            $last  = $coords[ count( $coords ) - 1 ];
            $skip  = [ "$first:$first", "$first:$last", "$last:$first" ];

            foreach ( $coords as $r ) {
                foreach ( $coords as $c ) {
                    if ( in_array( "$r:$c", $skip, true ) ) {
                        continue;
                    }
                    for ( $i = -2; $i <= 2; $i++ ) {
                        for ( $j = -2; $j <= 2; $j++ ) {
                            $put( $r + $i, $c + $j, max( abs( $i ), abs( $j ) ) !== 1 ? 1 : 0 );
                        }
                    }
                }
            }
        }

        $put( $size - 8, 8, 1 );   // stets dunkles Modul

        // Bereiche für Format- und Versionsinformation freihalten
        for ( $i = 0; $i <= 8; $i++ ) {
            if ( ! $fixed[8][ $i ] ) {
                $put( 8, $i, 0 );
            }
            if ( ! $fixed[ $i ][8] ) {
                $put( $i, 8, 0 );
            }
        }
        for ( $i = 0; $i < 8; $i++ ) {
            if ( ! $fixed[8][ $size - 1 - $i ] ) {
                $put( 8, $size - 1 - $i, 0 );
            }
            if ( ! $fixed[ $size - 1 - $i ][8] ) {
                $put( $size - 1 - $i, 8, 0 );
            }
        }
        if ( $version >= 7 ) {
            for ( $i = 0; $i < 18; $i++ ) {
                $r = intdiv( $i, 3 );
                $c = $i % 3;
                $put( $size - 11 + $c, $r, 0 );
                $put( $r, $size - 11 + $c, 0 );
            }
        }

        // Daten im Zickzack von rechts unten nach oben einweben
        $bits = [];
        foreach ( $codewords as $cw ) {
            for ( $i = 7; $i >= 0; $i-- ) {
                $bits[] = ( $cw >> $i ) & 1;
            }
        }

        $idx = 0;
        $up  = true;
        $col = $size - 1;
        while ( $col > 0 ) {
            if ( $col === 6 ) {
                $col--;       // Taktspalte überspringen
            }
            for ( $n = 0; $n < $size; $n++ ) {
                $r = $up ? $size - 1 - $n : $n;
                foreach ( [ $col, $col - 1 ] as $c ) {
                    if ( ! $fixed[ $r ][ $c ] ) {
                        $modules[ $r ][ $c ] = $bits[ $idx ] ?? 0;
                        $idx++;
                    }
                }
            }
            $up   = ! $up;
            $col -= 2;
        }

        return [ $modules, $fixed, $size ];
    }

    private static function mask_bit( int $k, int $r, int $c ): bool {
        return match ( $k ) {
            0 => ( $r + $c ) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ( $r + $c ) % 3 === 0,
            4 => ( intdiv( $r, 2 ) + intdiv( $c, 3 ) ) % 2 === 0,
            5 => ( $r * $c ) % 2 + ( $r * $c ) % 3 === 0,
            6 => ( ( $r * $c ) % 2 + ( $r * $c ) % 3 ) % 2 === 0,
            default => ( ( $r + $c ) % 2 + ( $r * $c ) % 3 ) % 2 === 0,
        };
    }

    /** Maske anwenden und Format- bzw. Versionsinformation eintragen. */
    private static function apply_mask( array $modules, array $fixed, int $size, int $k, int $version ): array {
        for ( $r = 0; $r < $size; $r++ ) {
            for ( $c = 0; $c < $size; $c++ ) {
                if ( ! $fixed[ $r ][ $c ] && self::mask_bit( $k, $r, $c ) ) {
                    $modules[ $r ][ $c ] ^= 1;
                }
            }
        }

        $format = self::bch_format( ( 0b00 << 3 ) | $k );   // 00 = Stufe M
        for ( $i = 0; $i < 15; $i++ ) {
            $bit = ( $format >> $i ) & 1;

            // senkrechte Kopie in Spalte 8
            if ( $i < 6 ) {
                $modules[ $i ][8] = $bit;
            } elseif ( $i < 8 ) {
                $modules[ $i + 1 ][8] = $bit;
            } else {
                $modules[ $size - 15 + $i ][8] = $bit;
            }

            // waagerechte Kopie in Zeile 8
            if ( $i < 8 ) {
                $modules[8][ $size - 1 - $i ] = $bit;
            } elseif ( $i === 8 ) {
                $modules[8][7] = $bit;
            } else {
                $modules[8][ 14 - $i ] = $bit;
            }
        }
        $modules[ $size - 8 ][8] = 1;

        if ( $version >= 7 ) {
            $vbits = self::bch_version( $version );
            for ( $i = 0; $i < 18; $i++ ) {
                $bit = ( $vbits >> $i ) & 1;
                $r   = intdiv( $i, 3 );
                $c   = $i % 3;
                $modules[ $size - 11 + $c ][ $r ] = $bit;
                $modules[ $r ][ $size - 11 + $c ] = $bit;
            }
        }

        return $modules;
    }

    /**
     * Bewertung der vier Strafregeln – je niedriger, desto besser lesbar.
     * Bewertet wird das vollständige Symbol einschließlich Formatbits.
     */
    private static function penalty( array $g, int $size ): int {
        $points = 0;

        $lines = $g;
        for ( $c = 0; $c < $size; $c++ ) {
            $col = [];
            for ( $r = 0; $r < $size; $r++ ) {
                $col[] = $g[ $r ][ $c ];
            }
            $lines[] = $col;
        }

        foreach ( $lines as $line ) {
            // Regel 1: lange gleichfarbige Strecken
            $run = 1;
            for ( $i = 1; $i < $size; $i++ ) {
                if ( $line[ $i ] === $line[ $i - 1 ] ) {
                    $run++;
                    continue;
                }
                if ( $run >= 5 ) {
                    $points += 3 + ( $run - 5 );
                }
                $run = 1;
            }
            if ( $run >= 5 ) {
                $points += 3 + ( $run - 5 );
            }

            // Regel 3: Suchmuster-Verhältnis 1:1:3:1:1 mit vier hellen
            // Modulen auf einer Seite. Überlappende Treffer zählen einzeln.
            $s = implode( '', $line );
            foreach ( [ '10111010000', '00001011101' ] as $pattern ) {
                $offset = 0;
                while ( ( $at = strpos( $s, $pattern, $offset ) ) !== false ) {
                    $points += 40;
                    $offset  = $at + 1;
                }
            }
        }

        // Regel 2: gleichfarbige Zweierblöcke
        for ( $r = 0; $r < $size - 1; $r++ ) {
            for ( $c = 0; $c < $size - 1; $c++ ) {
                $v = $g[ $r ][ $c ];
                if ( $v === $g[ $r ][ $c + 1 ] && $v === $g[ $r + 1 ][ $c ] && $v === $g[ $r + 1 ][ $c + 1 ] ) {
                    $points += 3;
                }
            }
        }

        // Regel 4: Abweichung vom halb dunklen Idealbild, je 5 Prozent
        // 10 Punkte. Der Anteil darf nicht vorab gerundet werden, sonst
        // kippt das Ergebnis knapp unter- oder oberhalb einer Stufe.
        $dark = 0;
        foreach ( $g as $row ) {
            $dark += array_sum( $row );
        }
        $total   = $size * $size;
        $points += 10 * intdiv( abs( $dark * 100 - 50 * $total ), 5 * $total );

        return $points;
    }
}
