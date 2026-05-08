<?php
/**
 * External function: get_exam_status
 * Cek apakah siswa sedang dalam ujian aktif
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
use context_module;

class get_exam_status extends external_api {

    /**
     * Definisi parameter input
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'ID of the student'),
            'quizid' => new external_value(PARAM_INT, 'ID of the quiz'),
        ]);
    }

    /**
     * Eksekusi fungsi - cek status ujian aktif siswa
     */
    public static function execute(int $userid, int $quizid): array {
        global $DB, $USER;

        // Validasi parameter
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'quizid' => $quizid,
        ]);

        // Ambil data quiz
        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);

        // Ambil course module untuk validasi konteks
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Cari attempt yang sedang berjalan (inprogress)
        $attempt = $DB->get_record_sql(
            'SELECT qa.id, qa.timestart, qa.timefinish, qa.timemodified, q.timelimit
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE qa.userid = :userid
                AND qa.quiz = :quizid
                AND qa.state = :state
           ORDER BY qa.timestart DESC
              LIMIT 1',
            [
                'userid'  => $params['userid'],
                'quizid'  => $params['quizid'],
                'state'   => 'inprogress',
            ]
        );

        if (!$attempt) {
            return [
                'is_active'      => false,
                'attempt_id'     => 0,
                'time_remaining' => 0,
                'time_started'   => 0,
            ];
        }

        // Hitung sisa waktu
        $timeRemaining = 0;
        if ($attempt->timelimit > 0) {
            $timeElapsed  = time() - $attempt->timestart;
            $timeRemaining = max(0, $attempt->timelimit - $timeElapsed);
        }

        return [
            'is_active'      => true,
            'attempt_id'     => (int) $attempt->id,
            'time_remaining' => (int) $timeRemaining,
            'time_started'   => (int) $attempt->timestart,
        ];
    }

    /**
     * Definisi struktur output
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'is_active'      => new external_value(PARAM_BOOL, 'Whether the student has an active exam attempt'),
            'attempt_id'     => new external_value(PARAM_INT,  'ID of the active attempt, 0 if none'),
            'time_remaining' => new external_value(PARAM_INT,  'Seconds remaining, 0 if no time limit'),
            'time_started'   => new external_value(PARAM_INT,  'Unix timestamp when exam started'),
        ]);
    }
}
