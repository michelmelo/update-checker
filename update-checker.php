<?php
/*
 * Plugin Name: Update Checker
 * Plugin URI: https://michelmelo.pt/update-checker
 * Description: Gets updates from a custom server.
 * Version: 1.0.0
 * Author: Michel Melo
 * Author URI: https://michelmelo.pt
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: update-checker
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

if (!class_exists('mmUpdateChecker')) {
    class mmUpdateChecker
    {
        private $plugin_slug;
        private $version;
        private $cache_key;
        private $cache_allowed;
        private $cache_expiration;
        private $update_url;

        public function __construct()
        {
            // Definição de variáveis para fácil modificação
            $this->plugin_slug      = plugin_basename(__DIR__);
            $this->version          = '1.0.0'; // Versão do plugin
            $this->cache_key        = 'custom_upd_3'; // Chave para armazenamento do cache
            $this->cache_allowed    = false; // Ativar/desativar cache
            $this->cache_expiration = 1; // Tempo do cache (1 dia)
            $this->update_url       = 'https://raw.githubusercontent.com/michelmelo/update-checker/main/info.json'; // URL do JSON de atualização

            add_filter('plugins_api', [$this, 'info'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'update']);
            add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
        }

        private function get_remote_data()
        {
            if ($this->cache_allowed) {
                $cached_data = get_transient($this->cache_key);
                if ($cached_data) {
                    return json_decode($cached_data);
                }
            }

            $response = wp_remote_get($this->update_url, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                return false;
            }

            set_transient($this->cache_key, $body, $this->cache_expiration);
            return json_decode($body);
        }

        public function info($res, $action, $args)
        {
            if ($action !== 'plugin_information' || $this->plugin_slug !== $args->slug) {
                return $res;
            }

            $remote = $this->get_remote_data();
            if (!$remote) {
                return $res;
            }

            $res = (object) [
                'name'           => $remote->name ?? '',
                'slug'           => $remote->slug ?? '',
                'version'        => $remote->version ?? '',
                'tested'         => $remote->tested ?? '',
                'requires'       => $remote->requires ?? '',
                'author'         => $remote->author ?? '',
                'author_profile' => $remote->author_profile ?? '',
                'download_link'  => $remote->download_url ?? '',
                'trunk'          => $remote->download_url ?? '',
                'requires_php'   => $remote->requires_php ?? '',
                'last_updated'   => $remote->last_updated ?? '',
                'sections'       => (array) ($remote->sections ?? []),
                'banners'        => isset($remote->banners) ? (array) $remote->banners : [],
            ];

            return $res;
        }

        public function update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->get_remote_data();
            if (!$remote) {
                return $transient;
            }

            if (
                version_compare($this->version, $remote->version, '<') &&
                version_compare(get_bloginfo('version'), $remote->requires, '>=') &&
                version_compare(PHP_VERSION, $remote->requires_php, '>=')
            ) {
                $res = (object) [
                    'slug'        => $this->plugin_slug,
                    'plugin'      => plugin_basename(__FILE__),
                    'new_version' => $remote->version,
                    'tested'      => $remote->tested,
                    'package'     => $remote->download_url,
                ];

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        public function purge($upgrader, $options)
        {
            if ($this->cache_allowed && $options['action'] === 'update' && $options['type'] === 'plugin') {
                delete_transient($this->cache_key);
            }
        }
    }

    new mmUpdateChecker();
}
