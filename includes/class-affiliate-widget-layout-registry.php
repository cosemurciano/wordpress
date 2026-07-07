<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registro dei layout dei Widget Link.
 *
 * Dalla 2.82.0 i layout proposti sono tre famiglie di card in stile catalogo
 * di esperienze di viaggio (ispirate a GetYourGuide, senza loghi):
 * - destination_cards: immagine a tutta card, titolo e località in
 *   sovraimpressione (griglia su desktop, elenco impilato su mobile);
 * - experience_cards: card compatte con immagine, località, titolo e
 *   pulsante (griglia su desktop, carosello scorrevole su mobile);
 * - hero_spotlight: un solo link di punta, testo+pulsante accanto a una
 *   grande immagine (impilati su mobile).
 *
 * I vecchi preset a colonne (columns_1..6) restano VALIDI per i widget già
 * salvati (retrocompatibilità piena: continuano a renderizzare come prima)
 * ma non sono più proponibili nella creazione.
 */
class ALMA_Affiliate_Widget_Layout_Registry {
    public static function get_default_preset() {
        return 'destination_cards';
    }

    /**
     * Preset proponibili nella UI e all'Agente AI.
     */
    public static function get_selectable_presets() {
        return array(
            'destination_cards' => array(
                'label'       => __('Card destinazione', 'affiliate-link-manager-ai'),
                'description' => __('Immagine a tutta card con titolo e località in sovraimpressione: perfetta per mete e luoghi. Griglia su desktop, elenco impilato su mobile.', 'affiliate-link-manager-ai'),
                'image'       => 'widget-layout-destination-cards.svg',
                'desktop'     => 3,
                'mobile'      => 1,
                'style'       => 'destination_cards',
                'min_links'   => 2,
                'max_links'   => 6,
                'badge'       => __('3 per riga · impilate su mobile', 'affiliate-link-manager-ai'),
                'ai_hint'     => __('griglia di mete/destinazioni (2-6 link)', 'affiliate-link-manager-ai'),
            ),
            'experience_cards' => array(
                'label'       => __('Card esperienza', 'affiliate-link-manager-ai'),
                'description' => __('Card compatte con immagine, località, titolo e pulsante: ideali per tour e attività specifiche. Su mobile scorrono in orizzontale (carosello).', 'affiliate-link-manager-ai'),
                'image'       => 'widget-layout-experience-cards.svg',
                'desktop'     => 4,
                'mobile'      => 1,
                'style'       => 'experience_cards',
                'min_links'   => 2,
                'max_links'   => 8,
                'badge'       => __('4 per riga · carosello su mobile', 'affiliate-link-manager-ai'),
                'ai_hint'     => __('tour e attività specifiche (2-8 link, carosello su mobile)', 'affiliate-link-manager-ai'),
            ),
            'hero_spotlight' => array(
                'label'       => __('Vetrina in evidenza', 'affiliate-link-manager-ai'),
                'description' => __('Un solo link di punta: titolo, descrizione e pulsante accanto a una grande immagine (immagine sopra al testo su mobile).', 'affiliate-link-manager-ai'),
                'image'       => 'widget-layout-hero-spotlight.svg',
                'desktop'     => 1,
                'mobile'      => 1,
                'style'       => 'hero_spotlight',
                'min_links'   => 1,
                'max_links'   => 1,
                'badge'       => __('1 link in evidenza', 'affiliate-link-manager-ai'),
                'ai_hint'     => __('UNA sola esperienza di punta da mettere in risalto (1 link)', 'affiliate-link-manager-ai'),
            ),
        );
    }

    /**
     * Preset legacy a colonne: non più proponibili, ma i widget salvati con
     * questi layout continuano a funzionare identici.
     */
    public static function get_legacy_presets() {
        $presets = array();
        for ($i = 1; $i <= 6; $i++) {
            $mobile = $i >= 4 ? 2 : 1;
            $presets['columns_' . $i] = array(
                'label'       => sprintf(_n('Layout %d colonna', 'Layout %d colonne', $i, 'affiliate-link-manager-ai'), $i),
                'description' => sprintf(_n('Griglia responsive con %d colonna su desktop e massimo %d su mobile.', 'Griglia responsive con %d colonne su desktop e massimo %d su mobile.', $i, 'affiliate-link-manager-ai'), $i, $mobile),
                'image'       => 'widget-layout-columns-' . $i . '.svg',
                'desktop'     => $i,
                'mobile'      => $mobile,
                'style'       => 'grid',
                'min_links'   => 1,
                'max_links'   => 20,
                'legacy'      => true,
            );
        }

        return $presets;
    }

    /**
     * Tutti i preset validi (nuovi + legacy).
     */
    public static function get_presets() {
        return array_merge(self::get_selectable_presets(), self::get_legacy_presets());
    }

    public static function is_valid_preset($preset) {
        $preset = sanitize_key($preset);
        $presets = self::get_presets();

        return isset($presets[$preset]);
    }

    /**
     * Il preset usa il nuovo rendering a card (non la griglia legacy)?
     */
    public static function is_card_preset($preset) {
        $preset = sanitize_key($preset);
        $presets = self::get_presets();

        return isset($presets[$preset]) && ($presets[$preset]['style'] ?? 'grid') !== 'grid';
    }

    public static function sanitize_preset($preset) {
        $preset = sanitize_key($preset);

        return self::is_valid_preset($preset) ? $preset : self::get_default_preset();
    }

    public static function get_preset($preset) {
        $preset = self::sanitize_preset($preset);
        $presets = self::get_presets();

        return $presets[$preset];
    }

    public static function infer_preset($instance) {
        $saved = sanitize_key($instance['layout_preset'] ?? '');
        if (self::is_valid_preset($saved)) {
            return $saved;
        }

        $desktop = isset($instance['template_desktop_columns']) ? absint($instance['template_desktop_columns']) : 0;
        if ($desktop < 1) {
            if (!empty($instance['format']) && $instance['format'] === 'small') {
                $desktop = 2;
            } elseif (!empty($instance['orientation']) && $instance['orientation'] === 'horizontal') {
                $desktop = 2;
            } else {
                $desktop = 1;
            }
        }

        $desktop = max(1, min(6, $desktop));

        return 'columns_' . $desktop;
    }
}
