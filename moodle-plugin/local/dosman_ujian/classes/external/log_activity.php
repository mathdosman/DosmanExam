<?php
/**
 * External function: log_activity
 * Digunakan oleh aplikasi mobile siswa untuk mengirim log aktivitas mencurigakan
 *
 * @package    local_dosman_ujian
 * @copyright  2024 dosman_ujian
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

class log_activity extends external_api {

    /**
     * Definisi parameter input
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'    => new external_value(PARAM_INT,  'ID of the student'),
            'quizid'    => new external_value(PARAM_INT,  'ID of the quiz'),
            'courseid'  => new external_value(PARAM_INT,  'ID of the course'),
            'eventtype' => new external_value(PARAM_TEXT, 'Type of event: app_background, screenshot_attempt, copy_attempt, focus_lost, exam_start, exam_end'),
            'eventdata' => new external_value(PARAM_RAW,  'Additional data in JSON format', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Eksekusi fungsi - simpan log ke database
     */
    public static function execute(int $userid, int $quizid, int $courseid, string $eventtype, string $eventdata = '{}'): array {
        global $DB, $USER;

        // Validasi parameter
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'    => $userid,
            'quizid'    => $quizid,
            'courseid'  => $courseid,
            'eventtype' => $eventtype,
            'eventdata' => $eventdata,
        ]);

        // Validasi konteks course
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Validasi user yang login harus sama dengan userid yang dikirim
        // (kecuali admin)
        if ($USER->id != $params['userid'] && !is_siteadmin()) {
            return ['success' => false, 'message' => 'Permission denied: cannot log for another user'];
        }

        // Validasi apakah quiz ada
        if (!$DB->record_exists('quiz', ['id' => $params['quizid']])) {
            return ['success' => false, 'message' => 'Invalid quiz ID'];
        }

        // Tentukan apakah event ini mencurigakan
        $suspiciousEvents = [
            'app_background',
            'screenshot_attempt',
            'copy_attempt',
            'focus_lost',
            'screen_record_attempt',
        ];
        $isSuspicious = in_array($params['eventtype'], $suspiciousEvents) ? 1 : 0;

        // Simpan log ke database
        $record = new \stdClass();
        $record->userid      = $params['userid'];
        $record->courseid    = $params['courseid'];
        $record->quizid      = $params['quizid'];
        $record->eventtype   = $params['eventtype'];
        $record->eventdata   = $params['eventdata'];
        $record->suspicious  = $isSuspicious;
        $record->timecreated = time();

        $insertedId = $DB->insert_record('local_dosman_ujian_logs', $record);

        if ($insertedId) {
            return [
                'success' => true,
                'message' => 'Activity logged successfully',
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to save log',
            ];
        }
    }

    /**
     * Definisi struktur output
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the log was saved successfully'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}
