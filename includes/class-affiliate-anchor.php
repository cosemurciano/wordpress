<?php
/**
 * L'ancora di un link affiliato: un posto solo che decide `rel` e tracciamento.
 *
 * Questa classe nasce da un difetto, e vale la pena scrivere quale perché
 * spiega la sua forma.
 *
 * La regola «se non è stato scelto un rel, metti quello da link sponsorizzato»
 * era scritta in quattro punti diversi — shortcode, widget elenco (due volte)
 * e widget contestuale — e solo l'ultimo la applicava davvero. Gli altri tre
 * facevano così:
 *
 *     $rel = get_post_meta($id, '_link_rel', true);
 *     if ($rel === '') {
 *         // Link interno: nessun attributo rel
 *     } elseif (!$rel) {
 *         $rel = 'sponsored noopener';
 *     }
 *
 * `get_post_meta()` con `$single = true` restituisce la stringa vuota in **due**
 * casi che non sono la stessa cosa: il campo c'è ed è vuoto (una scelta: è un
 * link interno, niente rel), e il campo non c'è affatto. Il secondo caso è
 * quello di ogni link arrivato dagli importer dei provider, che `_link_rel`
 * non lo scrivono mai — cioè il grosso del catalogo. Quei link uscivano con
 * **nessun rel**: niente `sponsored`, niente `noopener`, e un `target="_blank"`
 * scoperto. Un link pagato presentato a Google come link editoriale.
 *
 * Il ramo `elseif (!$rel)` era raggiungibile solo dalla stringa «0».
 *
 * Il modo giusto esisteva già, nel widget contestuale: `metadata_exists()`
 * distingue «scelto vuoto» da «mai scritto». Qui quella distinzione sta una
 * volta sola, e i quattro punti la chiamano.
 *
 * Il tracciamento ha la stessa storia. `assets/tracking.js` si aggancia a
 * `.alma-affiliate-link[data-track="1"]`: gli attributi senza la classe non
 * contano un bel niente, ed era il caso dei layout a scheda del widget (il
 * preset predefinito, e l'unico che l'agente AI usa) e delle ancore che la
 * pipeline AI aggiunge dopo la scrittura. Click mai registrati, su superfici
 * che nel frattempo i consigli dell'AI consideravano «poco cliccate».
 *
 * @package AffiliateLinkManagerAI
 */

if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Anchor {

    /**
     * La classe a cui si aggancia il tracciamento dei click.
     *
     * Scritta qui e non a mano nei template: è il punto di contatto con
     * `assets/tracking.js`, e un refuso in uno dei sette posti che la
     * stampano non si vede — semplicemente, quei click non arrivano.
     */
    const TRACK_CLASS = 'alma-affiliate-link';

    /**
     * Il `rel` di partenza per un link affiliato.
     *
     * `sponsored` è il valore che Google indica per i link pagati;
     * `noopener` chiude il buco del `target="_blank"`. Non si aggiunge
     * `nofollow`: con `sponsored` presente non serve, e chi lo vuole lo
     * scrive nel campo del link o passa dal filtro.
     *
     * @return string
     */
    public static function default_rel() {
        /**
         * Filtra il `rel` usato quando il link non ne ha uno suo.
         *
         * @param string $rel Valore di partenza.
         */
        return (string) apply_filters('alma_affiliate_default_rel', 'sponsored noopener');
    }

    /**
     * Il `rel` da stampare per questo link.
     *
     * @param int $link_id ID del Link Affiliato.
     * @return string Stringa vuota quando il link è stato dichiarato interno.
     */
    public static function rel($link_id) {
        $link_id = (int) $link_id;

        if ($link_id < 1) {
            return self::default_rel();
        }

        $rel = get_post_meta($link_id, '_link_rel', true);

        // Il campo c'è ed è vuoto: è una scelta di chi ha compilato la
        // scheda — link interno, nessun rel — e si rispetta.
        if ($rel === '' && metadata_exists('post', $link_id, '_link_rel')) {
            return '';
        }

        // Il campo non c'è (ogni link importato) o vale qualcosa di falso:
        // vale il valore di partenza.
        if (!$rel) {
            return self::default_rel();
        }

        return (string) $rel;
    }

    /**
     * Il `target` da stampare per questo link.
     *
     * @param int $link_id ID del Link Affiliato.
     * @return string
     */
    public static function target($link_id) {
        $target = get_post_meta((int) $link_id, '_link_target', true);

        return $target ? (string) $target : '_blank';
    }

    /**
     * Gli attributi di tracciamento, classe compresa.
     *
     * Restituisce una stringa che comincia con uno spazio, pronta da
     * concatenare dentro un tag: `<a' . self::track_attrs(...) . '>`.
     *
     * @param int    $link_id     ID del Link Affiliato.
     * @param string $source      Da dove arriva il click (shortcode, widget, …).
     * @param string $extra_class Classi del template, se ne ha.
     * @return string
     */
    public static function track_attrs($link_id, $source = 'shortcode', $extra_class = '') {
        $link_id = (int) $link_id;
        $source  = sanitize_key($source);

        if ($source === '') {
            $source = 'shortcode';
        }

        $classi = trim($extra_class . ' ' . self::TRACK_CLASS);

        return ' class="' . esc_attr($classi) . '"'
            . ' data-link-id="' . esc_attr($link_id) . '"'
            . ' data-track="1"'
            . ' data-source="' . esc_attr($source) . '"';
    }

    /**
     * L'attributo `rel`, già scritto, o niente quando il link è interno.
     *
     * @param int $link_id ID del Link Affiliato.
     * @return string
     */
    public static function rel_attr($link_id) {
        $rel = self::rel($link_id);

        return $rel === '' ? '' : ' rel="' . esc_attr($rel) . '"';
    }
}
