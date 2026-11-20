<?php

namespace VereinsPlugin\Polls;

/**
 * Tabellen des Moduls Umfragen.
 *
 * Eigene Tabellen mit eigenem Präfix vp_poll…, damit das Modul vom Modul
 * Termine sichtbar getrennt bleibt. Die Personen-ID folgt der Basis-
 * Verabredung (positiv = Mitglied, negativ = Gast aus vp_people).
 */
class PollSchema {

    /** @return string[] CREATE-Anweisungen für dbDelta */
    public static function tables( string $prefix, string $charset ): array {
        // --- vp_polls --------------------------------------------------------
        // kind:    'date'   – Terminumfrage (passt / vielleicht / passt nicht)
        //          'choice' – Abstimmung oder Nominierung (Auswahl)
        //          'entry'  – Wettkampfmeldung (Starter mit Alters-/Gewichtsklasse)
        //          'text'   – Ideensammlung: Vorschläge als Freitext (z. B. Slogans)
        // max_texts: Ideensammlung – wie viele Vorschläge je Person
        // results: wer das Ergebnis sieht (seit 1.19.0 vier Stufen) –
        //          'always' sofort alle, 'after_close' alle nach dem Ende,
        //          'orga' nur das Orgateam, 'nobody' niemand mit Namen
        //          (geheim; das Orgateam sieht nur die Zahlen).
        //          'after_vote' ist ein Altwert, migrate() macht 'always' daraus.
        // anonymous: folgt aus results = 'nobody' (PollModel::save).
        // Nur Wettkampfmeldung:
        //   event_date  Wettkampftag – sein Jahr bestimmt die Jahrgänge
        //   classes     startende Klassen als JSON (Kopie der Vorlage, je
        //               Turnier angepasst; siehe ClassTemplates)
        //   purge_days  Meldungen so viele Tage nach dem Wettkampf löschen
        //   purged_at   wann das geschehen ist
        //   dokume      Meldung läuft über DokuMe – Judopass wird abgefragt
        //   submitted_at  Meldung an den Veranstalter abgeschickt (von Hand)
        //   confirmed_at  vom Veranstalter bestätigt (von Hand)
        $polls = "CREATE TABLE {$prefix}vp_polls (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind            ENUM('date','choice','entry','text') NOT NULL DEFAULT 'choice',
            title           VARCHAR(255)    NOT NULL,
            description     TEXT            DEFAULT NULL,
            status          ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
            closes_at       DATETIME        DEFAULT NULL,
            multiple        TINYINT(1)      NOT NULL DEFAULT 0,
            allow_maybe     TINYINT(1)      NOT NULL DEFAULT 1,
            anonymous       TINYINT(1)      NOT NULL DEFAULT 0,
            results         ENUM('always','after_vote','after_close','orga','nobody') NOT NULL DEFAULT 'always',
            login_required  TINYINT(1)      NOT NULL DEFAULT 0,
            max_texts       TINYINT UNSIGNED NOT NULL DEFAULT 3,
            event_date      DATE            DEFAULT NULL,
            classes         LONGTEXT        DEFAULT NULL,
            purge_days      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            purged_at       DATETIME        DEFAULT NULL,
            dokume          TINYINT(1)      NOT NULL DEFAULT 0,
            submitted_at    DATETIME        DEFAULT NULL,
            confirmed_at    DATETIME        DEFAULT NULL,
            created_by      BIGINT          NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          status (status)
        ) $charset;";

        // --- vp_poll_options -------------------------------------------------
        // Bei Terminumfragen tragen starts_at/ends_at den Vorschlag; label ist
        // dann ein optionaler Zusatz („mit Grillen“).
        $options = "CREATE TABLE {$prefix}vp_poll_options (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            label       VARCHAR(255)    NOT NULL DEFAULT '',
            starts_at   DATETIME        DEFAULT NULL,
            ends_at     DATETIME        DEFAULT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY          poll_id (poll_id)
        ) $charset;";

        // --- vp_poll_votes ---------------------------------------------------
        // Eine Zeile je Person und Antwortmöglichkeit. Auch bei geheimen
        // Umfragen wird die Person gespeichert – sonst ließe sich nicht
        // verhindern, dass jemand mehrfach abstimmt. Angezeigt wird sie dann
        // niemandem, auch dem Orgateam nicht.
        $votes = "CREATE TABLE {$prefix}vp_poll_votes (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            option_id   BIGINT UNSIGNED NOT NULL,
            person_id   BIGINT          NOT NULL,
            value       ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes',
            voted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   option_person (option_id, person_id),
            KEY          poll_person (poll_id, person_id)
        ) $charset;";

        // --- vp_poll_entries -------------------------------------------------
        // Wettkampfmeldung: eine Zeile je Starter. person_id ist der Melder –
        // so sieht das Orgateam, welche Kinder zu wem gehören. Geburtsjahr,
        // Geschlecht und Gewichtsklasse stehen nur hier, nie am Profil, und
        // werden nach dem Wettkampf gelöscht (EntryCleanup).
        // weight leer = gewichtsnahe Gruppe, Einteilung beim Wiegen.
        // judopass: 1/0 laut Melder (nur bei DokuMe-Meldungen), NULL = nicht gefragt.
        // submitted_at: dieser Starter ist beim Veranstalter gemeldet (Abhakliste).
        // notified_at: der Melder hat zu diesem Starter die Mail „abgeschickt“ bekommen.
        // confirmed_at: der Veranstalter hat diesen Starter bestätigt;
        // confirm_notified_at: der Melder weiß davon (Mail oder von Hand).
        // prev_weight: Ummeldung – Gewicht, mit dem der Starter gemeldet war.
        // withdrawn_at: vom Melder gestrichen, war aber schon gemeldet (Abmeldung);
        // withdraw_done_at: beim Veranstalter abgemeldet. Die Zeile bleibt, bis
        // der Melder informiert ist.
        $entries = "CREATE TABLE {$prefix}vp_poll_entries (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            person_id   BIGINT          NOT NULL,
            first_name  VARCHAR(60)     NOT NULL,
            last_name   VARCHAR(60)     NOT NULL,
            birth_year  SMALLINT UNSIGNED NOT NULL,
            sex         ENUM('m','w')   NOT NULL,
            class_key   VARCHAR(20)     NOT NULL,
            weight      VARCHAR(10)     NOT NULL DEFAULT '',
            note        VARCHAR(255)    NOT NULL DEFAULT '',
            judopass    TINYINT(1)      DEFAULT NULL,
            submitted_at DATETIME       DEFAULT NULL,
            notified_at  DATETIME       DEFAULT NULL,
            confirmed_at DATETIME       DEFAULT NULL,
            confirm_notified_at DATETIME DEFAULT NULL,
            prev_weight  VARCHAR(20)    DEFAULT NULL,
            withdrawn_at DATETIME       DEFAULT NULL,
            withdraw_done_at DATETIME   DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          poll_person (poll_id, person_id)
        ) $charset;";

        // --- vp_poll_texts ---------------------------------------------------
        // Ideensammlung: je Vorschlag eine Zeile. Wie bei Stimmen wird die
        // Person auch bei geheimen Umfragen gespeichert (eigene Vorschläge
        // ändern, Missbrauch zuordnen), aber dann niemandem angezeigt.
        $texts = "CREATE TABLE {$prefix}vp_poll_texts (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            person_id   BIGINT          NOT NULL,
            text        VARCHAR(500)    NOT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          poll_person (poll_id, person_id)
        ) $charset;";

        return [ $polls, $options, $votes, $entries, $texts ];
    }

    /**
     * Nach dbDelta: Altwerte der Sichtbarkeit auf die vier Stufen bringen
     * (1.19.0). „nach eigener Abstimmung“ wird „sofort“, „geheim“ wird
     * „niemand“. Läuft bei jedem Update, ändert aber nur noch Altzeilen.
     */
    public static function migrate( string $prefix ): void {
        global $wpdb;
        $t = $prefix . 'vp_polls';
        $wpdb->query( "UPDATE {$t} SET results = 'nobody' WHERE anonymous = 1 AND results <> 'nobody'" );
        $wpdb->query( "UPDATE {$t} SET results = 'always' WHERE results = 'after_vote'" );
        // 1.23.4: Bestätigung je Starter. Was vor der Bestätigung der Meldung
        // schon gemeldet war, gilt als mitbestätigt.
        $e = $prefix . 'vp_poll_entries';
        $wpdb->query( "UPDATE {$e} e JOIN {$t} p ON p.id = e.poll_id SET e.confirmed_at = p.confirmed_at
            WHERE e.confirmed_at IS NULL AND p.confirmed_at IS NOT NULL AND e.submitted_at IS NOT NULL AND e.submitted_at <= p.confirmed_at" );
    }
}
