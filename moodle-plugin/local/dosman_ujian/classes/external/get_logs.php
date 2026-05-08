<?php
/**
 * External function: get_logs
 * Ambil log aktivitas siswa - hanya untuk guru/admin
 *
 * @package    local_dosman_ujian
 * @copyright  2024 dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_dosman_ujian\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/dosman_ujian/lib.php');

use external_api;
use external_value;
use external_single_structure;
use external_multiple_structure;
use external_function_parameters;
use context_course;

class get_logs extends external_api {

    /**
     * Definisi parameter input
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid'        => new external_value(PARAM_INT, 'ID of the quiz'),
            'courseid'      => new external_value(PARAM_INT, 'ID of the course'),
            'userid'        => new external_value(PARAM_INT, 'Filter by student ID (0 = all students)', VALUE_DEFAULT, 0),
            'suspicious_only' => new external_value(PARAM_BOOL, 'Only return suspicious events', VALUE_DEFAULT, false),
            'limit'         => new external_value(PARAM_INT, 'Max number of records to return', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * Eksekusi fungsi - ambil log dari database
     */
    public static function execute(int $quizid, int $courseid, int $userid = 0, bool $suspiciousOnly = false, int $limit = 100): array {
        global $DB;

        // Validasi parameter
        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid'          => $quizid,
            'courseid'        => $courseid,
            'userid'          => $userid,
            'suspicious_only' => $suspiciousOnly,
            'limit'           => $limit,
        ]);

        // Validasi konteks - hanya guru/admin yang bisa akses
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        if (!local_dosman_ujian_user_can_monitor_course($context)) {
            require_capability('mod/quiz:viewreports', $context);
        }

        // Bangun query
        $sql = 'SELECT l.id, l.userid, l.courseid, l.quizid, l.eventtype,
                       l.eventdata, l.suspicious, l.timecreated,
                       u.firstname, u.lastname, u.email,
                       u.department, u.institution
                  FROM {local_dosman_ujian_logs} l
                  JOIN {user} u ON u.id = l.userid
                 WHERE l.quizid = :quizid
                   AND l.courseid = :courseid';

        $sqlParams = [
            'quizid'   => $params['quizid'],
            'courseid' => $params['courseid'],
        ];

        // Filter per siswa jika ada
        if ($params['userid'] > 0) {
            $sql .= ' AND l.userid = :userid';
            $sqlParams['userid'] = $params['userid'];
        }

        // Filter hanya yang mencurigakan
        if ($params['suspicious_only']) {
            $sql .= ' AND l.suspicious = 1';
        }

        $sql .= ' ORDER BY l.timecreated DESC';

        $records = $DB->get_records_sql($sql, $sqlParams, 0, $params['limit']);

        if (empty($records)) {
            $fallbackSql = str_replace('AND l.courseid = :courseid', '', $sql);
            $fallbackParams = $sqlParams;
            unset($fallbackParams['courseid']);
            $records = $DB->get_records_sql($fallbackSql, $fallbackParams);
        }

        // Format hasil
        $logs = [];
        foreach ($records as $record) {
            $logs[] = [
                'id'          => (int) $record->id,
                'userid'      => (int) $record->userid,
                'firstname'   => $record->firstname,
                'lastname'    => $record->lastname,
                'email'       => $record->email,
                'department'  => $record->department ?? '',
                'institution' => $record->institution ?? '',
                'quizid'      => (int) $record->quizid,
                'courseid'    => (int) $record->courseid,
                'eventtype'   => $record->eventtype,
                'eventdata'   => $record->eventdata ?? '{}',
                'suspicious'  => (bool) $record->suspicious,
                'timecreated' => (int) $record->timecreated,
            ];
        }

        // Hitung statistik ringkasan per siswa
        $summarySql = 'SELECT l.userid, u.firstname, u.lastname,
                               COUNT(*) as total_events,
                               SUM(l.suspicious) as suspicious_count
                          FROM {local_dosman_ujian_logs} l
                          JOIN {user} u ON u.id = l.userid
                         WHERE l.quizid = :quizid
                           AND l.courseid = :courseid
                      GROUP BY l.userid, u.firstname, u.lastname
                      ORDER BY suspicious_count DESC';

        $summaryRecords = $DB->get_records_sql($summarySql, [
            'quizid'   => $params['quizid'],
            'courseid' => $params['courseid'],
        ]);

        if (empty($summaryRecords)) {
            $fallbackSummarySql = str_replace('AND l.courseid = :courseid', '', $summarySql);
            $summaryRecords = $DB->get_records_sql($fallbackSummarySql, ['quizid' => $params['quizid']]);
        }

        $summary = [];
        foreach ($summaryRecords as $s) {
            $summary[] = [
                'userid'          => (int) $s->userid,
                'firstname'       => $s->firstname,
                'lastname'        => $s->lastname,
                'total_events'    => (int) $s->total_events,
                'suspicious_count' => (int) $s->suspicious_count,
            ];
        }

        return [
            'logs'         => $logs,
            'summary'      => $summary,
            'total_records' => count($logs),
        ];
    }

    /**
     * Definisi struktur output
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logs' => new external_multiple_structure(
                new external_single_structure([
                    'id'          => new external_value(PARAM_INT,  'Log ID'),
                    'userid'      => new external_value(PARAM_INT,  'Student user ID'),
                    'firstname'   => new external_value(PARAM_TEXT, 'Student first name'),
                    'lastname'    => new external_value(PARAM_TEXT, 'Student last name'),
                    'email'       => new external_value(PARAM_EMAIL,'Student email'),
                    'department'  => new external_value(PARAM_TEXT, 'User department', VALUE_DEFAULT, ''),
                    'institution' => new external_value(PARAM_TEXT, 'User institution', VALUE_DEFAULT, ''),
                    'quizid'      => new external_value(PARAM_INT,  'Quiz ID'),
                    'courseid'    => new external_value(PARAM_INT,  'Course ID'),
                    'eventtype'   => new external_value(PARAM_TEXT, 'Event type'),
                    'eventdata'   => new external_value(PARAM_RAW,  'Event data JSON'),
                    'suspicious'  => new external_value(PARAM_BOOL, 'Is suspicious'),
                    'timecreated' => new external_value(PARAM_INT,  'Unix timestamp'),
                ])
            ),
            'summary' => new external_multiple_structure(
                new external_single_structure([
                    'userid'           => new external_value(PARAM_INT,  'Student user ID'),
                    'firstname'        => new external_value(PARAM_TEXT, 'Student first name'),
                    'lastname'         => new external_value(PARAM_TEXT, 'Student last name'),
                    'total_events'     => new external_value(PARAM_INT,  'Total events logged'),
                    'suspicious_count' => new external_value(PARAM_INT,  'Number of suspicious events'),
                ])
            ),
            'total_records' => new external_value(PARAM_INT, 'Total number of log records returned'),
        ]);
    }
}
