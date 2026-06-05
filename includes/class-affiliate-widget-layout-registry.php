<?php
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Widget_Layout_Registry {
    public static function get_default_preset() {
        return 'columns_3';
    }

    public static function get_presets() {
        $presets = array();
        for ($i = 1; $i <= 6; $i++) {
            $mobile = $i >= 4 ? 2 : 1;
            $presets['columns_' . $i] = array(
                'label'       => sprintf(_n('Layout %d colonna', 'Layout %d colonne', $i, 'affiliate-link-manager-ai'), $i),
                'description' => sprintf(_n('Griglia responsive con %d colonna su desktop e massimo %d su mobile.', 'Griglia responsive con %d colonne su desktop e massimo %d su mobile.', $i, 'affiliate-link-manager-ai'), $i, $mobile),
                'image'       => 'widget-layout-columns-' . $i . '.svg',
                'desktop'     => $i,
                'mobile'      => $mobile,
            );
        }

        return $presets;
    }

    public static function is_valid_preset($preset) {
        $preset = sanitize_key($preset);
        $presets = self::get_presets();

        return isset($presets[$preset]);
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
