<?php
// ============================================================================
// KutPod · Plugin de Barra Lateral de Episodios
// ============================================================================

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

/**
 * Devuelve la configuración del plugin con valores por defecto garantizados.
 */
function kp_episode_sidebar_get_config(): array {
    $defaults = [
        'mode'                  => 'no_chapters', // 'no_chapters' | 'always'
        'widgets_order'         => [
            'platforms',
            'navigation',
            'persons',
            'meta',
            'support',
            'fediverse_likes',
            'embed',
        ],
        'show_platforms'        => true,
        'title_platforms'       => 'Escúchalo en',
        'show_rss'              => true,
        'show_navigation'       => true,
        'title_navigation'      => 'Navegación',
        'show_persons'          => true,
        'title_persons'         => 'Participantes',
        'show_meta'             => true,
        'title_meta'            => 'Detalles del episodio',
        'show_download'         => true,
        'show_transcript'       => true,
        'show_support'          => true,
        'title_support'         => 'Apoyo y Comunidad',
        'show_fediverse_likes'  => true,
        'title_fediverse_likes' => 'Me gusta en el Fediverso',
        'show_embed'            => true,
        'title_embed'           => 'Incrustar reproductor',
    ];

    $raw = kp_setting('episode_sidebar_config', null);
    if ($raw === null) {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $merged = array_merge($defaults, $decoded);

    // Asegurar que widgets_order contenga todos los widgets conocidos si falta alguno
    if (empty($merged['widgets_order']) || !is_array($merged['widgets_order'])) {
        $merged['widgets_order'] = $defaults['widgets_order'];
    } else {
        foreach ($defaults['widgets_order'] as $wk) {
            if (!in_array($wk, $merged['widgets_order'], true)) {
                $merged['widgets_order'][] = $wk;
            }
        }
    }

    return $merged;
}
