# Vereins-Plugin

Terminorganisation für Vereine – als WordPress-Plugin, im Frontend bedienbar,
mit Zusagen, Mitbringliste, Helferaufgaben, Fotos und Aushängen.

**Autor:** Chris Helbing  
**Quellcode:** https://github.com/chrishelbing/vereins-plugin

---

## ⚠️ Disclaimer

**Dieses Projekt wurde weitgehend mit Unterstützung von KI entwickelt.**

Der Code ist in Zusammenarbeit mit einem KI-Assistenten entstanden. Er wurde
nach bestem Wissen geprüft und mit automatisierten Tests abgesichert, aber
er ist nicht von einem Sicherheitsaudit begleitet worden und wird ohne
Gewährleistung veröffentlicht.

- **Keine Haftung.** Die Nutzung erfolgt ausschließlich auf eigenes Risiko.
  Für Schäden, Datenverluste, Ausfälle, Fehlfunktionen oder Folgeschäden
  jeder Art wird keine Haftung übernommen – soweit gesetzlich zulässig.
- **Keine Zusicherung.** Die Software wird „wie besehen" (*as is*)
  bereitgestellt, ohne ausdrückliche oder stillschweigende Zusicherung
  hinsichtlich Funktionsfähigkeit, Sicherheit, Eignung für einen bestimmten
  Zweck oder Rechtsmängelfreiheit.
- **Datenschutz liegt beim Betreiber.** Das Plugin verarbeitet
  personenbezogene Daten (Namen, E-Mail-Adressen, Fotos). Es bringt
  Werkzeuge zur Löschung, Aufbewahrungsfrist und Datenauskunft mit, aber
  die Verantwortung nach DSGVO trägt allein, wer es einsetzt. Eine eigene
  datenschutzrechtliche Prüfung vor dem produktiven Einsatz wird dringend
  empfohlen.
- **Vor dem Einsatz testen.** Bitte zuerst auf einer Testinstallation
  ausprobieren und Backups anlegen. Kein Einsatz in sicherheitskritischen
  Umgebungen.

Wer das Plugin installiert, akzeptiert diese Bedingungen.

---

## Welches Problem das Plugin löst

Vereine organisieren immer wieder dieselben Dinge: Sommerfest, Zeltlager,
Jahreshauptversammlung, Arbeitseinsatz. Und um jeden dieser Termine herum
entstehen immer wieder dieselben Fragen.

**Wer kommt eigentlich?** Die Antwort liegt verteilt in einer
WhatsApp-Gruppe, in der Hälfte der Fälle als Daumen-hoch-Reaktion, dazu drei
Zusagen per Anruf und eine per Zettel am Vereinsheim. Niemand hat eine
verlässliche Zahl, und wer sie braucht, zählt von Hand nach.

**Wer bringt was mit?** Eine Liste im Gruppenchat ist nach zwanzig
Nachrichten nicht mehr auffindbar. Am Ende stehen sechs Kuchen und kein
Salat auf dem Tisch.

**Wer hilft beim Aufbau?** Helferaufgaben werden mündlich verteilt und
mündlich vergessen. Wer nicht dabei war, als es besprochen wurde, weiß
nicht, dass noch jemand für den Einlass fehlt.

**Wo sind hinterher die Fotos?** Verstreut auf zwölf Handys. Ein Teil landet
in der Gruppe und wird nach Wochen automatisch gelöscht, ein Teil nirgends.

**Und wie erreicht man die, die nicht im Verein sind?** Nachbarn, Eltern,
Interessierte. Sie sollen zusagen können, ohne sich vorher irgendwo zu
registrieren – sonst tun sie es schlicht nicht.

Das Plugin bildet diesen Kreislauf auf der Vereins-Website ab: Termin
anlegen, freigeben, teilen, Zusagen sammeln, Aufgaben verteilen, Fotos
sammeln, Sammlung abschließen. An einer Stelle, nachvollziehbar, ohne
zusätzliches Konto bei einem externen Dienst.

### Zwei Grundentscheidungen

**Die Website ist der Hauptweg.** Mitglieder erledigen alles im Frontend
über Shortcodes. Niemand muss sich im wp-admin zurechtfinden – das Backend
ist für Administratoren da, nicht für Mitglieder.

**Die Hürde bleibt niedrig.** Wer über einen geteilten Terminlink kommt,
kann zusagen, ohne sich anzumelden oder zu registrieren. Nach dem Namen wird
erst gefragt, wenn er wirklich gebraucht wird – und nur nach dem Namen.

---

## Anwendungsfälle

### 0. Der erste Tag: nicht bei null anfangen

Nach der Aktivierung ist das Plugin nicht leer. Vier Termintypen (Training
und Übung, Spiel und Wettkampf, Fest, Arbeitseinsatz) und sechs Mitbring-
und Helferlisten sind
angelegt und miteinander verknüpft – der erste Termin lässt sich sofort
erstellen, ohne vorher irgendetwas zu konfigurieren. Ausgewählt ist nach
dem Alltag eines Breitensportvereins; alles Weitere – Versammlung, Sitzung,
Marktstand, Ressourcen – legt der Verein selbst an, statt es erst
wegräumen zu müssen.

Terminserien kommen bewusst nicht mit erfundenen Daten daher – ein
geratenes Datum stünde falsch in der Liste und würde geteilt. Serien legt
der Verein im Terminformular an; die Wiederholung (täglich, wöchentlich mit
Wochentagsauswahl, monatlich, jährlich, begrenzt durch Enddatum oder
Anzahl) steht dort direkt zur Verfügung, und die Listen des Termintyps sind
schon vorbelegt.

Alles davon ist änderbar oder löschbar, und nichts kommt von selbst zurück:
Wer die Startdaten aufräumt, hat beim nächsten Update seine Ruhe. Umgekehrt
liegt unter *Verein → Einstellungen* ein Knopf, der fehlende Startdaten
nachlegt – ohne Dubletten und ohne eigene Anpassungen zu überschreiben.

### 1. Sommerfest: Zusagen von Mitgliedern und Nachbarn

Das Orgateam legt den Termin an, ein Administrator gibt ihn frei. Mitglieder
sehen ihn in der Terminliste und sagen zu – inklusive Begleitpersonen, denn
aus einer Familie kommen meist mehrere. „Mia, Tom“ sind dabei zwei Personen,
und der Dialog zeigt, wie viele zusammen kommen. Die Namen der Kinder merkt
sich das Plugin als persönliche Vorschlagsliste, damit man sie nicht jedes
Jahr neu tippt.

Für die Startseite schaltet ein Administrator am Termin den Banner ein: Ab
zwei Wochen vorher steht das Sommerfest dort für alle Besucher, nach dem
Fest verschwindet es von selbst.

Für die Nachbarschaft kopiert das Orgateam den signierten Terminlink und
teilt ihn in der Dorfgruppe. Wer ihn öffnet, sieht genau diesen einen Termin
und kann sofort zusagen. Ein Cookie erkennt ihn beim nächsten Mal wieder.
Namen anderer Teilnehmer bleiben für ihn verborgen.

### 2. Mitbringliste: sechs Kuchen, kein Salat – nicht mehr

Das Orgateam gibt den Bedarf vor: „3× Kuchen", „2× Salat", „30 Brötchen".
Mitglieder übernehmen Posten – auch mehrere Stück auf einmal: Wer 15 der 30
Brötchen mitbringt, trägt 15 ein und kann die Zahl später ändern. Wer etwas
beisteuern will, das nicht auf der Liste steht, trägt es frei ein. Sichtbar
ist jederzeit, was noch offen ist. Tippfehler korrigiert das Orgateam direkt
am Eintrag; wer schon übernommen hat, bleibt eingetragen. Für wiederkehrende Feste gibt es Vorlagen – auch aus einem
bestehenden Termin erzeugbar, sodass das Sommerfest 2027 mit der Liste von
2026 startet.

### 3. Helferplanung für den Arbeitseinsatz

„Auf- und Abbau", „Catering", „Einlass", „Parkplatzeinweisung". Das Orgateam
legt die Posten an und gibt vor, wie viele Helfer je Aufgabe gebraucht
werden; Mitglieder übernehmen je einen Platz verbindlich. Auch
Begleitpersonen können Aufgaben übernehmen – eine Familie teilt sich mehrere
Schichten. Die Helferzahl ergibt sich aus den tatsächlich übernommenen
Aufgaben, nicht aus einem unverbindlichen Häkchen.

### 4. Aushang für Schaukasten und WhatsApp

Zu jedem Termin erzeugt das Orgateam einen Aushang – A4 hoch für den
Schaukasten im Gemeindehaus, quadratisch 1080 × 1080 für WhatsApp-Status und
Gruppen, oder nur den QR-Code für eigene Gestaltung. Der QR-Code führt
direkt auf den Termin. Der Encoder ist selbst gebaut, ohne externen Dienst
und ohne Abhängigkeit.

### 5. Fotos nach dem Zeltlager einsammeln

Nach der Veranstaltung laden Mitglieder ihre Bilder hoch – auf dem Handy
direkt aus der Fotos-App, am Rechner per Drag & Drop, mit Mehrfachauswahl.
Beim Hochladen werden die Bilder verkleinert und von Standortdaten befreit.
Vor dem ersten Foto muss die Verwendung auf Website und in sozialen Medien
bestätigt werden; die Zustimmung wird serverseitig geprüft und je Foto mit
Zeitstempel festgehalten.

Nach dem Upload-Zeitraum (standardmäßig 14 Tage) werden die Teilnehmer
benachrichtigt, dass die Sammlung vollständig ist und als ZIP
heruntergeladen werden kann. Fotos sind Mitgliedern vorbehalten – wer nur
über einen Terminlink kam, kommt nicht heran.

### 6. Serientermine: das wöchentliche Training

Termine wiederholen sich täglich, wöchentlich (mit Wochentagsauswahl),
monatlich oder jährlich, begrenzt durch Enddatum oder Anzahl. Einzelne
Termine der Serie lassen sich abweichend ändern oder absagen – das Training
zwischen den Jahren fällt aus, ohne dass die ganze Serie angefasst werden
muss.

### 7. Mitgliederaufnahme ohne Karteileichen

Wer sich über das Registrierungsformular meldet, landet zunächst in einer
Warteliste – ohne WordPress-Profil. (Die Freischaltung lässt sich in den
Einstellungen abschalten; dann ist der Zugang sofort aktiv.) Ein Administrator nimmt ihn auf, und
dabei werden alle bisherigen Einträge übernommen: frühere Zusagen,
übernommene Aufgaben, Begleitpersonen. Es geht nichts verloren.

Gäste, die einmal über einen Terminlink zugesagt haben und nie
wiederkommen, werden nach einer einstellbaren Frist (Standard 180 Tage)
automatisch entfernt. Maßgeblich ist die letzte Aktivität, nicht das
Anlegedatum – wer jedes Jahr wiederkommt, bleibt.

### 8. Ein Redakteur kommt ins Orgateam

Vereinsrollen werden immer zusätzlich vergeben, nie ersetzend. Wer als
Redakteur die Website pflegt und zusätzlich ins Orgateam kommt, behält Rolle,
Rechte und Backend-Zugang. Das Plugin nimmt nie etwas weg, was die Website
schon vergeben hatte.

---

## Featureset

**Auslieferungszustand**

- Vier Termintypen vorbelegt (Training und Übung, Spiel und Wettkampf, Fest, Arbeitseinsatz), mit Farbe und passend gesetzten Schaltern
- Sechs Mitbring- und Helfervorlagen (Fest, Arbeitseinsatz, Fahrt), als Standard am jeweiligen Typ hinterlegt
- Wird nur einmal angelegt; eine gewachsene Installation bleibt unberührt, Gelöschtes kommt nicht von selbst zurück
- Knopf „Fehlende Startdaten anlegen“ für den Nachschlag – legt nur an, was fehlt, und lässt Angepasstes in Ruhe

**Termine**

- Frei definierbare Termintypen mit Farbe; je Typ steuerbar, ob Zusagen, Mitbringliste, Helferaufgaben und Fotos vorkommen
- Infotermine ohne Zusage (Termintyp ohne Zu-/Absage)
- Tags je Termintyp, z. B. Altersklassen, am Termin auswählbar
- Termine anlegen durch jedes Mitglied im Frontend, Freigabe nur durch Administratoren
- Entwurf und live: Administratoren schalten Termine jederzeit sichtbar oder unsichtbar
- Bearbeiten im Frontend über den Stift an der Kachel – durch Anleger, Orgateam und Administratoren
- Serientermine: täglich, wöchentlich (Wochentagsauswahl), monatlich, jährlich – begrenzt durch Enddatum oder Anzahl
- Abweichende Einzeltermine und Absagen innerhalb einer Serie
- Ganztägige und mehrtägige Termine mit eigener Darstellung („Mehrtägig · 3 Tage")
- Öffentliche Termine: auch für Besucher ohne Anmeldung in der Terminliste sichtbar
- Beschreibung mit Links (`https://…` oder `[Text](https://…)`), Ort als Link in die Karten-App
- Lange Kacheln gekürzt, aufklappbar mit „Mehr anzeigen“
- Titelbild je Termin
- Banner auf der Startseite (`[vp_banner]`): erscheint ab einstellbarem Vorlauf, verschwindet nach dem Termin von selbst
- Termintyp als Ressource kennzeichenbar (Bus, Vereinsheim) – nur Kennzeichnung, ohne Belegungsprüfung

**Zu- und Absagen**

- Zusage/Absage je Termininstanz
- Begleitpersonen mit persönlicher Vorschlagsliste, mehrere Namen auf einmal („Mia, Tom“), Summe im Dialog
- Eigene Antwort und Begleitpersonen unabhängig voneinander
- Teilnehmerzahlen direkt sichtbar, Namen hinter einem Klick
- CSV-Export der Teilnehmerlisten

**Mitbringliste und Helferaufgaben**

- Bedarf mit Menge durch das Orgateam vorgeben, Übernahme durch Mitglieder
- Mitbringliste: mehrere Stück auf einmal übernehmen („15 von 30 Brötchen“), Anzahl nachträglich änderbar
- Helferaufgaben: je Person ein Platz
- Freie Einträge durch Mitglieder („Ich bringe Salat mit")
- Übernahme auch durch Begleitpersonen
- Orgateam korrigiert Einträge direkt am Text (Stift), Übernahmen bleiben erhalten
- Wiederverwendbare Vorlagen, aus bestehenden Terminen erzeugbar
- Standardvorlage je Termintyp, beim Bearbeiten nachträglich anwendbar (ergänzt nur Fehlendes)

**Fotos**

- Upload je Termininstanz mit Mehrfachauswahl, Drag & Drop, Handy-Kamerarolle
- Automatisches Verkleinern und Entfernen von Standortdaten (EXIF)
- Pflicht-Zustimmung zur Verwendung, serverseitig geprüft und mit Zeitstempel dokumentiert
- Kontingente je Mitglied und Termin, maximale Dateigröße, längste Kante konfigurierbar
- Upload-Zeitraum global und je Termin einstellbar
- ZIP-Download der fertigen Sammlung, Benachrichtigung per E-Mail
- Hochladende Person als Beschreibung am Attachment, nicht im Dateinamen

**Teilnahme ohne Anmeldung**

- Signierte Terminlinks zum Teilen
- Zusagen, Helferaufgaben und Mitbringliste ohne Konto nutzbar
- Nur der Name nötig, E-Mail freiwillig
- Wiedererkennung per Cookie
- Namen anderer Teilnehmer bleiben verborgen – auch über die Schnittstelle
- Unbestätigte Kontakte in allen Listen und Exporten gekennzeichnet

**Flyer und QR-Codes**

- A4 hoch (Druck/PDF), 1080 × 1080 (PNG für Social Media), reiner QR-Code
- Titelbild, Datumsblock, Termintyp-Farbe, Ort, Kurzbeschreibung, Absenderzeile mit Logo
- Helferbedarf und Mitbringliste optional zuschaltbar
- Selbstgebauter QR-Encoder ohne externe Abhängigkeit, SVG-Ausgabe

**Anmeldung und Rollen**

- Anmeldung per Passwort oder Einmalcode per E-Mail
- Selbstregistrierung mit Freischaltung (abschaltbar)
- Zwei Vereinsrollen: „Verein: Mitglied" und „Verein: Orgateam"
- Rollen werden additiv vergeben, bestehende Website-Rollen bleiben unangetastet
- wp-admin gesperrt nur für Konten, die ausschließlich Vereinsrollen tragen
- Mitgliederverwaltung: Name, E-Mail und Telefon bearbeiten, alle Rollen einer Person sichtbar und änderbar

**Personen und Datenschutz**

- Vier Stufen: Gast, wartende Registrierung, Mitglied, Orgateam
- Kein WordPress-Profil für Unbestätigte
- Vollständige Übernahme aller Einträge bei der Aufnahme
- Aufbewahrungsfrist für inaktive offene Kontakte (Standard 180 Tage, abschaltbar)
- Vollständige Löschung von Mitgliedern inkl. Zusagen, Aufgaben, Begleitpersonen, Fotos
- Anbindung an *Werkzeuge → Persönliche Daten exportieren/löschen* von WordPress

**Schnittstelle und Technik**

- REST-API unter `vereins-plugin/v1` für Termine, Zusagen, Listen, Fotos, Anmeldung – mit Token-Sitzungen, sodass eine eigene App darauf aufsetzen könnte (eine App gehört nicht zum Plugin)
- Shortcodes: `[vp_events]`, `[vp_create_event]`, `[vp_login]`, `[vp_register]`, `[vp_banner]`
- Updates direkt in WordPress unter *Plugins* („Jetzt aktualisieren“), aus den GitHub-Releases
- Zehn automatisierte Prüfungen (`php tools/pruefen.php`): Struktur, Zugriffsrechte, Personenwege, Schnittstelle, Startdaten, QR-Encoder, Banner, Infotermine und Tags, Updates, Beschreibungstexte
- Keine externen Dienste, keine externen PHP-Abhängigkeiten

---

## Installation und Updates

1. Unter [Releases](https://github.com/chrishelbing/vereins-plugin/releases/latest) die Datei `vereins-plugin-<Version>.zip` herunterladen.
2. In WordPress unter *Plugins → Installieren → Plugin hochladen* einspielen und aktivieren.
3. Auf je einer Seite `[vp_events]` (Terminliste) und `[vp_login]` (Anmeldung) einsetzen, optional `[vp_register]`, `[vp_create_event]` und `[vp_banner]`.

Danach kommen neue Versionen von selbst: Unter *Plugins* erscheint
„Jetzt aktualisieren“, sobald es ein neues Release gibt.

---

## Bewusste Grenzen

Damit niemand danach sucht:

- Keine Beitragsverwaltung, keine Buchhaltung, keine Lastschriften
- Keine Kommunikation über einzelne Benachrichtigungen hinaus – kein Newsletter, keine internen Nachrichten
- Keine Anwesenheitskontrolle vor Ort
- Kein eigenes Anmeldesystem – wer sich anmeldet, ist WordPress-Nutzer
- Ressourcen nur als Kennzeichnung, ohne Belegungsprüfung
- Keine App – die Schnittstelle ist vorbereitet, eine App gehört nicht dazu
- Kein Versand per SMS oder WhatsApp – Einmalcodes und Benachrichtigungen gehen per E-Mail
- Kein Filter der Terminliste nach Tags

---

Ausführliche Beschreibung des Funktionsumfangs: [FEATURES.md](FEATURES.md)
