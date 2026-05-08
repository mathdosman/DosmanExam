<?php
/**
 * External function: get_client_config
 * Konfigurasi client untuk aplikasi siswa (mis. password keluar).
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_dosman_ujian\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_value;
use external_single_structure;
use external_function_parameters;
use context_system;

class get_client_config extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);

        // Izinkan admin (atau role yang punya permission site config) mengambil config.
        require_capability('moodle/site:config', $context);

        $pwd = (string) get_config('local_dosman_ujian', 'admin_exit_password');
        $updated = (int) (get_config('local_dosman_ujian', 'admin_exit_password_updated_at') ?: 0);

        return [
            'success' => true,
            'admin_exit_password' => $pwd,
            'updated_at' => $updated,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'admin_exit_password' => new external_value(PARAM_TEXT, 'Admin exit password', VALUE_DEFAULT, ''),
            'updated_at' => new external_value(PARAM_INT, 'Updated at timestamp', VALUE_DEFAULT, 0),
        ]);
    }
}

