<?php

namespace VereinsPlugin\Polls;

/**
 * [vp_wettkampfmeldungen] – offene und beendete Wettkampfmeldungen: Eltern
 * melden ihre Kinder mit Alters- und Gewichtsklasse. Gleiche Oberfläche wie
 * [vp_umfragen], nur auf Meldungen beschränkt.
 */
class EntriesShortcode extends PollsShortcode {

    public const TAG = 'vp_wettkampfmeldungen';
    protected const BEREICH = 'meldungen';

    public function register(): void {
        parent::register();   // meldet static::TAG an
    }

    public static function describe(): array {
        return [
            'tag'         => self::TAG,
            'title'       => __( 'Wettkampfmeldungen', 'vereins-plugin' ),
            'description' => __( 'Offene und beendete Wettkampfmeldungen: Starter mit Alters- und Gewichtsklasse melden, auch ohne Anmeldung. Entwürfe erscheinen nicht.', 'vereins-plugin' ),
            'atts'        => [
                [ 'id', __( 'alle', 'vereins-plugin' ), __( 'Nur diese eine Meldung zeigen – die Nummer steht in der Liste der Wettkampfmeldungen im Backend.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_wettkampfmeldungen id="3"]',
        ];
    }
}
