<?php
/**
 * Le regole di inserimento: quanti link affiliati finiscono in un articolo.
 *
 * Il difetto che queste prove sorvegliano: `enforce()` conta **solo gli
 * shortcode**, ma la pipeline AI, dopo averla eseguita, aggiunge ancore
 * grezze (`link_used_affiliate_images`, fino a tredici) e una card
 * universale. L'impostazione «un inserimento ogni 300 parole» governava
 * quindi soltanto i link che il modello aveva scritto da sé: un articolo da
 * 900 parole regolato su tre inserimenti poteva uscirne con diciassette.
 *
 * Si esegue con: php tests/test-insertion-rules.php
 *
 * @package AffiliateLinkManagerAI
 */

if ('cli' !== PHP_SAPI) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/class-ai-insertion-rules.php';

/**
 * Un testo di N parole, per ragionare sul tetto senza contare a mano.
 *
 * @param int $quante Numero di parole.
 * @return string
 */
function alma_test_parole($quante) {
    return implode(' ', array_fill(0, max(1, (int) $quante), 'parola'));
}

$regole = array('density_words' => 300, 'min_paragraphs_before' => 0, 'patterns' => array('anchor', 'widget'));

echo "\nIl conteggio degli inserimenti\n";

alma_assert_same(0, ALMA_AI_Insertion_Rules::count_insertions('Testo senza niente.'), 'un testo pulito non conta niente');

alma_assert_same(
    2,
    ALMA_AI_Insertion_Rules::count_insertions('[affiliate_link id="1"] e poi [affiliate_links_widget id="3"]'),
    'gli shortcode si contano, anche il widget'
);

/*
 * La riga che conta davvero. Le ancore grezze sono quello che la pipeline
 * aggiunge DOPO enforce(), ed è quello che prima nessuno contava.
 */
alma_assert_same(
    1,
    ALMA_AI_Insertion_Rules::count_insertions('<a href="https://x.test" data-link-id="12" data-track="1">Hotel</a>'),
    'un’ancora grezza tracciata conta come un inserimento'
);

alma_assert_same(
    3,
    ALMA_AI_Insertion_Rules::count_insertions(
        '[affiliate_link id="1"] <a href="https://x.test" data-link-id="12">a</a> <a href="https://y.test" data-link-id="13">b</a>'
    ),
    'shortcode e ancore si sommano'
);

alma_assert_same(
    0,
    ALMA_AI_Insertion_Rules::count_insertions('<a href="https://esempio.test/guida/">Una guida interna</a>'),
    'un link interno non è un inserimento affiliato'
);

alma_assert_same(
    1,
    ALMA_AI_Insertion_Rules::count_insertions("<a\n  href=\"https://x.test\"\n  data-link-id=\"12\">Hotel</a>"),
    'un’ancora scritta su più righe si conta lo stesso'
);

echo "\nQuanto spazio resta\n";

$novecento = alma_test_parole(900);

alma_assert_same(
    3,
    ALMA_AI_Insertion_Rules::remaining_budget($novecento, $regole),
    'novecento parole, nessun link: tre inserimenti disponibili'
);

alma_assert_same(
    1,
    ALMA_AI_Insertion_Rules::remaining_budget($novecento . ' [affiliate_link id="1"] [affiliate_link id="2"]', $regole),
    'due già messi: ne resta uno'
);

alma_assert_same(
    0,
    ALMA_AI_Insertion_Rules::remaining_budget(
        $novecento . ' [affiliate_link id="1"]<a href="#" data-link-id="2">x</a><a href="#" data-link-id="3">y</a>',
        $regole
    ),
    'il tetto conta anche le ancore: qui non resta niente'
);

alma_assert_same(
    0,
    ALMA_AI_Insertion_Rules::remaining_budget(
        $novecento . str_repeat(' <a href="#" data-link-id="9">x</a>', 13),
        $regole
    ),
    'e con tredici ancore non va sotto zero'
);

alma_assert_same(
    1,
    ALMA_AI_Insertion_Rules::remaining_budget(alma_test_parole(50), $regole),
    'un articolo corto ha comunque il suo inserimento'
);

echo "\nPerché enforce() non si rilegge alla fine\n";

/*
 * Questa è la prova più importante del file, e nasce da un errore mio: la
 * correzione doveva «far girare enforce() alla fine», visto che la pipeline
 * inserisce link dopo averla eseguita. Scritta così, toglieva link buoni.
 *
 * `enforce()` non è idempotente. Il passo 3 degrada gli shortcode oltre la
 * densità; `shortcode_to_text()` restituisce la stringa vuota per tutto quel
 * che non è un'anchor con `text="…"` — cioè per le card, i bottoni e i
 * widget; i due shortcode che stavano ai lati di quello sparito diventano
 * adiacenti; e il passo 4 degrada il secondo di ogni coppia adiacente. Una
 * passata dopo l'altra, il contenuto perde un link per volta.
 *
 * Il tetto si rispetta a monte, col budget. Queste righe tengono ferma la
 * ragione, così chi riproverà la strada breve lo scopre in un secondo
 * invece che in produzione.
 */
$card = $novecento
    . ' [affiliate_link id="1" img="yes"] [affiliate_link id="2" img="yes"]'
    . ' [affiliate_link id="3" img="yes"] [affiliate_link id="4" img="yes"]';

$una = ALMA_AI_Insertion_Rules::enforce($card, $regole);
$due = ALMA_AI_Insertion_Rules::enforce($una['content'], $regole);

$dopo_una = ALMA_AI_Insertion_Rules::count_insertions($una['content']);
$dopo_due = ALMA_AI_Insertion_Rules::count_insertions($due['content']);

alma_assert_same(
    true,
    $dopo_due < $dopo_una,
    'rieseguire enforce() toglie un altro link: non è idempotente'
);

/*
 * Il budget, invece, si può interrogare quante volte si vuole: è una
 * domanda, non una modifica. È la proprietà su cui poggia la correzione.
 */
$contenuto = $novecento . ' [affiliate_link id="1" img="yes"]';

alma_assert_same(
    ALMA_AI_Insertion_Rules::remaining_budget($contenuto, $regole),
    ALMA_AI_Insertion_Rules::remaining_budget($contenuto, $regole),
    'chiedere il budget non cambia niente, per quante volte lo si chieda'
);

alma_assert_same(
    2,
    ALMA_AI_Insertion_Rules::remaining_budget($contenuto, $regole),
    'e dice il vero: uno messo su tre, ne restano due'
);

echo "\nLe regole strutturali\n";

$tre_widget = alma_test_parole(1200)
    . ' [affiliate_links_widget id="1"] testo in mezzo [affiliate_links_widget id="2"] altro testo [affiliate_links_widget id="3"]';
$ripulito = ALMA_AI_Insertion_Rules::enforce($tre_widget, $regole);

alma_assert_same(
    1,
    preg_match_all('/\[affiliate_links_widget[^\]]*\]/', $ripulito['content']),
    'resta un widget solo'
);

// Niente inserimenti nei primi paragrafi, quando la regola è accesa.
$con_paragrafi = array('min_paragraphs_before' => 2, 'density_words' => 300, 'patterns' => array('anchor'));
$in_testa = '<p>Primo [affiliate_link id="1" text="subito"] paragrafo.</p><p>Secondo.</p><p>' . alma_test_parole(400) . '</p>';
$spostato = ALMA_AI_Insertion_Rules::enforce($in_testa, $con_paragrafi);

alma_assert_same(
    false,
    strpos($spostato['content'], '[affiliate_link'),
    'lo shortcode nel primo paragrafo diventa testo'
);

alma_assert_same(
    true,
    false !== strpos($spostato['content'], 'subito'),
    'ma la frase resta leggibile: l’anchor conserva il suo testo'
);

echo "\nIl conteggio delle parole\n";

alma_assert_same(3, ALMA_AI_Insertion_Rules::count_words('Tre parole qui'), 'conta le parole');
alma_assert_same(2, ALMA_AI_Insertion_Rules::count_words('Città perché'), 'anche quelle accentate');
alma_assert_same(1, ALMA_AI_Insertion_Rules::count_words('dell’arte'), 'l’apostrofo tipografico non spezza la parola');

alma_test_summary();
