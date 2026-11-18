<?php

namespace VereinsPlugin\Polls;

/**
 * [vp_umfragen] – offene und beendete Umfragen zum Mitmachen.
 *
 * Teilnahme ohne Anmeldung: Wer noch nicht bekannt ist, nennt beim ersten
 * Abstimmen seinen Namen (E-Mail freiwillig). Der Inhalt wird im Browser aus
 * der Schnittstelle /polls aufgebaut; ohne JavaScript erscheint ein Hinweis.
 */
class PollsShortcode {

    public const TAG = 'vp_umfragen';

    public function register(): void {
        add_shortcode( self::TAG, [ $this, 'render' ] );
    }

    public static function describe(): array {
        return [
            'tag'         => self::TAG,
            'title'       => __( 'Umfragen', 'vereins-plugin' ),
            'description' => __( 'Offene und beendete Umfragen (Terminumfragen, Abstimmungen, Nominierungen). Teilnahme auch ohne Anmeldung – Gäste nennen einmal ihren Namen. Entwürfe erscheinen nicht.', 'vereins-plugin' ),
            'atts'        => [
                [ 'id', __( 'alle', 'vereins-plugin' ), __( 'Nur diese eine Umfrage zeigen – die Nummer steht in der Umfragen-Liste im Backend.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_umfragen id="3"]',
        ];
    }

    public function render( $atts = [] ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], (array) $atts, self::TAG );

        $base = plugin_dir_url( VP_PLUGIN_FILE );
        wp_enqueue_style( 'vp-polls', $base . 'assets/css/polls.css', [], VP_VERSION );
        wp_enqueue_script( 'vp-polls', $base . 'assets/js/polls.js', [], VP_VERSION, true );
        wp_localize_script( 'vp-polls', 'vpPolls', [
            'api'   => esc_url_raw( rest_url( 'vereins-plugin/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'here'  => esc_url_raw( get_permalink() ?: home_url( '/' ) ),
            't'     => self::texts(),
        ] );

        return '<div class="vp-polls" data-poll="' . (int) $atts['id'] . '">'
            . '<noscript>' . esc_html__( 'Zum Abstimmen bitte JavaScript aktivieren.', 'vereins-plugin' ) . '</noscript>'
            . '<p class="vp-polls__loading">' . esc_html__( 'Umfragen werden geladen …', 'vereins-plugin' ) . '</p>'
            . '</div>';
    }

    /** Alle Texte der Oberfläche – das Skript enthält keine eigenen. */
    public static function texts(): array {
        return [
            'none'         => __( 'Gerade läuft keine Umfrage.', 'vereins-plugin' ),
            'error'        => __( 'Das hat nicht geklappt. Bitte versuche es noch einmal.', 'vereins-plugin' ),
            'openUntil'    => __( 'offen bis %s', 'vereins-plugin' ),
            'open'         => __( 'offen', 'vereins-plugin' ),
            'closed'       => __( 'beendet', 'vereins-plugin' ),
            'voters'       => __( '%d Teilnahmen', 'vereins-plugin' ),
            'voter'        => __( '1 Teilnahme', 'vereins-plugin' ),
            'yes'          => __( 'passt', 'vereins-plugin' ),
            'maybe'        => __( 'vielleicht', 'vereins-plugin' ),
            'no'           => __( 'passt nicht', 'vereins-plugin' ),
            'save'         => __( 'Antwort speichern', 'vereins-plugin' ),
            'saved'        => __( 'Deine Antwort ist gespeichert. Du kannst sie ändern, solange die Umfrage läuft.', 'vereins-plugin' ),
            'withdraw'     => __( 'Antwort zurückziehen', 'vereins-plugin' ),
            'chooseOne'    => __( 'Bitte wähle eine Antwort.', 'vereins-plugin' ),
            'multiple'     => __( 'Mehrere Antworten möglich.', 'vereins-plugin' ),
            'anonymous'    => __( 'Geheim – Namen werden nicht angezeigt.', 'vereins-plugin' ),
            'loginOnly'    => __( 'Bei dieser Umfrage stimmen nur angemeldete Mitglieder ab.', 'vereins-plugin' ),
            'results'      => __( 'Ergebnis', 'vereins-plugin' ),
            'resultsLater' => __( 'Das Ergebnis wird später sichtbar.', 'vereins-plugin' ),
            'votesFor'     => __( '%d Stimmen', 'vereins-plugin' ),
            'voteFor'      => __( '1 Stimme', 'vereins-plugin' ),
            'youAre'       => __( 'Du stimmst ab als %s.', 'vereins-plugin' ),
            'whoTitle'     => __( 'Wer bist du?', 'vereins-plugin' ),
            'whoText'      => __( 'Damit wir deine Antwort zuordnen können. Die E-Mail ist freiwillig – mit ihr erkennen wir dich auch auf einem anderen Gerät wieder.', 'vereins-plugin' ),
            'name'         => __( 'Name', 'vereins-plugin' ),
            'email'        => __( 'E-Mail (freiwillig)', 'vereins-plugin' ),
            'continue'     => __( 'Weiter', 'vereins-plugin' ),
            'cancel'       => __( 'Abbrechen', 'vereins-plugin' ),
            'nameNeeded'   => __( 'Bitte gib deinen Namen an.', 'vereins-plugin' ),
            'codeTitle'    => __( 'Zugangscode', 'vereins-plugin' ),
            'codeText'     => __( 'Diese Adresse gehört zu einem Mitgliedskonto. Wir haben dir einen Zugangscode geschickt – nach der Eingabe bist du angemeldet. Kein Mitglied? Dann lass das E-Mail-Feld leer.', 'vereins-plugin' ),
            'codeNeeded'   => __( 'Bitte gib den sechsstelligen Code aus der E-Mail ein.', 'vereins-plugin' ),
        ];
    }
}
