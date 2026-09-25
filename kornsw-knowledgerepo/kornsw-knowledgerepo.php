<?php
/**
 * Plugin Name: KornSW KnowledgeRepo
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-KnowledgeRepo/master/doc/kornsw-knowledgerepo.update.json
 * Plugin URI: https://github.com/KornSW/WP-KnowledgeRepo
 * Description: Provider-neutrales Wissensrepository mit Wiki, Joplin-WebDAV und UJMW.
 * Version: 1.0.4
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: KornSW
 * License: GPL-2.0-or-later
 * Text Domain: kornsw-knowledgerepo
 */
if (!defined('ABSPATH')) { exit; }

/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWKNOWLE944F_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswknowle944f_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_KR_FILE', __FILE__);
$requiredClasses = ['Contract', 'FileCache', 'Http', 'WordPressRepository', 'GitHubRepository', 'Aggregator', 'Auth', 'Joplin', 'Admin', 'SearchSession', 'ConfiguredRepository', 'WikiPresentation', 'OpenApi', 'Raw', 'Plugin'];
$missingFiles = [];
foreach ($requiredClasses as $class) {
    $file = __DIR__ . '/includes/' . $class . '.php';
    if (!is_readable($file)) { $missingFiles[] = 'includes/' . $class . '.php'; }
}
if ($missingFiles) {
    error_log('KornSW KnowledgeRepo installation incomplete. Missing: ' . implode(', ', $missingFiles));
    add_action('admin_notices', static function () use ($missingFiles) {
        if (!current_user_can('activate_plugins')) { return; }
        echo '<div class="notice notice-error"><p><strong>KornSW KnowledgeRepo:</strong> Die Installation ist unvollständig. Bitte das vollständige Plugin-Paket erneut installieren. Fehlend: <code>' . esc_html(implode(', ', $missingFiles)) . '</code></p></div>';
    });
    return;
}
foreach ($requiredClasses as $class) {
    require_once __DIR__ . '/includes/' . $class . '.php';
}
register_activation_hook(__FILE__, [\KornSW\KnowledgeRepo\Plugin::class, 'activate']);
add_action('plugins_loaded', [\KornSW\KnowledgeRepo\Plugin::class, 'boot']);
