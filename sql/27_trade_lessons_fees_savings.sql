-- ============================================================================
-- MBC Trade Module - Lektionen 4+5 (Content-Ausbau)
-- ============================================================================
-- Version: 2.1.0
-- Erstellt: 2026-08-30
-- Beschreibung: Zwei neue Lernpfad-Lektionen (reiner Seed, kein Code nötig —
--               das V1-Modell trägt Abschnitte, Quiz und Verkettung):
--               4. Gebühren & Kosten          (required: Lektion 3)
--               5. Sparplan vs. Einmalanlage  (required: Lektion 4)
--               Beide ohne unlocks_feature — reines Wissen, direkt im
--               Papertrading (1-EUR-Flat-Fee) erlebbar.
-- Idempotent: Upserts über UNIQUE-Keys (slug, lesson_id+sort_order).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

SET @l3 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'risiko-und-diversifikation');

-- ============================================================================
-- Lektion 4: Gebühren & Kosten
-- ============================================================================

INSERT INTO `mbc_trade_lessons`
  (`slug`, `title`, `description`, `sort_order`, `required_lesson_id`, `unlocks_feature`, `pass_threshold`, `is_active`)
VALUES
('gebuehren-und-kosten', 'Gebühren & Kosten',
 'Warum jede Order Geld kostet — und wie du verhinderst, dass Gebühren deine Rendite auffressen.',
 4, @l3, NULL, 70, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`), `required_lesson_id` = VALUES(`required_lesson_id`),
  `unlocks_feature` = VALUES(`unlocks_feature`), `pass_threshold` = VALUES(`pass_threshold`),
  `is_active` = VALUES(`is_active`);

SET @l4 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'gebuehren-und-kosten');

INSERT INTO `mbc_trade_lesson_sections` (`lesson_id`, `sort_order`, `title`, `kind`, `content`) VALUES
(@l4, 1, 'Jede Order kostet', 'text',
'Beim echten Broker zahlst du für fast jede Order **Gebühren** — mal pauschal, mal prozentual, dazu oft versteckte Kosten wie den **Spread** (Differenz zwischen Kauf- und Verkaufskurs). In unserem Übungsdepot ist es bewusst einfach: **1 € pauschal pro Order.**

Klingt harmlos? Entscheidend ist das **Verhältnis zur Ordergröße**: Bei einer Order über 1.000 € ist 1 € nur 0,1 %. Bei einer Order über 20 € sind es **5 %** — so viel muss der Kurs erst einmal steigen, bevor du überhaupt bei null bist.

Beim Verkauf gilt dasselbe: Dein Erlös ist **Stückzahl × Kurs minus Gebühr**. Kosten wirken also doppelt — beim Einstieg und beim Ausstieg.'),
(@l4, 2, 'Rechnen wir nach', 'example',
'Zwei Wege, 1.000 € zu investieren:

- **Zehn kleine Orders** à 100 €: 10 × 1 € = **10 € Gebühren** — 1 % deines Kapitals ist weg, bevor irgendein Kurs sich bewegt hat.
- **Eine Order** über 1.000 €: **1 € Gebühr** — 0,1 %.

Und wer hin und her handelt, zahlt doppelt: Kaufen (1 €), Verkaufen (1 €), wieder Kaufen (1 €) … Nach zehn Umschichtungen sind 20 € weg — dafür muss dein Depot erst einmal 2 % Rendite erwirtschaften.

Probiere es im **Depot** aus: Die Order-Vorschau zeigt dir Kaufkosten und Verkaufserlös immer inklusive Gebühr.'),
(@l4, 3, 'Weniger ist mehr', 'tip',
'Ein alter Börsenspruch bringt es auf den Punkt: **„Hin und her macht Taschen leer."**

Vieltraden fühlt sich nach Kontrolle an, kostet aber doppelt: Gebühren bei jeder Order — und häufig schlechtes Timing obendrauf. Die entspannte Alternative heißt **Kaufen und Halten**: seltener handeln, größere Positionen, lange Haltedauer.

**Tipp:** Bevor du eine Order abschickst, frag dich: Handle ich aus einem Plan heraus — oder nur, weil sich der Kurs bewegt hat?')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `kind` = VALUES(`kind`), `content` = VALUES(`content`);

INSERT INTO `mbc_trade_quiz_questions` (`lesson_id`, `sort_order`, `question`, `options`, `correct_key`, `explanation`) VALUES
(@l4, 1, 'Warum trifft eine Pauschalgebühr kleine Orders härter als große?',
 '[{"key":"a","text":"Weil kleine Orders langsamer ausgeführt werden"},{"key":"b","text":"Weil die Gebühr bei kleinen Orders einen größeren Prozentsatz des Kapitals ausmacht"},{"key":"c","text":"Tut sie nicht — 1 € ist immer 1 €"},{"key":"d","text":"Weil kleine Orders riskanter sind"}]',
 'b', 'Absolut ist die Gebühr gleich, relativ nicht: 1 € von 20 € sind 5 %, 1 € von 1.000 € nur 0,1 %. Der Kurs muss die Gebühr erst wieder hereinholen.'),
(@l4, 2, 'Was kostet dich häufiges Umschichten (kaufen → verkaufen → kaufen …)?',
 '[{"key":"a","text":"Nichts, solange die Kurse steigen"},{"key":"b","text":"Nur Zeit"},{"key":"c","text":"Bei jeder einzelnen Order fällt die Gebühr erneut an"},{"key":"d","text":"Nur beim allerersten Kauf fällt eine Gebühr an"}]',
 'c', 'Jede Order kostet — Kauf wie Verkauf. Zehn Umschichtungen bedeuten zwanzig Orders und damit zwanzig Mal Gebühren, die deine Rendite erst verdienen muss.'),
(@l4, 3, 'Wie berechnet sich dein Erlös beim Verkauf in unserem Depot?',
 '[{"key":"a","text":"Stückzahl × Kurs plus Gebühr"},{"key":"b","text":"Stückzahl × Kurs minus Gebühr"},{"key":"c","text":"Stückzahl × Einstandskurs"},{"key":"d","text":"Immer der Betrag, den du investiert hast"}]',
 'b', 'Die Gebühr wird vom Verkaufswert abgezogen: Erlös = Stückzahl × Kurs − 1 €. Kosten wirken beim Einstieg UND beim Ausstieg.'),
(@l4, 4, 'Welche Strategie hält die Gebührenlast klein?',
 '[{"key":"a","text":"Viele kleine Orders über den Tag verteilen"},{"key":"b","text":"Täglich umschichten, um immer im besten Wert zu sein"},{"key":"c","text":"Seltener handeln und Positionen länger halten"},{"key":"d","text":"Nur Werte unter 20 € kaufen"}]',
 'c', 'Kaufen und Halten minimiert die Zahl der Orders — und damit die Gebühren. „Hin und her macht Taschen leer."')
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `options` = VALUES(`options`),
  `correct_key` = VALUES(`correct_key`), `explanation` = VALUES(`explanation`);

-- ============================================================================
-- Lektion 5: Sparplan vs. Einmalanlage
-- ============================================================================

INSERT INTO `mbc_trade_lessons`
  (`slug`, `title`, `description`, `sort_order`, `required_lesson_id`, `unlocks_feature`, `pass_threshold`, `is_active`)
VALUES
('sparplan-vs-einmalanlage', 'Sparplan vs. Einmalanlage',
 'Alles auf einmal investieren oder in Raten? Was der Durchschnittskosteneffekt kann — und was nicht.',
 5, @l4, NULL, 70, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`), `required_lesson_id` = VALUES(`required_lesson_id`),
  `unlocks_feature` = VALUES(`unlocks_feature`), `pass_threshold` = VALUES(`pass_threshold`),
  `is_active` = VALUES(`is_active`);

SET @l5 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'sparplan-vs-einmalanlage');

INSERT INTO `mbc_trade_lesson_sections` (`lesson_id`, `sort_order`, `title`, `kind`, `content`) VALUES
(@l5, 1, 'Zwei Wege zu investieren', 'text',
'Du hast Geld übrig — wie kommt es in den Markt?

- **Einmalanlage:** Du investierst den ganzen Betrag sofort. Dein Geld ist ab Tag eins voll investiert und arbeitet die gesamte Zeit.
- **Sparplan:** Du investierst in festen **Raten** (z. B. monatlich denselben Betrag), egal wie der Kurs gerade steht.

Beide Wege sind legitim — sie unterscheiden sich darin, wie viel **Timing-Risiko** du auf einen einzigen Zeitpunkt konzentrierst und wie leicht dir das Durchhalten fällt.'),
(@l5, 2, 'Der Durchschnittskosteneffekt', 'info',
'Beim Sparplan kauft dieselbe Rate bei **niedrigen Kursen mehr Stücke** und bei **hohen Kursen weniger**. Dein Einstandskurs wird dadurch zum Durchschnitt über viele Zeitpunkte — der eine, perfekt oder katastrophal getimte Einstieg entfällt.

Das nennt sich **Durchschnittskosteneffekt** (Cost-Average-Effekt). Er **glättet** deinen Einstieg und nimmt die Angst vor dem „falschen Moment".

Aber Ehrlichkeit gehört dazu: Weil Märkte langfristig eher steigen, schneidet die **frühe Einmalanlage statistisch häufig besser** ab — Geld, das an der Seitenlinie auf die nächste Rate wartet, verpasst Rendite. Der Sparplan gewinnt vor allem **psychologisch**: Er macht Investieren zur Routine.'),
(@l5, 3, 'Probiere es im Depot', 'example',
'Spiel beide Strategien mit Spielgeld durch:

- **Einmalanlage:** Kaufe heute z. B. 10 × *Eichenhain Konsum* auf einmal (1 € Gebühr, voll investiert).
- **Sparplan-Simulation:** Kaufe stattdessen über mehrere Tage verteilt jeweils 2 Stück und beobachte, wie sich dein **Ø-Einstandskurs** in der Positionszeile verändert.

Denk an Lektion 4: Jede Sparplan-Rate ist eine eigene Order mit eigener Gebühr — je kleiner die Rate, desto stärker frisst die Pauschale. Echte Broker bieten für Sparpläne deshalb oft reduzierte Gebühren.'),
(@l5, 4, 'Zeit im Markt schlägt Market-Timing', 'tip',
'Egal ob Rate oder Einmalbetrag — der teuerste Fehler ist meist das **Warten auf den perfekten Moment**, der nie kommt.

**„Time in the market beats timing the market":** Wer lange investiert bleibt, gibt dem Zinseszins Zeit zu arbeiten und muss zwischenzeitliche Tiefs nicht zum schlechtesten Zeitpunkt realisieren (Lektion 3 lässt grüßen).

**Tipp:** Wähle die Methode, die du **durchhältst** — die beste Strategie ist die, die du nicht im ersten Kurssturz über Bord wirfst.')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `kind` = VALUES(`kind`), `content` = VALUES(`content`);

INSERT INTO `mbc_trade_quiz_questions` (`lesson_id`, `sort_order`, `question`, `options`, `correct_key`, `explanation`) VALUES
(@l5, 1, 'Was passiert beim Sparplan, wenn die Kurse fallen?',
 '[{"key":"a","text":"Die Rate kauft mehr Stücke als bei hohen Kursen"},{"key":"b","text":"Die Rate wird automatisch ausgesetzt"},{"key":"c","text":"Die Rate kauft weniger Stücke"},{"key":"d","text":"Der Sparplan macht dann Verlust ohne Gegenwert"}]',
 'a', 'Dieselbe Rate bekommt bei niedrigen Kursen mehr Stücke — genau das ist der Durchschnittskosteneffekt: Der Einstand wird zum Durchschnitt über viele Zeitpunkte.'),
(@l5, 2, 'Was kann der Durchschnittskosteneffekt NICHT?',
 '[{"key":"a","text":"Den Einstiegszeitpunkt glätten"},{"key":"b","text":"Die Angst vor dem falschen Moment nehmen"},{"key":"c","text":"Eine höhere Rendite als die frühe Einmalanlage garantieren"},{"key":"d","text":"Investieren zur Routine machen"}]',
 'c', 'Er glättet den Einstieg — mehr nicht. Weil Märkte langfristig eher steigen, liegt die frühe Einmalanlage statistisch häufig vorn; garantiert ist beides nicht.'),
(@l5, 3, 'Warum schneidet die frühe Einmalanlage statistisch oft besser ab?',
 '[{"key":"a","text":"Weil sie weniger riskant ist"},{"key":"b","text":"Weil das Geld sofort voll investiert ist und nicht wartend Rendite verpasst"},{"key":"c","text":"Weil Broker Einmalanlagen bevorzugen"},{"key":"d","text":"Weil man dabei den Tiefpunkt trifft"}]',
 'b', 'Geld an der Seitenlinie verpasst Marktphasen. Da Märkte langfristig eher steigen, ist früh und voll investiert im Schnitt im Vorteil — bei höherem Timing-Risiko des einen Einstiegs.'),
(@l5, 4, 'Worauf musst du bei kleinen Sparplan-Raten achten (Stichwort Lektion 4)?',
 '[{"key":"a","text":"Auf die Uhrzeit der Ausführung"},{"key":"b","text":"Dass die Pauschalgebühr pro Rate prozentual stark ins Gewicht fällt"},{"key":"c","text":"Dass man nur ganze Stücke kaufen kann"},{"key":"d","text":"Auf nichts — Raten sind immer gebührenfrei"}]',
 'b', 'Jede Rate ist eine eigene Order mit eigener Gebühr. Bei einer 20-€-Rate sind 1 € bereits 5 % — deshalb haben echte Sparpläne oft reduzierte Konditionen.'),
(@l5, 5, 'Welche Strategie ist am Ende „die beste"?',
 '[{"key":"a","text":"Immer die Einmalanlage"},{"key":"b","text":"Immer der Sparplan"},{"key":"c","text":"Die, die du in guten wie in schlechten Phasen durchhältst"},{"key":"d","text":"Gar nicht investieren, bis der perfekte Moment kommt"}]',
 'c', 'Die beste Strategie nützt nichts, wenn du sie im ersten Kurssturz aufgibst. Durchhaltbarkeit schlägt Optimierung — und Warten auf den perfekten Moment kostet meist am meisten.')
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `options` = VALUES(`options`),
  `correct_key` = VALUES(`correct_key`), `explanation` = VALUES(`explanation`);

COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('trade', '2.1.0', 'Trade — Lektionen 4 (Gebühren & Kosten) + 5 (Sparplan vs. Einmalanlage): 7 Abschnitte, 9 Quizfragen')
ON DUPLICATE KEY UPDATE
  version = '2.1.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Trade — Lektionen 4 (Gebühren & Kosten) + 5 (Sparplan vs. Einmalanlage): 7 Abschnitte, 9 Quizfragen';
