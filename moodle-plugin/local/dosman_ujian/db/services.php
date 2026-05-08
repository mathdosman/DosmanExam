<?php
/**
 * External services definition for local_dosman_ujian
 *
 * @package    local_dosman_ujian
 * @copyright  2024 Dosman Ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Siswa kirim log aktivitas mencurigakan
    'local_dosman_ujian_log_activity' => [
        'classname'     => 'local_dosman_ujian\external\log_activity',
        'methodname'    => 'execute',
        'description'   => 'Log student activity during exam',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Cek status ujian aktif
    'local_dosman_ujian_get_exam_status' => [
        'classname'     => 'local_dosman_ujian\external\get_exam_status',
        'methodname'    => 'execute',
        'description'   => 'Get current exam status for a student',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Guru ambil semua log
    'local_dosman_ujian_get_logs' => [
        'classname'     => 'local_dosman_ujian\external\get_logs',
        'methodname'    => 'execute',
        'description'   => 'Get exam activity logs for teacher dashboard',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Siswa daftar sesi ujian (lockdown mulai)
    'local_dosman_ujian_register_session' => [
        'classname'     => 'local_dosman_ujian\external\register_session',
        'methodname'    => 'execute',
        'description'   => 'Register exam session when student starts exam in lockdown mode',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Siswa minta izin keluar dari aplikasi (menunggu persetujuan guru)
    'local_dosman_ujian_request_exit' => [
        'classname'     => 'local_dosman_ujian\external\request_exit',
        'methodname'    => 'execute',
        'description'   => 'Student requests permission to leave the exam app',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Siswa kirim heartbeat
    'local_dosman_ujian_heartbeat' => [
        'classname'     => 'local_dosman_ujian\external\heartbeat',
        'methodname'    => 'execute',
        'description'   => 'Student sends heartbeat to confirm still in exam',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Guru kelola sesi (pause/blokir/reset)
    'local_dosman_ujian_manage_session' => [
        'classname'     => 'local_dosman_ujian\external\manage_session',
        'methodname'    => 'execute',
        'description'   => 'Teacher manages student session: pause, block, reset',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Ambil semua sesi aktif untuk dashboard
    'local_dosman_ujian_get_sessions' => [
        'classname'     => 'local_dosman_ujian\external\get_sessions',
        'methodname'    => 'execute',
        'description'   => 'Get all active sessions for teacher dashboard',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Ambil config client (admin)
    'local_dosman_ujian_get_client_config' => [
        'classname'     => 'local_dosman_ujian\external\get_client_config',
        'methodname'    => 'execute',
        'description'   => 'Get client configuration (admin only)',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Set config client (admin)
    'local_dosman_ujian_set_client_config' => [
        'classname'     => 'local_dosman_ujian\external\set_client_config',
        'methodname'    => 'execute',
        'description'   => 'Set client configuration (admin only)',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Siswa verifikasi password keluar (dibandingkan di server)
    'local_dosman_ujian_verify_exit_password' => [
        'classname'     => 'local_dosman_ujian\external\verify_exit_password',
        'methodname'    => 'execute',
        'description'   => 'Verify student exit password against server config',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];

$services = [
    'Dosman Ujian Mobile Service' => [
        'functions' => [
            // Core Moodle functions yang dibutuhkan dashboard
            'core_webservice_get_site_info',      // Verifikasi token login
            'core_enrol_get_users_courses',        // Ambil course milik user
            'core_course_get_courses',             // Ambil semua course (admin)
            'mod_quiz_get_quizzes_by_courses',     // Ambil daftar quiz

            // Custom functions plugin ini
            'local_dosman_ujian_log_activity',
            'local_dosman_ujian_get_exam_status',
            'local_dosman_ujian_get_logs',
            'local_dosman_ujian_register_session',
            'local_dosman_ujian_request_exit',
            'local_dosman_ujian_heartbeat',
            'local_dosman_ujian_manage_session',
            'local_dosman_ujian_get_sessions',
            'local_dosman_ujian_get_client_config',
            'local_dosman_ujian_set_client_config',
            'local_dosman_ujian_verify_exit_password',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'dosman_ujian_mobile',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];

