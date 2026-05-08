<?php
/**
 * External function: set_client_config
 * Admin mengubah konfigurasi client (mis. password keluar).
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

class set_client_config extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'admin_exit_password' => new external_value(PARAM_TEXT, 'New admin exit password'),
        ]);
    }

    public static function execute(string $adminExitPassword): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'admin_exit_password' => $adminExitPassword,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $pwd = trim((string) $params['admin_exit_password']);
        if ($pwd === '' || strlen($pwd) < 4) {
            return ['success' => false, 'message' => 'Password minimal 4 karakter.'];
        }
        if (strlen($pwd) > 64) {
            return ['success' => false, 'message' => 'Password terlalu panjang (maks 64).'];
        }

        set_config('admin_exit_password', $pwd, 'local_dosman_ujian');
        set_config('admin_exit_password_updated_at', time(), 'local_dosman_ujian');

        return ['success' => true, 'message' => 'Password keluar berhasil diperbarui.'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}

