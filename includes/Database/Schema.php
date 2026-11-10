<?php

namespace VereinsPlugin\Database;

/**
 * Erstellt und verwaltet alle Plugin-eigenen Datenbanktabellen.
 *
 * Personen-IDs
 * ------------
 * Tabellen, an denen auch Gäste hängen können, führen die Spalte person_id.
 * Ein positiver Wert ist eine wp_users-ID, ein negativer verweist auf
 * vp_people (also -id). Dadurch bleibt genau eine Spalte je Beziehung, und
 * aus einer Person kann bei der Freigabe ein Mitglied werden, ohne dass sich
 * die Struktur ändert. Wo dagegen zwingend ein WordPress-Nutzer steht –
 * vp_members, vp_sessions, vp_event_photos – heißt die Spalte weiterhin
 * wp_user_id.
 *
 * Tabellen:
 *  vp_members           – aufgenommene Mitglieder (1:1 zu wp_users)
 *  vp_people            – Personen ohne WordPress-Profil: Gäste aus Termin-
 *                         links und noch nicht freigegebene Registrierungen
 *  vp_otp_tokens        – Einmalcodes für passwortlosen Login
 *  vp_sessions          – App-Sessions (Bearer-Token)
 *  vp_event_types       – frei definierbare Termintypen (Admin)
 *  vp_events            – Termine / Serien
 *  vp_event_recurrence  – Wiederholungsregeln pro Serie
 *  vp_event_exceptions  – Ausnahmen (geänderte/gelöschte Instanzen)
 *  vp_event_attendance  – Zu-/Absagen der Mitglieder je Termininstanz (inkl. Helfer-Flag)
 *  vp_event_items       – Mitbringliste UND Helferaufgaben (per kind getrennt)
 *  vp_event_item_claims – wer welchen Posten übernimmt
 *  vp_templates         – wiederverwendbare Vorlagen je Art
 *  vp_template_items    – Posten einer Vorlage
 *  vp_event_photos      – Fotos je Termininstanz (Datei liegt in der Mediathek)
 *  vp_photo_notices     – bereits verschickte "Upload-Frist abgelaufen"-Infos
 *  vp_member_companions – persönliche Vorschlagsliste für Begleitpersonen
 *  vp_event_companions  – wer bei einer Zusage mitkommt
 */
class Schema {

    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // --- vp_members ---------------------------------------------------
        $members = "CREATE TABLE {$wpdb->prefix}vp_members (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id      BIGINT UNSIGNED NOT NULL,
            display_name    VARCHAR(100)    NOT NULL DEFAULT '',
            phone           VARCHAR(30)     DEFAULT NULL,
            status          ENUM('active','inactive','pending') NOT NULL DEFAULT 'pending',
            confirmed_at    DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   wp_user_id (wp_user_id)
        ) $charset;";

        // --- vp_people ----------------------------------------------------
        // Personen ohne WordPress-Profil. Zwei Herkünfte:
        //   'link'         – hat über einen geteilten Terminlink zugesagt
        //   'registration' – hat sich selbst registriert, wartet auf Freigabe
        //
        // Bewusst kein WordPress-Nutzer: Wer nur einmal zu einem Sommerfest
        // zusagt, soll kein Profil in der Nutzerverwaltung hinterlassen. Erst
        // mit der Aufnahme durch den Verein entsteht ein echter Zugang.
        $people = "CREATE TABLE {$wpdb->prefix}vp_people (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name  VARCHAR(100)    NOT NULL DEFAULT '',
            email         VARCHAR(190)    DEFAULT NULL,
            phone         VARCHAR(30)     DEFAULT NULL,
            source        ENUM('link','registration') NOT NULL DEFAULT 'link',
            confirmed_at  DATETIME        DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   email (email),
            KEY          phone (phone),
            KEY          source (source)
        ) $charset;";

        // --- vp_otp_tokens ------------------------------------------------
        $otp_tokens = "CREATE TABLE {$wpdb->prefix}vp_otp_tokens (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier  VARCHAR(255)    NOT NULL,
            channel     ENUM('email','sms','whatsapp') NOT NULL DEFAULT 'email',
            token_hash  VARCHAR(255)    NOT NULL,
            expires_at  DATETIME        NOT NULL,
            used_at     DATETIME        DEFAULT NULL,
            ip_address  VARCHAR(45)     DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY          identifier (identifier),
            KEY          expires_at (expires_at)
        ) $charset;";

        // --- vp_sessions --------------------------------------------------
        $sessions = "CREATE TABLE {$wpdb->prefix}vp_sessions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id      BIGINT UNSIGNED NOT NULL,
            token_hash      VARCHAR(255)    NOT NULL,
            device_name     VARCHAR(100)    DEFAULT NULL,
            last_active_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at      DATETIME        NOT NULL,
            revoked         TINYINT(1)      NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY          token_hash (token_hash),
            KEY          wp_user_id (wp_user_id)
        ) $charset;";

        // --- vp_event_types -----------------------------------------------
        // Frei durch Admins definierbar. is_resource markiert Ressourcen (Bus, Raum etc.)
        // allows_items / needs_helpers steuern, ob Termine dieses Typs eine
        // Mitbringliste bzw. Helferaufgaben haben. Die default_*_template_id
        // wird beim Anlegen vorbelegt, ist dort aber änderbar.
        // tags: JSON-Liste der Tags, die Termine dieses Typs tragen können
        //       (z. B. Altersklassen); Auswahl am Termin in vp_events.tags.
        // allows_rsvp: 0 = reiner Infotermin – keine Zu-/Absage, und damit
        // auch keine Helferaufgaben und keine Mitbringliste.
        $event_types = "CREATE TABLE {$wpdb->prefix}vp_event_types (
            id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                     VARCHAR(100)    NOT NULL,
            color                    VARCHAR(7)      NOT NULL DEFAULT '#3B82F6',
            icon                     VARCHAR(50)     DEFAULT NULL,
            is_resource              TINYINT(1)      NOT NULL DEFAULT 0,
            allows_rsvp              TINYINT(1)      NOT NULL DEFAULT 1,
            allows_items             TINYINT(1)      NOT NULL DEFAULT 0,
            needs_helpers            TINYINT(1)      NOT NULL DEFAULT 0,
            allows_photos            TINYINT(1)      NOT NULL DEFAULT 0,
            default_item_template_id BIGINT UNSIGNED DEFAULT NULL,
            default_help_template_id BIGINT UNSIGNED DEFAULT NULL,
            tags                     TEXT            DEFAULT NULL,
            created_by               BIGINT UNSIGNED NOT NULL,
            created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";

        // --- vp_events ----------------------------------------------------
        // Einmalige Termine UND Serien-Stammsätze.
        // Bei Serien: start_at/end_at = erster Termin der Serie.
        // is_full_day: ganztägig
        // resource_id: optionale Ressource die gebucht wird (self-referencing auf vp_event_types mit is_resource=1)
        // approval_status: 'approved' (live: vom Admin angelegt oder freigegeben) | 'pending' (wartet auf Freigabe) | 'rejected'
        //                  | 'draft' (Entwurf: nur im Backend sichtbar, per Schalter live zu stellen)
        // tags: JSON-Liste, Auswahl aus den Tags des Termintyps (NULL = keine).
        // is_public: 1 = auch Besuchern ohne Anmeldung in [vp_events] gezeigt
        //            (als Übersicht; zusagen geht über den Terminlink).
        // banner_days_before: NULL = kein Banner. Sonst erscheint der Termin im
        //                     Shortcode [vp_banner] ab so vielen Tagen vor
        //                     Beginn bis zu seinem Ende; 0 = sofort.
        $events = "CREATE TABLE {$wpdb->prefix}vp_events (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type_id    BIGINT UNSIGNED NOT NULL,
            title            VARCHAR(255)    NOT NULL,
            description      TEXT            DEFAULT NULL,
            location         VARCHAR(255)    DEFAULT NULL,
            start_at         DATETIME        NOT NULL,
            end_at           DATETIME        NOT NULL,
            is_full_day      TINYINT(1)      NOT NULL DEFAULT 0,
            is_recurring     TINYINT(1)      NOT NULL DEFAULT 0,
            resource_id      BIGINT UNSIGNED DEFAULT NULL,
            approval_status  ENUM('approved','pending','rejected','draft') NOT NULL DEFAULT 'approved',
            reviewed_by      BIGINT UNSIGNED DEFAULT NULL,
            reviewed_at      DATETIME        DEFAULT NULL,
            photo_upload_days SMALLINT UNSIGNED DEFAULT NULL,
            image_attachment_id BIGINT UNSIGNED DEFAULT NULL,
            banner_days_before SMALLINT UNSIGNED DEFAULT NULL,
            tags             TEXT            DEFAULT NULL,
            is_public        TINYINT(1)      NOT NULL DEFAULT 0,
            created_by       BIGINT UNSIGNED NOT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          event_type_id (event_type_id),
            KEY          start_at (start_at),
            KEY          created_by (created_by),
            KEY          approval_status (approval_status)
        ) $charset;";

        // --- vp_event_recurrence ------------------------------------------
        // RRULE-inspiriert, aber als strukturierte Felder (einfacher zu querien).
        // frequency: DAILY | WEEKLY | MONTHLY | YEARLY
        // interval:  alle X Tage/Wochen/Monate
        // weekdays:  JSON-Array z.B. ["MO","WE","FR"] für wöchentliche Termine
        // month_day: Tag im Monat (1-31) für monatliche Termine
        // until:     Ende der Serie (NULL = unbegrenzt)
        // count:     max. Anzahl Instanzen (NULL = unbegrenzt)
        $recurrence = "CREATE TABLE {$wpdb->prefix}vp_event_recurrence (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id    BIGINT UNSIGNED NOT NULL,
            frequency   ENUM('DAILY','WEEKLY','MONTHLY','YEARLY') NOT NULL,
            interval_n  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            weekdays    JSON            DEFAULT NULL,
            month_day   TINYINT UNSIGNED DEFAULT NULL,
            until       DATE            DEFAULT NULL,
            count       INT UNSIGNED    DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   event_id (event_id)
        ) $charset;";

        // --- vp_event_exceptions ------------------------------------------
        // Jede Ausnahme referenziert den Stamm-Event + das Originaldatum der Instanz.
        // exception_type:
        //   'cancelled'  → Instanz fällt aus
        //   'modified'   → Instanz hat geänderte Daten (eigene Zeilen in diesem Datensatz)
        $exceptions = "CREATE TABLE {$wpdb->prefix}vp_event_exceptions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id        BIGINT UNSIGNED NOT NULL,
            original_date   DATE            NOT NULL,
            exception_type  ENUM('cancelled','modified') NOT NULL,
            title           VARCHAR(255)    DEFAULT NULL,
            description     TEXT            DEFAULT NULL,
            location        VARCHAR(255)    DEFAULT NULL,
            start_at        DATETIME        DEFAULT NULL,
            end_at          DATETIME        DEFAULT NULL,
            modified_by     BIGINT UNSIGNED NOT NULL,
            modified_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   event_instance (event_id, original_date),
            KEY          event_id (event_id)
        ) $charset;";

        // --- vp_event_attendance ------------------------------------------
        // Zu-/Absagen der Mitglieder. Bei Serien wird pro Instanz geantwortet,
        // daher instance_date (= Originaldatum der Instanz, wie bei Ausnahmen).
        // Einmalige Termine nutzen das Startdatum des Termins.
        // helping / help_task: „Ich packe mit an“ inkl. optionaler Aufgabe (Aufbau, Grill …)
        $attendance = "CREATE TABLE {$wpdb->prefix}vp_event_attendance (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id      BIGINT UNSIGNED NOT NULL,
            instance_date DATE            NOT NULL,
            person_id     BIGINT          NOT NULL,
            response      ENUM('yes','no') NOT NULL,
            helping       TINYINT(1)      NOT NULL DEFAULT 0,
            help_task     VARCHAR(255)    DEFAULT NULL,
            responded_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   user_instance (event_id, instance_date, person_id),
            KEY          event_instance (event_id, instance_date)
        ) $charset;";

        // --- vp_event_items -----------------------------------------------
        // Mitbringliste. Zwei Sorten Einträge:
        //   is_request = 1 → vom Orgateam vorgegebener Bedarf ("3x Kuchen"),
        //                    den Mitglieder übernehmen können
        //   is_request = 0 → freier Eintrag eines Mitglieds ("Ich bringe Salat mit")
        // kind trennt Mitbringliste ('bring') von Helferaufgaben ('help') –
        // beide funktionieren gleich: Posten mit Bedarfsmenge, die übernommen werden.
        $items = "CREATE TABLE {$wpdb->prefix}vp_event_items (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id         BIGINT UNSIGNED NOT NULL,
            instance_date    DATE            NOT NULL,
            kind             ENUM('bring','help') NOT NULL DEFAULT 'bring',
            label            VARCHAR(255)    NOT NULL,
            quantity_needed  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            is_request       TINYINT(1)      NOT NULL DEFAULT 1,
            created_by       BIGINT          NOT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          event_instance (event_id, instance_date),
            KEY          kind (kind)
        ) $charset;";

        // --- vp_event_item_claims ------------------------------------------
        // Wer übernimmt welchen Posten (mit optionaler Menge und Notiz).
        // companion_id: 0 = das Mitglied selbst, sonst die Begleitperson.
        // So kann eine Familie mehrere Aufgaben übernehmen.
        $item_claims = "CREATE TABLE {$wpdb->prefix}vp_event_item_claims (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id      BIGINT UNSIGNED NOT NULL,
            person_id    BIGINT          NOT NULL,
            companion_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            note         VARCHAR(255)    DEFAULT NULL,
            claimed_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   item_person (item_id, person_id, companion_id),
            KEY          item_id (item_id)
        ) $charset;";

        // --- vp_member_companions -------------------------------------------
        // Persönliche Vorschlagsliste je Mitglied: Familienmitglieder und
        // Begleitungen, die immer wieder mitkommen. Einmal getippt, danach
        // nur noch anklicken.
        $member_companions = "CREATE TABLE {$wpdb->prefix}vp_member_companions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id   BIGINT          NOT NULL,
            name        VARCHAR(100)    NOT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   user_name (person_id, name),
            KEY          person_id (person_id)
        ) $charset;";

        // --- vp_event_companions --------------------------------------------
        // Wer kommt bei einer Zusage mit. Der Name wird mitgespeichert, damit
        // vergangene Zusagen lesbar bleiben, wenn jemand seine Liste aufräumt.
        $event_companions = "CREATE TABLE {$wpdb->prefix}vp_event_companions (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id       BIGINT UNSIGNED NOT NULL,
            instance_date  DATE            NOT NULL,
            person_id      BIGINT          NOT NULL,
            companion_id   BIGINT UNSIGNED NOT NULL,
            name           VARCHAR(100)    NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   instance_person (event_id, instance_date, person_id, companion_id),
            KEY          event_instance (event_id, instance_date)
        ) $charset;";

        // --- vp_templates ---------------------------------------------------
        // Wiederverwendbare Vorlagen, je Art getrennt:
        //   kind = 'bring' → Mitbringliste ("Kuchen 3x, Salat 2x")
        //   kind = 'help'  → Helferaufgaben ("Aufbau 3x, Grill 2x")
        $templates = "CREATE TABLE {$wpdb->prefix}vp_templates (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind        ENUM('bring','help') NOT NULL DEFAULT 'bring',
            name        VARCHAR(150)    NOT NULL,
            created_by  BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          kind (kind)
        ) $charset;";

        // --- vp_template_items ----------------------------------------------
        $template_items = "CREATE TABLE {$wpdb->prefix}vp_template_items (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id      BIGINT UNSIGNED NOT NULL,
            label            VARCHAR(255)    NOT NULL,
            quantity_needed  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY          template_id (template_id)
        ) $charset;";

        // --- vp_event_photos -------------------------------------------------
        // Fotos zu einer Termininstanz. Die Datei selbst liegt als normales
        // WordPress-Attachment in der Mediathek; hier stehen nur die Zuordnung
        // zum Termin, der Uploader und die Größe (für das Kontingent).
        // purge_after: Zeitpunkt, ab dem der Cron das Foto löschen darf.
        // consent_at: Zeitpunkt, zu dem die hochladende Person der Nutzung auf
        // Website und in sozialen Medien zugestimmt hat. Pflichtfeld im
        // Frontend (Checkbox); hier als Nachweis festgehalten.
        $photos = "CREATE TABLE {$wpdb->prefix}vp_event_photos (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id       BIGINT UNSIGNED NOT NULL,
            instance_date  DATE            NOT NULL,
            wp_user_id     BIGINT UNSIGNED NOT NULL,
            attachment_id  BIGINT UNSIGNED NOT NULL,
            file_size      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            purge_after    DATETIME        DEFAULT NULL,
            warned_at      DATETIME        DEFAULT NULL,
            consent_at     DATETIME        DEFAULT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          event_instance (event_id, instance_date),
            KEY          wp_user_id (wp_user_id),
            KEY          purge_after (purge_after),
            KEY          attachment_id (attachment_id)
        ) $charset;";

        // --- vp_photo_notices ------------------------------------------------
        // Merkt, für welche Termininstanz die "Upload-Frist abgelaufen"-Info
        // schon raus ist, damit sie nicht mehrfach verschickt wird.
        $photo_notices = "CREATE TABLE {$wpdb->prefix}vp_photo_notices (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id       BIGINT UNSIGNED NOT NULL,
            instance_date  DATE            NOT NULL,
            notified_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   event_instance (event_id, instance_date)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Erst umbauen, dann abgleichen. dbDelta kennt kein Umbenennen: Liefe
        // es zuerst, legte es person_id als ZUSÄTZLICHE Spalte an und die
        // Umbenennung liefe anschließend auf einen Namenskonflikt.
        self::migrate_claim_index();
        self::migrate_person_columns();

        dbDelta( $members      );
        dbDelta( $people       );
        dbDelta( $otp_tokens   );
        dbDelta( $sessions     );
        dbDelta( $event_types  );
        dbDelta( $events       );
        dbDelta( $recurrence   );
        dbDelta( $exceptions   );
        dbDelta( $attendance   );
        dbDelta( $items          );
        dbDelta( $item_claims    );
        dbDelta( $templates      );
        dbDelta( $template_items );
        dbDelta( $photos            );
        dbDelta( $photo_notices     );
        dbDelta( $member_companions );
        dbDelta( $event_companions  );

        update_option( 'vp_db_version', VP_VERSION );
    }

    /**
     * Benennt wp_user_id in person_id um und macht die Spalte vorzeichenfähig.
     *
     * Beides kann dbDelta nicht: Umbenennen kennt es gar nicht, und einen
     * Wechsel von UNSIGNED auf vorzeichenbehaftet nimmt es nicht vor. Ohne
     * das Vorzeichen ließe sich ein Gast auch nicht als negative ID ablegen.
     *
     * Die zugehörigen Indizes wandern beim CHANGE automatisch mit.
     */
    private static function migrate_person_columns(): void {
        global $wpdb;

        $spalten = [
            'vp_event_attendance'  => 'wp_user_id',
            'vp_event_item_claims' => 'wp_user_id',
            'vp_member_companions' => 'wp_user_id',
            'vp_event_companions'  => 'wp_user_id',
            // Auch der Ersteller eines Postens kann ein Gast sein: Über einen
            // Terminlink darf jeder „Ich bringe Salat mit“ eintragen.
            'vp_event_items'       => 'created_by',
        ];

        foreach ( $spalten as $suffix => $alt ) {
            $table = $wpdb->prefix . $suffix;

            if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
                continue;
            }

            $neu = $alt === 'created_by' ? 'created_by' : 'person_id';

            $spalte = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE '{$alt}'", ARRAY_A );
            if ( $spalte ) {
                // Nur anfassen, wenn noch UNSIGNED oder noch alter Name.
                if ( $alt !== $neu || stripos( (string) $spalte['Type'], 'unsigned' ) !== false ) {
                    $wpdb->query( "ALTER TABLE {$table} CHANGE {$alt} {$neu} BIGINT NOT NULL" );
                }
                continue;
            }

            // Schon umbenannt? Dann nur noch das Vorzeichen sicherstellen.
            $spalte = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE '{$neu}'", ARRAY_A );
            if ( $spalte && stripos( (string) $spalte['Type'], 'unsigned' ) !== false ) {
                $wpdb->query( "ALTER TABLE {$table} CHANGE {$neu} {$neu} BIGINT NOT NULL" );
            }
        }
    }

    /**
     * Tauscht den alten UNIQUE-Key (item_id, wp_user_id) gegen den neuen, der
     * die Begleitperson einschließt. dbDelta ändert bestehende Indizes nicht
     * zuverlässig, deshalb hier von Hand.
     *
     * Vorher wird die Spalte companion_id sichergestellt – bei Installationen
     * aus der Zeit vor diesem Update fehlt sie sonst noch.
     */
    private static function migrate_claim_index(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'vp_event_item_claims';

        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return;
        }

        $has_column = $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'companion_id' )
        );

        if ( ! $has_column ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN companion_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER wp_user_id" );
        }

        $old_index = $wpdb->get_var(
            $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'item_user' )
        );

        if ( $old_index ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX item_user" );
        }

        $new_index = $wpdb->get_var(
            $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'item_person' )
        );

        if ( ! $new_index ) {
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY item_person (item_id, wp_user_id, companion_id)" );
        }
    }
}
