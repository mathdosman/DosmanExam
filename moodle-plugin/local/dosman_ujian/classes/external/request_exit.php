<?php
/**
 * External function: request_exit
 * Siswa minta izin keluar dari aplikasi ujian; guru menyetujui lewat dashboard.
 *
 * @package    local_dosman_ujian
 * @copyright  2024 Dosman Ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_dosman_ujian\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_value;
use external_single_structure;
use external_function_parameters;
use context_course;

class request_exit extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'session_id' => new external_value(PARAM_INT, 'Session ID'),
            'userid'     => new external_value(PARAM_INT, 'Student user ID'),
        ]);
    }

    public static function execute(int $sessionId, int $userid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'session_id' => $sessionId,
            'userid'     => $userid,
        ]);

        if ($USER->id != $params['userid'] && !is_siteadmin()) {
            return ['success' => false, 'message' => 'Permission denied'];
        }

        $session = $DB->get_record('local_dosman_ujian_sessions', [
            'id'     => $params['session_id'],
            'userid' => $params['userid'],
        ]);

        if (!$session) {
            return ['success' => false, 'message' => 'Sesi tidak ditemukan'];
        }

        if ($session->status === 'blocked') {
            return ['success' => false, 'message' => 'Sesi diblokir'];
        }

        if ($session->status === 'released') {
            return ['success' => false, 'message' => 'Sesi sudah berakhir'];
        }

        if ($session->status !== 'active') {
            return ['success' => false, 'message' => 'Hanya sesi aktif yang dapat meminta izin keluar'];
        }

        $pending = ($session->exit_request ?? 'none') === 'pending';
        if ($pending) {
            return ['success' => true, 'message' => 'Permintaan sudah menunggu persetujuan guru'];
        }

        $now = time();
        $DB->update_record('local_dosman_ujian_sessions', (object)[
            'id'                => $session->id,
            'exit_request'      => 'pending',
            'exit_requested_at' => $now,
            'timemodified'      => $now,
        ]);

        $DB->insert_record('local_dosman_ujian_logs', (object)[
            'userid'      => $session->userid,
            'courseid'    => $session->courseid,
            'quizid'      => $session->quizid,
            'eventtype'   => 'exit_requested',
            'eventdata'   => json_encode(['session_id' => $session->id]),
            'suspicious'  => 0,
            'timecreated' => $now,
        ]);

        return ['success' => true, 'message' => 'Permintaan izin keluar dikirim ke guru'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
