-- ============================================================================
-- MBC Trade Module - Seed (V1: 10 Wertpapiere + 3 Lektionen)
-- ============================================================================
-- Version: 1.0.1
-- Erstellt: 2026-08-30
-- Beschreibung: Fiktive Wertpapiere (keine realen Ticker/Marken) mit
--               Simulationsparametern sowie der Lernpfad V1:
--               1. Was ist eine Aktie?           (unlocks: market)
--               2. Wie liest man einen Kurschart? (unlocks: trading, V2)
--               3. Risiko & Diversifikation       (unlocks: analysis, V2)
--               Content ist Deutsch (DB-Content = DE, UI-Chrome = i18n).
-- Idempotent: Upserts über UNIQUE-Keys (symbol, slug, lesson_id+sort_order).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Wertpapiere (sim_start_date einheitlich 2024-01-01, Seeds 90101-90110)
-- ============================================================================

INSERT INTO `mbc_trade_securities`
  (`symbol`, `name`, `description`, `sector`, `currency`, `source`,
   `start_price`, `drift`, `volatility`, `seed`, `sim_start_date`, `is_active`)
VALUES
('NWE', 'Nordwind Energie AG',
 'Die Nordwind Energie AG betreibt Wind- und Solarparks in Norddeutschland. Als Versorger mit langfristigen Lieferverträgen gilt sie als solides Basisinvestment. **Lern-Hinweis:** Energie-Werte schwanken moderat, reagieren aber auf Strompreise und Politik.',
 'Energie', 'EUR', 'sim', 42.5000, 0.000350, 0.014000, 90101, '2024-01-01', 1),
('WOS', 'Wolkenschmiede Software SE',
 'Die Wolkenschmiede entwickelt Cloud-Software für den Mittelstand und wächst seit Jahren zweistellig. **Lern-Hinweis:** Wachstumswerte versprechen mehr Rendite, schwanken dafür deutlich stärker als Versorger.',
 'Technologie', 'EUR', 'sim', 118.0000, 0.000750, 0.022000, 90102, '2024-01-01', 1),
('AUB', 'Aurora Biotek AG',
 'Aurora Biotek forscht an neuartigen Therapien. Ein Studienerfolg kann den Kurs vervielfachen, ein Rückschlag halbieren. **Lern-Hinweis:** Biotech ist Hochrisiko — hier zeigt sich, warum man nie alles auf eine Karte setzt.',
 'Gesundheit', 'EUR', 'sim', 23.4000, 0.000600, 0.032000, 90103, '2024-01-01', 1),
('BBI', 'Bergblick Immobilien AG',
 'Bergblick vermietet Wohn- und Gewerbeimmobilien in Alpennähe. Stabile Mieteinnahmen sorgen für ruhige Kurse. **Lern-Hinweis:** Defensive Werte dämpfen die Schwankung eines Depots.',
 'Immobilien', 'EUR', 'sim', 67.8000, 0.000100, 0.009000, 90104, '2024-01-01', 1),
('SPL', 'Silberpfad Logistik SE',
 'Silberpfad transportiert Waren quer durch Europa. Läuft die Wirtschaft, brummt das Geschäft — in Flauten leiden die Margen. **Lern-Hinweis:** Zykliker folgen der Konjunktur, das macht Timing schwer.',
 'Industrie', 'EUR', 'sim', 54.2000, 0.000400, 0.016000, 90105, '2024-01-01', 1),
('KUK', 'Kupferkrone Rohstoffe AG',
 'Die Kupferkrone fördert Kupfer und Seltene Erden. Der Kurs hängt stark an den Weltmarktpreisen. **Lern-Hinweis:** Rohstoffwerte schwanken mit den Rohstoffpreisen — oft unabhängig vom Aktienmarkt.',
 'Rohstoffe', 'EUR', 'sim', 31.1000, 0.000200, 0.026000, 90106, '2024-01-01', 1),
('STH', 'Sternhafen Touristik AG',
 'Sternhafen betreibt Ferienresorts an der Ostsee und lebt vom Reisehunger der Kundschaft. **Lern-Hinweis:** Konsum- und Reisewerte erzählen oft Erholungs-Storys — mit entsprechend nervösen Kursen.',
 'Konsum & Reise', 'EUR', 'sim', 18.7500, 0.000450, 0.028000, 90107, '2024-01-01', 1),
('EHK', 'Eichenhain Konsum AG',
 'Eichenhain stellt Lebensmittel des täglichen Bedarfs her — gegessen wird immer. **Lern-Hinweis:** Basiskonsum gilt als klassischer Witwen-und-Waisen-Wert: wenig Rendite-Fantasie, wenig Drama.',
 'Basiskonsum', 'EUR', 'sim', 88.9000, 0.000150, 0.008000, 90108, '2024-01-01', 1),
('BLF', 'Blaufels Versicherung AG',
 'Die Blaufels versichert Häuser, Autos und Betriebe und verwaltet große Kapitalanlagen. **Lern-Hinweis:** Finanzwerte reagieren auf Zinsen — steigende Zinsen helfen oft dem Geschäft.',
 'Finanzen', 'EUR', 'sim', 96.3000, 0.000250, 0.011000, 90109, '2024-01-01', 1),
('QFT', 'Quantfeld Technologies SE',
 'Quantfeld baut Spezialchips für Rechenzentren und gilt als Liebling der Wachstumsanleger. **Lern-Hinweis:** Highflyer können lange steigen — und genauso schnell korrigieren. Der Chart zeigt beides.',
 'Technologie', 'EUR', 'sim', 210.0000, 0.000850, 0.035000, 90110, '2024-01-01', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `sector` = VALUES(`sector`),
  `start_price` = VALUES(`start_price`),
  `drift` = VALUES(`drift`),
  `volatility` = VALUES(`volatility`),
  `seed` = VALUES(`seed`),
  `sim_start_date` = VALUES(`sim_start_date`),
  `is_active` = VALUES(`is_active`);


-- ============================================================================
-- Lektion 1: Was ist eine Aktie? (unlocks: market)
-- ============================================================================

INSERT INTO `mbc_trade_lessons`
  (`slug`, `title`, `description`, `sort_order`, `required_lesson_id`, `unlocks_feature`, `pass_threshold`, `is_active`)
VALUES
('was-ist-eine-aktie', 'Was ist eine Aktie?',
 'Dein Einstieg: was du mit einer Aktie wirklich besitzt, wie Kurse entstehen und warum es die Börse gibt.',
 1, NULL, 'market', 70, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`), `unlocks_feature` = VALUES(`unlocks_feature`),
  `pass_threshold` = VALUES(`pass_threshold`), `is_active` = VALUES(`is_active`);

SET @l1 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'was-ist-eine-aktie');

INSERT INTO `mbc_trade_lesson_sections` (`lesson_id`, `sort_order`, `title`, `kind`, `content`) VALUES
(@l1, 1, 'Ein Stück vom Unternehmen', 'text',
'Eine **Aktie** ist ein Anteil an einem Unternehmen. Wer eine Aktie kauft, wird **Miteigentümer** — mit allem, was dazugehört: Du profitierst, wenn das Unternehmen wächst und Gewinne macht, und du trägst mit, wenn es schlecht läuft.

Als Aktionär hast du typischerweise zwei Ertragsquellen:

- **Kursgewinne:** Der Wert deines Anteils steigt, weil das Unternehmen (oder die Erwartung an seine Zukunft) wertvoller wird.
- **Dividenden:** Viele Unternehmen schütten einen Teil ihres Gewinns regelmäßig an die Aktionäre aus.

Wichtig: Eine Aktie ist **kein Kredit** an das Unternehmen. Es gibt keine garantierte Rückzahlung und keinen festen Zins — dein Ertrag hängt vom Erfolg des Unternehmens ab.'),
(@l1, 2, 'Warum gibt es Aktien? Börse & Kursbildung', 'info',
'Unternehmen geben Aktien aus, um **Kapital** für Wachstum einzusammeln — Maschinen, Personal, Forschung. Im Gegenzug beteiligen sie die Käufer am Unternehmen.

Die **Börse** ist der Marktplatz, auf dem Aktien den Besitzer wechseln. Der **Kurs** ist dabei nichts Magisches: Er entsteht aus **Angebot und Nachfrage**. Wollen mehr Menschen kaufen als verkaufen, steigt der Kurs — und umgekehrt.

In die Nachfrage fließt vor allem die **Erwartung an die Zukunft** ein: Gewinnaussichten, Nachrichten, Zinsen, Stimmung. Deshalb bewegen sich Kurse ständig, auch wenn im Unternehmen selbst gerade nichts passiert.'),
(@l1, 3, 'Beispiel: Die Nordwind Energie AG', 'example',
'Schau dir später im **Markt** die *Nordwind Energie AG (NWE)* an — unseren fiktiven Windpark-Betreiber.

Angenommen, du kaufst eine NWE-Aktie für 42,50 EUR. Damit gehört dir ein winziger Teil aller Windräder, Verträge und zukünftigen Gewinne. Meldet Nordwind einen starken Jahresabschluss, wollen mehr Anleger einsteigen — die Nachfrage hebt den Kurs. Kommt eine teure Reparaturwelle, drücken Verkäufe den Kurs.

**Alle Kurse in diesem Lernbereich sind simuliert.** Es ist Spielgeld-Terrain: Du kannst hier nichts falsch machen — nutze das, um ein Gefühl für Schwankungen zu entwickeln.')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `kind` = VALUES(`kind`), `content` = VALUES(`content`);

INSERT INTO `mbc_trade_quiz_questions` (`lesson_id`, `sort_order`, `question`, `options`, `correct_key`, `explanation`) VALUES
(@l1, 1, 'Was besitzt du, wenn du eine Aktie kaufst?',
 '[{"key":"a","text":"Einen Anteil am Unternehmen"},{"key":"b","text":"Einen Kredit, den das Unternehmen zurückzahlen muss"},{"key":"c","text":"Ein Recht auf einen festen Zins"},{"key":"d","text":"Ein Produkt des Unternehmens"}]',
 'a', 'Eine Aktie macht dich zum Miteigentümer. Anders als bei einer Anleihe gibt es weder feste Zinsen noch eine garantierte Rückzahlung — dein Ertrag hängt vom Unternehmenserfolg ab.'),
(@l1, 2, 'Wodurch entsteht ein Aktienkurs?',
 '[{"key":"a","text":"Die Regierung legt ihn fest"},{"key":"b","text":"Durch Angebot und Nachfrage an der Börse"},{"key":"c","text":"Das Unternehmen bestimmt ihn selbst"},{"key":"d","text":"Er entspricht immer dem Firmenvermögen geteilt durch die Aktienzahl"}]',
 'b', 'Der Kurs bildet sich aus Angebot und Nachfrage. Erwartungen an die Zukunft — Gewinne, Nachrichten, Zinsen — bestimmen, wie viele kaufen oder verkaufen wollen.'),
(@l1, 3, 'Was ist eine Dividende?',
 '[{"key":"a","text":"Die Gebühr beim Aktienkauf"},{"key":"b","text":"Der garantierte Jahreszins einer Aktie"},{"key":"c","text":"Eine Gewinnausschüttung an die Aktionäre"},{"key":"d","text":"Der Kursgewinn beim Verkauf"}]',
 'c', 'Viele Unternehmen schütten einen Teil ihres Gewinns als Dividende aus. Sie ist freiwillig und kann gekürzt oder gestrichen werden — kein garantierter Zins.'),
(@l1, 4, 'Warum geben Unternehmen überhaupt Aktien aus?',
 '[{"key":"a","text":"Um Steuern zu sparen"},{"key":"b","text":"Um Kapital für Wachstum einzusammeln"},{"key":"c","text":"Weil es gesetzlich vorgeschrieben ist"},{"key":"d","text":"Um ihre Produkte bekannter zu machen"}]',
 'b', 'Mit dem Verkauf von Anteilen sammeln Unternehmen Geld für Investitionen ein — dafür beteiligen sie die Käufer am zukünftigen Erfolg.')
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `options` = VALUES(`options`),
  `correct_key` = VALUES(`correct_key`), `explanation` = VALUES(`explanation`);


-- ============================================================================
-- Lektion 2: Wie liest man einen Kurschart? (unlocks: trading, greift in V2)
-- ============================================================================

INSERT INTO `mbc_trade_lessons`
  (`slug`, `title`, `description`, `sort_order`, `required_lesson_id`, `unlocks_feature`, `pass_threshold`, `is_active`)
VALUES
('kurscharts-lesen', 'Wie liest man einen Kurschart?',
 'Achsen, Zeiträume, Trends und Fallen: Lerne, was ein Chart wirklich zeigt — und was nicht.',
 2, @l1, 'trading', 70, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`), `required_lesson_id` = VALUES(`required_lesson_id`),
  `unlocks_feature` = VALUES(`unlocks_feature`), `pass_threshold` = VALUES(`pass_threshold`),
  `is_active` = VALUES(`is_active`);

SET @l2 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'kurscharts-lesen');

INSERT INTO `mbc_trade_lesson_sections` (`lesson_id`, `sort_order`, `title`, `kind`, `content`) VALUES
(@l2, 1, 'Achsen & Zeiträume', 'text',
'Ein Kurschart zeigt den **Preisverlauf** eines Wertpapiers: Auf der **X-Achse** läuft die Zeit, auf der **Y-Achse** steht der Kurs. In unserem Markt siehst du **Tagesschlusskurse** — einen Punkt pro Handelstag.

Der gewählte **Zeitraum** verändert das Bild komplett: Ein Monat zeigt das tägliche Zittern, ein Jahr zeigt die große Richtung. Bevor du einen Chart bewertest, prüfe immer zuerst, **welchen Zeitraum** du gerade betrachtest.'),
(@l2, 2, 'Trends erkennen', 'tip',
'Ein **Aufwärtstrend** liegt vor, wenn der Kurs über längere Zeit steigende Hochs und steigende Tiefs bildet — ein **Abwärtstrend** entsprechend fallende. Dazwischen gibt es **Seitwärtsphasen**, in denen der Kurs um ein Niveau pendelt.

**Tipp:** Zoome heraus. Auf lange Sicht wird der zugrunde liegende Trend sichtbar, während kurze Zeiträume vor allem Rauschen zeigen. Vergleiche im Markt die *Quantfeld Technologies* (starker Drift) mit der *Eichenhain Konsum AG* (ruhiger Verlauf) über ein Jahr.'),
(@l2, 3, 'Volatilität: Zacken sind normal', 'info',
'Wie stark ein Kurs um seinen Trend schwankt, heißt **Volatilität**. Starke Zacken bedeuten: hohe Unsicherheit, hohes Risiko — aber auch höhere Chancen.

Schwankung ist **kein Fehler im System**, sondern der Normalzustand. Selbst in einem sauberen Aufwärtstrend gibt es rote Tage und Rücksetzer. Wer das weiß, verkauft nicht in Panik beim ersten Knick.'),
(@l2, 4, 'Vorsicht Chart-Fallen', 'warning',
'Drei klassische Fallen beim Chart-Lesen:

1. **Gestauchte Y-Achse:** Beginnt die Achse nicht bei einem sinnvollen Wert, wirken Mini-Bewegungen wie Kurssprünge.
2. **Rosinen-Zeitraum:** Wer nur den passenden Ausschnitt zeigt, kann fast jede Story erzählen. Wechsle selbst die Zeiträume.
3. **Vergangenheit ≠ Zukunft:** Ein Chart zeigt, was **war**. Er ist keine Garantie dafür, was kommt — auch wenn Muster noch so überzeugend aussehen.')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `kind` = VALUES(`kind`), `content` = VALUES(`content`);

INSERT INTO `mbc_trade_quiz_questions` (`lesson_id`, `sort_order`, `question`, `options`, `correct_key`, `explanation`) VALUES
(@l2, 1, 'Was zeigt die Y-Achse in unserem Kurschart?',
 '[{"key":"a","text":"Die Zeit"},{"key":"b","text":"Das Handelsvolumen"},{"key":"c","text":"Den Kurs des Wertpapiers"},{"key":"d","text":"Die Anzahl der Aktionäre"}]',
 'c', 'Die Y-Achse zeigt den Preis, die X-Achse die Zeit. In unserem Markt ist jeder Punkt ein Tagesschlusskurs.'),
(@l2, 2, 'Woran erkennst du einen Abwärtstrend?',
 '[{"key":"a","text":"An einem einzelnen roten Tag"},{"key":"b","text":"An fallenden Hochs und fallenden Tiefs über längere Zeit"},{"key":"c","text":"Daran, dass der Kurs unter 50 EUR liegt"},{"key":"d","text":"An besonders starken Zacken"}]',
 'b', 'Ein Trend zeigt sich über längere Zeit: fallende Hochs und fallende Tiefs. Ein einzelner roter Tag ist normales Rauschen, und der absolute Kurswert sagt nichts über die Richtung.'),
(@l2, 3, 'Was bedeutet eine stark gezackte Kurslinie?',
 '[{"key":"a","text":"Das Wertpapier ist fehlerhaft"},{"key":"b","text":"Hohe Volatilität — der Kurs schwankt stark"},{"key":"c","text":"Der Kurs wird bald steigen"},{"key":"d","text":"Es gibt viele Aktionäre"}]',
 'b', 'Starke Zacken bedeuten hohe Volatilität: mehr Unsicherheit und Risiko, aber auch größere Chancen. Über die künftige Richtung sagen sie nichts aus.'),
(@l2, 4, 'Warum solltest du beim Chart-Lesen den Zeitraum wechseln?',
 '[{"key":"a","text":"Weil kurze Ausschnitte täuschen können und der Trend erst im großen Bild sichtbar wird"},{"key":"b","text":"Weil die Kurse sonst nicht aktualisiert werden"},{"key":"c","text":"Weil lange Zeiträume immer steigende Kurse zeigen"},{"key":"d","text":"Das ist nicht nötig, ein Monat reicht immer"}]',
 'a', 'Ein gewählter Ausschnitt kann fast jede Story erzählen. Erst der Blick auf mehrere Zeiträume zeigt, ob eine Bewegung Rauschen oder Trend ist.')
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `options` = VALUES(`options`),
  `correct_key` = VALUES(`correct_key`), `explanation` = VALUES(`explanation`);


-- ============================================================================
-- Lektion 3: Risiko & Diversifikation (unlocks: analysis, greift in V2)
-- ============================================================================

INSERT INTO `mbc_trade_lessons`
  (`slug`, `title`, `description`, `sort_order`, `required_lesson_id`, `unlocks_feature`, `pass_threshold`, `is_active`)
VALUES
('risiko-und-diversifikation', 'Risiko & Diversifikation',
 'Warum Rendite und Risiko zusammengehören — und wie Streuung dein Depot ruhiger macht.',
 3, @l2, 'analysis', 70, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`), `required_lesson_id` = VALUES(`required_lesson_id`),
  `unlocks_feature` = VALUES(`unlocks_feature`), `pass_threshold` = VALUES(`pass_threshold`),
  `is_active` = VALUES(`is_active`);

SET @l3 := (SELECT lessons_id FROM mbc_trade_lessons WHERE slug = 'risiko-und-diversifikation');

INSERT INTO `mbc_trade_lesson_sections` (`lesson_id`, `sort_order`, `title`, `kind`, `content`) VALUES
(@l3, 1, 'Rendite und Risiko gehören zusammen', 'text',
'An der Börse gilt eine einfache, unbequeme Regel: **Mehr erwartete Rendite gibt es nur für mehr Risiko.** Ein Wert wie unsere *Aurora Biotek* kann sich vervielfachen — oder abstürzen. Die *Eichenhain Konsum AG* wird dich selten überraschen, in keine Richtung.

**Risiko** heißt hier: die Bandbreite möglicher Ergebnisse. Wer hohe Renditen sucht, muss zwischenzeitliche Verluste aushalten können — finanziell **und** nervlich. Wer ruhig schlafen will, akzeptiert dafür geringere Erwartungen. Beides ist legitim; wichtig ist, dass du die Wahl **bewusst** triffst.'),
(@l3, 2, 'Nicht alle Eier in einen Korb', 'tip',
'**Diversifikation** heißt streuen: Statt alles auf ein Papier zu setzen, verteilst du dein Kapital auf mehrere Werte, Branchen und Risikoprofile.

Der Effekt: Einzelrisiken gleichen sich teilweise aus. Fällt der Rohstoffpreis, leidet die *Kupferkrone* — aber deine *Blaufels Versicherung* interessiert das wenig. Das **Gesamtdepot** schwankt dadurch weniger als seine wildesten Einzelwerte.

**Tipp:** Streuung schützt vor dem Risiko einzelner Unternehmen (Pleite, Skandal, Studienflop) — dem sogenannten **Klumpenrisiko**.'),
(@l3, 3, 'Beispiel-Depot aus unseren 10 Werten', 'example',
'So könnte ein gestreutes Lern-Depot aus unserem Markt aussehen:

- **Stabile Basis:** Eichenhain Konsum, Bergblick Immobilien, Blaufels Versicherung
- **Solide Mitte:** Nordwind Energie, Silberpfad Logistik
- **Wachstums-Würze:** Wolkenschmiede Software, ein kleiner Anteil Quantfeld

Beachte: Auch das breiteste Depot schützt **nicht** vor einem allgemeinen Markteinbruch — wenn alles fällt, fällt auch ein gestreutes Depot, nur meist weniger tief. Diversifikation glättet, sie zaubert das Risiko nicht weg. In V2 kannst du genau dieses Depot mit Spielgeld nachbauen.')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `kind` = VALUES(`kind`), `content` = VALUES(`content`);

INSERT INTO `mbc_trade_quiz_questions` (`lesson_id`, `sort_order`, `question`, `options`, `correct_key`, `explanation`) VALUES
(@l3, 1, 'Was beschreibt das Verhältnis von Rendite und Risiko am besten?',
 '[{"key":"a","text":"Hohe Rendite gibt es auch ohne Risiko, man muss nur die richtige Aktie finden"},{"key":"b","text":"Mehr erwartete Rendite ist im Normalfall nur mit mehr Risiko zu haben"},{"key":"c","text":"Risiko und Rendite haben nichts miteinander zu tun"},{"key":"d","text":"Hohes Risiko garantiert hohe Rendite"}]',
 'b', 'Rendite ist die Belohnung für getragenes Risiko. Hohes Risiko ERMÖGLICHT hohe Rendite, garantiert sie aber nie — sonst wäre es kein Risiko.'),
(@l3, 2, 'Was ist ein Klumpenrisiko?',
 '[{"key":"a","text":"Das Risiko, dass die Börse einen Tag geschlossen ist"},{"key":"b","text":"Ein zu großer Teil des Kapitals hängt an einem einzelnen Wert oder einer Branche"},{"key":"c","text":"Das Risiko von Gebühren beim Kauf"},{"key":"d","text":"Wenn ein Kurs stark gezackt ist"}]',
 'b', 'Klumpenrisiko entsteht, wenn viel Kapital an einem Unternehmen oder einer Branche hängt. Ein einzelner Rückschlag trifft dann das ganze Depot.'),
(@l3, 3, 'Wie streust du ein Depot sinnvoll?',
 '[{"key":"a","text":"Zehn Aktien aus derselben Branche kaufen"},{"key":"b","text":"Über verschiedene Branchen und Risikoprofile verteilen"},{"key":"c","text":"Nur den Wert mit dem besten Chart kaufen"},{"key":"d","text":"Jeden Tag alle Positionen tauschen"}]',
 'b', 'Echte Streuung braucht unterschiedliche Branchen und Risikoprofile — zehn Tech-Werte sind kaum besser als einer, weil sie oft gemeinsam fallen.'),
(@l3, 4, 'Wovor schützt Diversifikation NICHT?',
 '[{"key":"a","text":"Vor der Pleite eines einzelnen Unternehmens im Depot"},{"key":"b","text":"Vor einem Skandal bei einem Einzelwert"},{"key":"c","text":"Vor einem allgemeinen Markteinbruch, bei dem fast alles fällt"},{"key":"d","text":"Vor einem Studienflop einer einzelnen Biotech-Firma"}]',
 'c', 'Streuung neutralisiert Einzelrisiken. Das Marktrisiko — wenn fast alles gleichzeitig fällt — bleibt; ein gestreutes Depot fällt dann meist nur weniger tief.'),
(@l3, 5, 'Warum spielt der Zeithorizont beim Risiko eine Rolle?',
 '[{"key":"a","text":"Weil Verluste über lange Zeiträume häufiger werden"},{"key":"b","text":"Weil zwischenzeitliche Rücksetzer bei langem Anlagehorizont eher ausgesessen werden können"},{"key":"c","text":"Weil Aktien nach genau einem Jahr immer im Plus sind"},{"key":"d","text":"Der Zeithorizont ist egal"}]',
 'b', 'Wer lange investiert bleiben kann, muss zwischenzeitliche Tiefs nicht zum schlechtesten Zeitpunkt realisieren. Eine Garantie auf Gewinn ist auch ein langer Horizont aber nicht.')
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `options` = VALUES(`options`),
  `correct_key` = VALUES(`correct_key`), `explanation` = VALUES(`explanation`);

COMMIT;

-- ============================================================================
-- Schema-Version
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('trade', '1.0.1', 'Trade V1 — Seed: 10 fiktive Wertpapiere + Lernpfad (3 Lektionen, 10 Abschnitte, 13 Quizfragen)')
ON DUPLICATE KEY UPDATE
  version = '1.0.1',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Trade V1 — Seed: 10 fiktive Wertpapiere + Lernpfad (3 Lektionen, 10 Abschnitte, 13 Quizfragen)';
