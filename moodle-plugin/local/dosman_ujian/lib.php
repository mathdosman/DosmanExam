<?php
/**
 * Library functions for local_dosman_ujian
 *
 * @package    local_dosman_ujian
 * @copyright  2024 dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Cek apakah user memiliki role guru, editingteacher, manager, atau coursecreator
 * di konteks mana pun (bukan hanya student).
 */
function local_dosman_ujian_is_teacher_or_above(int $userid): bool {
    global $DB;
    $sql = "SELECT COUNT(ra.id)
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid
               AND r.archetype IN ('manager','coursecreator','editingteacher','teacher')";
    return $DB->count_records_sql($sql, ['userid' => $userid]) > 0;
}

/**
 * Apakah user saat ini boleh memakai dashboard monitoring (get_sessions, get_logs, manage_session)
 * di course ini. Sebelumnya hanya mod/quiz:viewreports — banyak guru non-manager tidak punya itu
 * meski punya mod/quiz:grade atau mengelola aktivitas course.
 *
 * @param \context_course $context Konteks course
 * @return bool
 */
function local_dosman_ujian_user_can_monitor_course($context): bool {
    if (is_siteadmin()) {
        return true;
    }
    return has_capability('mod/quiz:viewreports', $context)
        || has_capability('mod/quiz:grade', $context)
        || has_capability('moodle/course:manageactivities', $context);
}

/**
 * Daftar event type yang dianggap mencurigakan
 */
function local_dosman_ujian_get_suspicious_events(): array {
    return [
        'app_background',
        'screenshot_attempt',
        'copy_attempt',
        'focus_lost',
        'screen_record_attempt',
        'multi_finger_gesture',
    ];
}

/**
 * Cek apakah event type termasuk mencurigakan
 */
function local_dosman_ujian_is_suspicious(string $eventtype): bool {
    return in_array($eventtype, local_dosman_ujian_get_suspicious_events());
}

/**
 * Ambil jumlah event mencurigakan seorang siswa dalam satu quiz
 */
function local_dosman_ujian_count_suspicious(int $userid, int $quizid): int {
    global $DB;
    return $DB->count_records('local_dosman_ujian_logs', [
        'userid'     => $userid,
        'quizid'     => $quizid,
        'suspicious' => 1,
    ]);
}

/**
 * Hapus semua log untuk quiz tertentu (misalnya saat quiz dihapus)
 */
function local_dosman_ujian_delete_quiz_logs(int $quizid): bool {
    global $DB;
    return $DB->delete_records('local_dosman_ujian_logs', ['quizid' => $quizid]);
}

/**
 * Format unix timestamp ke format tanggal yang mudah dibaca
 */
function local_dosman_ujian_format_time(int $timestamp): string {
    return userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig'));
}

/**
 * Ambil label yang ramah pengguna untuk setiap event type
 */
function local_dosman_ujian_get_event_label(string $eventtype): string {
    $labels = [
        'exam_start'            => 'Ujian Dimulai',
        'exam_end'              => 'Ujian Selesai',
        'app_background'        => 'Aplikasi Diminimize',
        'screenshot_attempt'    => 'Percobaan Screenshot',
        'copy_attempt'          => 'Percobaan Copy Teks',
        'focus_lost'            => 'Layar Tidak Fokus',
        'screen_record_attempt' => 'Percobaan Screen Record',
        'multi_finger_gesture'  => 'Gestur Multi-Jari',
        'exit_requested'        => 'Minta izin keluar aplikasi',
        'exit_approved'         => 'Izin keluar disetujui guru',
        'exit_rejected'         => 'Izin keluar ditolak guru',
    ];
    return $labels[$eventtype] ?? ucfirst(str_replace('_', ' ', $eventtype));
}
