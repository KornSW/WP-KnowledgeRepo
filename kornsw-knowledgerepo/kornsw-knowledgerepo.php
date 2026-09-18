<?php
/**
 * Plugin Name: KornSW KnowledgeRepo
 * Description: Provider-neutrales Wissensrepository mit Wiki, Joplin-WebDAV und UJMW.
 * Version: 0.1.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: KornSW
 * License: GPL-2.0-or-later
 * Text Domain: kornsw-knowledgerepo
 */
if (!defined('ABSPATH')) { exit; }
define('KORNSW_KR_FILE', __FILE__);
foreach (['Contract', 'FileCache', 'Http', 'WordPressRepository', 'GitHubRepository', 'Aggregator', 'Auth', 'Joplin', 'Admin', 'Plugin'] as $class) {
    require_once __DIR__ . '/includes/' . $class . '.php';
}
register_activation_hook(__FILE__, ['KornSW\\KnowledgeRepo\\Plugin', 'activate']);
add_action('plugins_loaded', ['KornSW\\KnowledgeRepo\\Plugin', 'boot']);
