<?php
/**
 * L'ancora dei link affiliati: `rel` e tracciamento.
 *
 * Queste prove sorvegliano il difetto per cui ogni link importato usciva
 * senza nessun `rel` — niente `sponsored`, niente `noopener` — perche' tre
 * copie su quattro della stessa regola scambiavano «campo mai scritto» per
 * «link interno, nessun rel di proposito».
 *
 * Si esegue con: php tests/test-affiliate-anchor.php
 *
 * @package AffiliateLinkManagerAI
 */

if ('cli' !== PHP_SAPI) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/class-affiliate-anchor.php';

echo "\nIl rel\n";

/* --- Il caso che si era rotto ------------------------------------------ */

alma_test_reset_meta();

// Un link appena importato: nessun importer di provider scrive `_link_rel`,
// quindi la chiave non esiste proprio. E' il grosso del catalogo.
alma_assert_same(
    'sponsored noopener',
    ALMA_Affiliate_Anchor::rel(101),
    'un link importato, senza il campo, prende il rel da sponsorizzato'
);

alma_assert_same(
    ' rel="sponsored noopener"',
    ALMA_Affiliate_Anchor::rel_attr(101),
    'e l’attributo esce scritto per intero'
);

/* --- Il caso che doveva continuare a funzionare ------------------------ */

$GLOBALS['alma_test_meta'][202] = array('_link_rel' => '');

alma_assert_same(
    '',
    ALMA_Affiliate_Anchor::rel(202),
    'un campo scelto vuoto resta vuoto: e’ un link interno, ed e’ una scelta'
);

alma_assert_same(
    '',
    ALMA_Affiliate_Anchor::rel_attr(202),
    'e non stampa nessun attributo'
);

/* --- Il valore scritto a mano vince ------------------------------------ */

$GLOBALS['alma_test_meta'][303] = array('_link_rel' => 'nofollow');

alma_assert_same(
    'nofollow',
    ALMA_Affiliate_Anchor::rel(303),
    'un rel scritto a mano si rispetta'
);

/*
 * La stringa «0» era l'unico valore che raggiungeva il vecchio ramo
 * `elseif (!$rel)`. Oggi e' un valore come un altro: il campo c'e', quindi
 * e' una scelta. La riga tiene fermo che non si torni indietro.
 */
$GLOBALS['alma_test_meta'][404] = array('_link_rel' => '0');

alma_assert_same(
    'sponsored noopener',
    ALMA_Affiliate_Anchor::rel(404),
    'la stringa «0» non e’ un rel: vale quello di partenza'
);

/* --- Un id che non esiste non deve mai dare un link scoperto ----------- */

alma_assert_same(
    'sponsored noopener',
    ALMA_Affiliate_Anchor::rel(0),
    'senza un id valido si sta dalla parte sicura'
);

/* --- Il filtro --------------------------------------------------------- */

$GLOBALS['alma_test_filters']['alma_affiliate_default_rel'] = function () {
    return 'sponsored nofollow noopener';
};

alma_assert_same(
    'sponsored nofollow noopener',
    ALMA_Affiliate_Anchor::rel(101),
    'chi vuole anche nofollow lo aggiunge dal filtro'
);

unset($GLOBALS['alma_test_filters']['alma_affiliate_default_rel']);

alma_assert_same(
    'sponsored noopener',
    ALMA_Affiliate_Anchor::rel(101),
    'e togliendolo si torna al valore di partenza'
);

echo "\nIl target\n";

alma_test_reset_meta();

alma_assert_same('_blank', ALMA_Affiliate_Anchor::target(101), 'senza campo si apre in una scheda nuova');

$GLOBALS['alma_test_meta'][505] = array('_link_target' => '_self');

alma_assert_same('_self', ALMA_Affiliate_Anchor::target(505), 'il target scelto si rispetta');

echo "\nGli attributi del tracciamento\n";

$attrs = ALMA_Affiliate_Anchor::track_attrs(77, 'widget', 'alma-wgt-dest');

/*
 * Il contratto con `assets/tracking.js`, che si aggancia a
 * `.alma-affiliate-link[data-track="1"]` e legge `data-link-id`. Le tre
 * righe qui sotto sono la ragione per cui i click delle schede, del
 * contenuto generato e delle ancore riparate dal QA non arrivavano.
 */
alma_assert_same(true, false !== strpos($attrs, 'alma-affiliate-link'), 'c’e’ la classe a cui il tracciamento si aggancia');
alma_assert_same(true, false !== strpos($attrs, 'data-track="1"'), 'c’e’ l’interruttore del tracciamento');
alma_assert_same(true, false !== strpos($attrs, 'data-link-id="77"'), 'c’e’ l’identificativo del link');
alma_assert_same(true, false !== strpos($attrs, 'data-source="widget"'), 'e da dove arriva il click');

/*
 * Una sola `class=`: la scheda passa la propria e qui si fondono. Due
 * attributi `class` nello stesso tag non sono un dettaglio estetico — il
 * browser tiene il primo e butta il secondo, cioe' proprio quello del
 * tracciamento.
 */
alma_assert_same(1, substr_count($attrs, 'class='), 'un solo attributo class, non due');
alma_assert_same(true, false !== strpos($attrs, 'alma-wgt-dest alma-affiliate-link'), 'con dentro sia la classe della scheda sia la nostra');

$senza = ALMA_Affiliate_Anchor::track_attrs(77, 'shortcode');

alma_assert_same(true, false !== strpos($senza, 'class="alma-affiliate-link"'), 'senza classe del template resta solo la nostra');
alma_assert_same(false, strpos($senza, '  '), 'e niente spazi doppi nell’attributo');

// La provenienza arriva dall'esterno: va ripulita come una chiave.
alma_assert_same(
    true,
    false !== strpos(ALMA_Affiliate_Anchor::track_attrs(1, 'Agent Content!'), 'data-source="agentcontent"'),
    'la provenienza si ripulisce'
);

alma_assert_same(
    true,
    false !== strpos(ALMA_Affiliate_Anchor::track_attrs(1, ''), 'data-source="shortcode"'),
    'e vuota ricade su «shortcode»'
);

alma_test_summary();
