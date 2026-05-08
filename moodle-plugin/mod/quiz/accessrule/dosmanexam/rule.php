<?php
/**
 * Dosman Exam browser access rule.
 *
 * Memblokir akses quiz jika User-Agent tidak mengandung
 * 'ExamDosmanAndroid' (app Android) atau 'ExamDosmanIOS' (SEB iOS).
 * Guru dengan hak preview tetap bisa akses dari browser biasa.
 *
 * @package   quizaccess_dosmanexam
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Kompatibel dengan Moodle 4.0 (accessrulebase.php) dan 4.2+ (namespace baru)
if (!class_exists('quizaccess_rule_base')) {
    if (class_exists('\mod_quiz\local\access_rule_base')) {
        class_alias('\mod_quiz\local\access_rule_base', 'quizaccess_rule_base');
    } else {
        require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');
    }
}

class quizaccess_dosmanexam extends quizaccess_rule_base {

    /** String yang harus ada di User-Agent agar diizinkan */
    const ALLOWED_UA = ['ExamDosmanAndroid', 'ExamDosmanIOS'];

    // ── Factory ──────────────────────────────────────────────────────────────

    public static function make($quizobj, $timenow, $canignoretimelimits) {
        // Hanya aktif jika opsi diaktifkan di pengaturan quiz
        if (empty($quizobj->get_quiz()->dosmanexam_required)) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    // ── Cek akses ─────────────────────────────────────────────────────────────

    public function prevent_access() {
        global $USER, $DB;

        // Guru / admin dengan hak preview tetap bisa buka dari browser biasa
        if (has_capability('mod/quiz:preview', $this->quizobj->get_context(), $USER)) {
            return false;
        }

        // Cek apakah siswa ini sedang diblokir admin
        $blockedJson = $DB->get_field('config_plugins', 'value',
            ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);
        if ($blockedJson) {
            $blocked = json_decode($blockedJson, true) ?: [];
            if (isset($blocked[(string)$USER->id])) {
                $info = $blocked[(string)$USER->id];
                $since = userdate($info['blocked_at'] ?? time(),
                    get_string('strftimedatetimeshort', 'langconfig'));
                $msg  = html_writer::tag('strong', '⛔ Akun Anda Diblokir Sementara') . html_writer::empty_tag('br');
                $msg .= html_writer::empty_tag('br');
                $msg .= 'Akun Anda diblokir karena aplikasi Dosman Exam terdeteksi keluar saat ujian berlangsung.' . html_writer::empty_tag('br');
                $msg .= 'Blokir sejak: <strong>' . htmlspecialchars($since) . '</strong>' . html_writer::empty_tag('br');
                $msg .= html_writer::empty_tag('br');
                $msg .= html_writer::tag('em', 'Hubungi guru pengawas untuk membuka blokir akun Anda.');
                return $msg;
            }
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach (self::ALLOWED_UA as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return false; // UA cocok — izinkan
            }
        }

        // UA tidak cocok — blokir dengan petunjuk download config SEB
        global $CFG;
        $configUrl = rtrim($CFG->wwwroot, '/') . '/local/dosman_ujian/sebconfig.php';
        $msg  = html_writer::tag('strong', '📵 Akses Ditolak — Wajib Gunakan Aplikasi Dosman Exam') . html_writer::empty_tag('br');
        $msg .= html_writer::empty_tag('br');
        $msg .= 'Kuis ini hanya bisa dikerjakan menggunakan:' . html_writer::empty_tag('br');
        $msg .= '• <strong>Android</strong>: aplikasi Dosman Exam' . html_writer::empty_tag('br');
        $msg .= '• <strong>iOS (iPad)</strong>: Safe Exam Browser dengan Config Dosman' . html_writer::empty_tag('br');
        $msg .= html_writer::empty_tag('br');
        $msg .= html_writer::tag('strong', '📲 Belum install Config SEB di iPad?') . html_writer::empty_tag('br');
        $msg .= 'Minta guru untuk mendownload dan kirimkan file konfigurasi SEB, ';
        $msg .= 'lalu buka file tersebut di iPad agar SEB terkonfigurasi otomatis.' . html_writer::empty_tag('br');
        $msg .= html_writer::empty_tag('br');
        $msg .= html_writer::tag('em', '⚠️ Jangan coba mengakses kuis dari browser biasa — semua aktivitas dipantau.');
        return $msg;
    }

    // ── Deskripsi yang ditampilkan ke siswa di halaman info quiz ──────────────

    public function description_html() {
        return html_writer::div(
            html_writer::tag('strong', '📱 ') .
            get_string('requirebrowsernotice', 'quizaccess_dosmanexam'),
            'quizaccess_dosmanexam_notice alert alert-info'
        );
    }

    // ── Form pengaturan quiz ──────────────────────────────────────────────────
    // Moodle 4.2+ mendeklarasikan metode ini sebagai static di base class

    public static function add_settings_form_fields($quizform, $mform) {
        $mform->addElement(
            'selectyesno',
            'dosmanexam_required',
            get_string('requirebrowser', 'quizaccess_dosmanexam')
        );
        $mform->addHelpButton('dosmanexam_required', 'requirebrowser', 'quizaccess_dosmanexam');
        $mform->setDefault('dosmanexam_required', 0);
    }

    // ── Simpan pengaturan ─────────────────────────────────────────────────────

    public static function save_settings($quiz) {
        global $DB;
        $existing = $DB->get_record('quizaccess_dosmanexam', ['quizid' => $quiz->id]);
        $data = (object)[
            'quizid'         => $quiz->id,
            'requirebrowser' => empty($quiz->dosmanexam_required) ? 0 : 1,
        ];
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('quizaccess_dosmanexam', $data);
        } else {
            $DB->insert_record('quizaccess_dosmanexam', $data);
        }
    }

    // ── Hapus pengaturan saat quiz dihapus ────────────────────────────────────

    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_dosmanexam', ['quizid' => $quiz->id]);
    }

    // ── SQL untuk memuat pengaturan bersama data quiz ─────────────────────────

    public static function get_settings_sql($quizid) {
        return [
            'COALESCE(dosmanexam.requirebrowser, 0) AS dosmanexam_required',
            'LEFT JOIN {quizaccess_dosmanexam} dosmanexam ON dosmanexam.quizid = quiz.id',
            [],
        ];
    }
}
