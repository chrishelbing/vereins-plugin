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
            'description' => __( 'Offene und beendete Umfragen (Terminumfragen, Abstimmungen, Nominierungen, Ideensammlungen, Wettkampfmeldungen). Teilnahme auch ohne Anmeldung – Gäste nennen einmal ihren Namen. Entwürfe erscheinen nicht.', 'vereins-plugin' ),
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

            // Übersicht (zuklappbare Karten)
            'groupOpen'     => __( 'Laufende Umfragen', 'vereins-plugin' ),
            'groupClosed'   => __( 'Beendet', 'vereins-plugin' ),
            'kindDate'      => __( 'Terminumfrage', 'vereins-plugin' ),
            'kindChoice'    => __( 'Abstimmung', 'vereins-plugin' ),
            'kindText'      => __( 'Ideensammlung', 'vereins-plugin' ),
            'kindEntry'     => __( 'Wettkampfmeldung', 'vereins-plugin' ),
            'stateOpen'     => __( 'Mitmachen', 'vereins-plugin' ),
            'stateClosed'   => __( 'Ansehen', 'vereins-plugin' ),
            'mineDone'      => __( '✓ mitgemacht', 'vereins-plugin' ),
            'mineStarter'   => __( '✓ 1 Starter gemeldet', 'vereins-plugin' ),
            'mineStarters'  => __( '✓ %d Starter gemeldet', 'vereins-plugin' ),
            'resultsOrga'   => __( 'Das Ergebnis sieht nur das Orgateam.', 'vereins-plugin' ),
            'ideasOrga'     => __( 'Die Vorschläge sieht nur das Orgateam.', 'vereins-plugin' ),
            'forgetName'    => __( '%s aus der Liste entfernen', 'vereins-plugin' ),
            'forgetNameAsk' => __( '„%s“ aus deiner Namensliste entfernen? Der Name wird dann auch bei Terminen nicht mehr vorgeschlagen. Bereits abgegebene Meldungen bleiben.', 'vereins-plugin' ),

            // Ideensammlung
            'ideaPerson'   => __( '1 Person hat Vorschläge gemacht', 'vereins-plugin' ),
            'ideaPersons'  => __( '%d Personen haben Vorschläge gemacht', 'vereins-plugin' ),
            'ideaOne'      => __( 'Ein Vorschlag je Person.', 'vereins-plugin' ),
            'ideaMax'      => __( 'Bis zu %d Vorschläge je Person.', 'vereins-plugin' ),
            'ideaNo'       => __( 'Vorschlag %d', 'vereins-plugin' ),
            'ideaAdd'      => __( '+ Weiteren Vorschlag', 'vereins-plugin' ),
            'ideaWrite'    => __( 'Bitte schreib mindestens einen Vorschlag.', 'vereins-plugin' ),
            'ideaSave'     => __( 'Vorschläge speichern', 'vereins-plugin' ),
            'ideaWithdraw' => __( 'Vorschläge zurückziehen', 'vereins-plugin' ),
            'ideaSaved'    => __( 'Deine Vorschläge sind gespeichert. Du kannst sie ändern, solange die Sammlung läuft.', 'vereins-plugin' ),
            'ideaYouAre'   => __( 'Du schreibst als %s.', 'vereins-plugin' ),
            'ideasAll'     => __( 'Alle Vorschläge (%d)', 'vereins-plugin' ),
            'ideasNone'    => __( 'Noch keine Vorschläge.', 'vereins-plugin' ),
            'ideasLater'   => __( 'Die Vorschläge der anderen werden später sichtbar.', 'vereins-plugin' ),

            // Wettkampfmeldung
            'eventOn'          => __( 'Wettkampf am %s', 'vereins-plugin' ),
            'entryUntil'       => __( 'Meldeschluss %s', 'vereins-plugin' ),
            'entryClosed'      => __( 'Meldeschluss vorbei', 'vereins-plugin' ),
            'starters'         => __( '%d Starter gemeldet', 'vereins-plugin' ),
            'starter'          => __( '1 Starter gemeldet', 'vereins-plugin' ),
            'yearsOf'          => __( 'Jahrgänge %s', 'vereins-plugin' ),
            'onlyM'            => __( 'nur männlich', 'vereins-plugin' ),
            'onlyW'            => __( 'nur weiblich', 'vereins-plugin' ),
            'near'             => __( 'gewichtsnahe Gruppen', 'vereins-plugin' ),
            'nearText'         => __( 'Gewichtsnahe Gruppen – eingeteilt wird beim Wiegen, eine Gewichtsklasse brauchst du nicht anzugeben.', 'vereins-plugin' ),
            'entryPrivacy'     => __( 'Die Meldung sieht nur das Orgateam. Jahrgang und Gewichtsklasse werden nach dem Wettkampf gelöscht.', 'vereins-plugin' ),
            'noClasses'        => __( 'Für diesen Wettkampf sind noch keine Klassen eingetragen.', 'vereins-plugin' ),
            'yourEntry'        => __( 'Deine Meldung', 'vereins-plugin' ),
            'starterNo'        => __( 'Starter %d', 'vereins-plugin' ),
            'firstName'        => __( 'Vorname', 'vereins-plugin' ),
            'lastName'         => __( 'Nachname', 'vereins-plugin' ),
            'birthYear'        => __( 'Jahrgang', 'vereins-plugin' ),
            'sex'              => __( 'Geschlecht', 'vereins-plugin' ),
            'female'           => __( 'weiblich', 'vereins-plugin' ),
            'male'             => __( 'männlich', 'vereins-plugin' ),
            'ageClass'         => __( 'Altersklasse', 'vereins-plugin' ),
            'weightClass'      => __( 'Gewichtsklasse', 'vereins-plugin' ),
            'note'             => __( 'Hinweis (freiwillig)', 'vereins-plugin' ),
            'pick'             => __( 'bitte wählen', 'vereins-plugin' ),
            'chooseYearSex'    => __( 'erst Jahrgang und Geschlecht', 'vereins-plugin' ),
            'noClass'          => __( 'keine passende Klasse ausgeschrieben', 'vereins-plugin' ),
            'removeStarter'    => __( 'Starter entfernen', 'vereins-plugin' ),
            'addStarter'       => __( '+ Weiteren Starter melden', 'vereins-plugin' ),
            'savedNames'       => __( 'Schnell eintragen:', 'vereins-plugin' ),
            'entrySave'        => __( 'Meldung abschicken', 'vereins-plugin' ),
            'entryUpdate'      => __( 'Meldung aktualisieren', 'vereins-plugin' ),
            'entryWithdraw'    => __( 'Meldung zurückziehen', 'vereins-plugin' ),
            'entryWithdrawAsk' => __( 'Die ganze Meldung zurückziehen? Alle deine Starter für diesen Wettkampf werden entfernt.', 'vereins-plugin' ),
            'entrySaved'       => __( 'Gemeldet: %d Starter. Du kannst die Meldung bis zum Meldeschluss ändern.', 'vereins-plugin' ),
            'entrySavedOne'    => __( 'Gemeldet: 1 Starter. Du kannst die Meldung bis zum Meldeschluss ändern.', 'vereins-plugin' ),
            'noStarter'        => __( 'Bitte trage mindestens einen Starter ein.', 'vereins-plugin' ),
            'youReport'        => __( 'Du meldest als %s.', 'vereins-plugin' ),
            'whoTextContact'   => __( 'Damit das Orgateam bei Rückfragen jemanden erreicht, brauchen wir deinen Namen und eine E-Mail-Adresse oder Telefonnummer.', 'vereins-plugin' ),
            'emailPlain'       => __( 'E-Mail', 'vereins-plugin' ),
            'phone'            => __( 'Telefon', 'vereins-plugin' ),
            'contactNeeded'    => __( 'Bitte gib eine E-Mail-Adresse oder Telefonnummer an.', 'vereins-plugin' ),
        ];
    }
}
