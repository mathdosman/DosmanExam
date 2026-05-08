<?php
/**
 * Dashboard monitoring status aplikasi siswa — Dosman Exam
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/dosman_ujian/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Monitor Siswa — Dosman Exam');
$PAGE->set_heading('Monitor Siswa');

// Pastikan tabel ada — buat langsung jika belum ada (tanpa perlu upgrade wizard)
$dbman = $DB->get_manager();
$table = new xmldb_table('local_dosman_ujian_appstatus');
if (!$dbman->table_exists($table)) {
    $table->add_field('id',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('userid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null);
    $table->add_field('lastping',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null);
    $table->add_field('ipaddress', XMLDB_TYPE_CHAR,    '45', null, null,          null, '');
    $table->add_key('primary',        XMLDB_KEY_PRIMARY,     ['id']);
    $table->add_index('idx_userid',   XMLDB_INDEX_UNIQUE,    ['userid']);
    $table->add_index('idx_lastping', XMLDB_INDEX_NOTUNIQUE, ['lastping']);
    $dbman->create_table($table);
}

// Threshold aktif: ping dalam 5 menit terakhir
$threshold = time() - 300;

$sql = "
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.username,
           a.lastping, a.ipaddress
      FROM {user} u
      JOIN {role_assignments} ra ON ra.userid = u.id
      JOIN {role} r ON r.id = ra.roleid
      LEFT JOIN {local_dosman_ujian_appstatus} a ON a.userid = u.id
     WHERE r.archetype = 'student'
       AND u.deleted  = 0
       AND u.suspended = 0
     ORDER BY (CASE WHEN a.lastping IS NULL THEN 1 ELSE 0 END) ASC,
              a.lastping DESC,
              u.lastname ASC,
              u.firstname ASC
";
$students = $DB->get_records_sql($sql);

$total  = count($students);
$aktif  = 0;
$keluar = 0;
$tidak  = 0;
foreach ($students as $s) {
    if (!$s->lastping)                    $tidak++;
    elseif ($s->lastping >= $threshold)   $aktif++;
    else                                  $keluar++;
}

// ── Handle simpan password dari form dashboard ──────────────────────────────
$pwd_saved = false;
$pwd_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHA) === 'savepwd') {
    require_sesskey();
    $new_exit_pwd    = trim(optional_param('exit_password',    '', PARAM_RAW_TRIMMED));
    $new_unblock_pwd = trim(optional_param('unblock_password', '', PARAM_RAW_TRIMMED));
    if ($new_exit_pwd === '' && $new_unblock_pwd === '') {
        $pwd_error = 'Isi minimal salah satu password sebelum menyimpan.';
    } else {
        if ($new_exit_pwd    !== '') set_config('admin_exit_password',    $new_exit_pwd,    'local_dosman_ujian');
        if ($new_unblock_pwd !== '') set_config('admin_unblock_password', $new_unblock_pwd, 'local_dosman_ujian');
        $pwd_saved = true;
    }
}

// ── Baca password saat ini ───────────────────────────────────────────────────
$cur_exit_pwd    = get_config('local_dosman_ujian', 'admin_exit_password')    ?: '';
$cur_unblock_pwd = get_config('local_dosman_ujian', 'admin_unblock_password') ?: '';

$settings_url = (new moodle_url('/admin/settings.php', ['section' => 'local_dosman_ujian']))->out();

echo $OUTPUT->header();
?>
<style>
:root {
  --blue:  #2563eb; --green: #16a34a; --red: #dc2626;
  --gray:  #6b7280; --border: #e5e7eb; --bg: #f9fafb;
}
.dm { max-width: 1000px; margin: 0 auto; padding: 0 16px 48px; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }

/* ── Header bar ── */
.dm-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.dm-header h1 { font-size:20px; font-weight:700; color:#111827; margin:0; }
.dm-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:var(--gray); background:#fff; cursor:pointer; }
.dm-btn:hover { background:var(--bg); }
.dm-btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
.dm-btn.primary:hover { background:#1d4ed8; }

/* ── Stat cards ── */
.dm-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
@media(max-width:600px){ .dm-stats { grid-template-columns:repeat(2,1fr); } }
.dm-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
.dm-card .num { font-size:30px; font-weight:700; line-height:1.1; }
.dm-card .lbl { font-size:12px; color:var(--gray); margin-top:4px; }
.dm-card.c-blue  .num { color:var(--blue);  }
.dm-card.c-green .num { color:var(--green); }
.dm-card.c-red   .num { color:var(--red);   }
.dm-card.c-gray  .num { color:var(--gray);  }

/* ── Table panel ── */
.dm-panel { background:#fff; border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.dm-panel-top { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--border); gap:12px; flex-wrap:wrap; }
.dm-panel-top h2 { font-size:14px; font-weight:700; color:#111827; margin:0; }
.dm-search { padding:7px 12px; border:1px solid var(--border); border-radius:8px; font-size:13px; outline:none; width:220px; }
.dm-search:focus { border-color:var(--blue); }
.dm-timer { font-size:12px; color:var(--gray); white-space:nowrap; }

table.dm-tbl { width:100%; border-collapse:collapse; }
table.dm-tbl th { background:var(--bg); font-size:11px; font-weight:600; color:var(--gray); text-transform:uppercase; letter-spacing:.05em; padding:9px 16px; text-align:left; border-bottom:1px solid var(--border); }
table.dm-tbl td { padding:11px 16px; font-size:13px; color:#1f2937; border-bottom:1px solid #f3f4f6; }
table.dm-tbl tr:last-child td { border-bottom:none; }
table.dm-tbl tr:hover td { background:#fafafa; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600; }
.badge.aktif  { background:#dcfce7; color:#15803d; }
.badge.keluar { background:#fee2e2; color:#b91c1c; }
.badge.tidak  { background:#f3f4f6; color:var(--gray); }
.dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.dot.aktif  { background:var(--green); animation:pulse 1.5s infinite; }
.dot.keluar { background:var(--red); }
.dot.tidak  { background:#d1d5db; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.35} }

.no-data { text-align:center; padding:40px; color:var(--gray); font-size:14px; }

/* ── Password panel ── */
.dm-pwd-panel { background:#fff; border:1px solid var(--border); border-radius:12px; padding:20px 24px; margin-bottom:24px; }
.dm-pwd-panel h2 { font-size:14px; font-weight:700; color:#111827; margin:0 0 16px; display:flex; align-items:center; gap:8px; }
.dm-pwd-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:600px){ .dm-pwd-grid { grid-template-columns:1fr; } }
.dm-pwd-field label { display:block; font-size:12px; font-weight:600; color:var(--gray); margin-bottom:5px; }
.dm-pwd-field .field-wrap { display:flex; gap:6px; }
.dm-pwd-field input[type=text] { flex:1; padding:8px 11px; border:1px solid var(--border); border-radius:8px; font-size:14px; font-family:monospace; outline:none; }
.dm-pwd-field input[type=text]:focus { border-color:var(--blue); }
.dm-pwd-field .eye-btn { padding:0 10px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:15px; }
.dm-pwd-actions { margin-top:16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.dm-pwd-actions .dm-btn { padding:9px 20px; }
.dm-msg { font-size:13px; padding:7px 12px; border-radius:7px; }
.dm-msg.ok  { background:#dcfce7; color:#15803d; }
.dm-msg.err { background:#fee2e2; color:#b91c1c; }
</style>

<div class="dm">

  <!-- Header -->
  <div class="dm-header">
    <h1>📊 Monitor Siswa — Dosman Exam</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= $settings_url ?>" class="dm-btn">⚙️ Pengaturan Plugin</a>
      <button class="dm-btn primary" onclick="location.reload()">↺ Refresh</button>
    </div>
  </div>

  <!-- Stat cards -->
  <div class="dm-stats">
    <div class="dm-card c-blue">
      <div class="num"><?= $total ?></div>
      <div class="lbl">Total Siswa</div>
    </div>
    <div class="dm-card c-green">
      <div class="num"><?= $aktif ?></div>
      <div class="lbl">Aktif di Aplikasi</div>
    </div>
    <div class="dm-card c-red">
      <div class="num"><?= $keluar ?></div>
      <div class="lbl">Keluar / Tidak Aktif</div>
    </div>
    <div class="dm-card c-gray">
      <div class="num"><?= $tidak ?></div>
      <div class="lbl">Tidak Pakai Aplikasi</div>
    </div>
  </div>

  <!-- Panel Password -->
  <div class="dm-pwd-panel">
    <h2>🔑 Pengaturan Password</h2>
    <form method="post" action="">
      <input type="hidden" name="action"  value="savepwd">
      <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
      <div class="dm-pwd-grid">
        <div class="dm-pwd-field">
          <label>Password Keluar Aplikasi</label>
          <div class="field-wrap">
            <input type="text" id="exit_pwd" name="exit_password"
                   placeholder="Kosongkan = tidak diubah"
                   autocomplete="off">
            <button type="button" class="eye-btn" onclick="togglePwd('exit_pwd')">👁</button>
          </div>
          <div style="margin-top:5px;font-size:11px;color:var(--gray)">
            Saat ini: <code><?= $cur_exit_pwd !== '' ? str_repeat('•', max(4, strlen($cur_exit_pwd)-2)).substr($cur_exit_pwd,-2) : '(belum diset)' ?></code>
          </div>
        </div>
        <div class="dm-pwd-field">
          <label>Password Buka Blokir Siswa</label>
          <div class="field-wrap">
            <input type="text" id="unblock_pwd" name="unblock_password"
                   placeholder="Kosongkan = tidak diubah"
                   autocomplete="off">
            <button type="button" class="eye-btn" onclick="togglePwd('unblock_pwd')">👁</button>
          </div>
          <div style="margin-top:5px;font-size:11px;color:var(--gray)">
            Saat ini: <code><?= $cur_unblock_pwd !== '' ? str_repeat('•', max(4, strlen($cur_unblock_pwd)-2)).substr($cur_unblock_pwd,-2) : '(belum diset)' ?></code>
          </div>
        </div>
      </div>
      <div class="dm-pwd-actions">
        <button type="submit" class="dm-btn primary">💾 Simpan Password</button>
        <?php if ($pwd_saved): ?>
          <span class="dm-msg ok">✅ Password berhasil disimpan.</span>
        <?php elseif ($pwd_error): ?>
          <span class="dm-msg err">⚠️ <?= htmlspecialchars($pwd_error) ?></span>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="dm-panel">
    <div class="dm-panel-top">
      <h2>Daftar Siswa</h2>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <input class="dm-search" id="search" type="text"
               placeholder="Cari nama / username…" oninput="filterTable()">
        <span class="dm-timer">Refresh dalam <b id="cd">30</b>s</span>
      </div>
    </div>

    <?php if (empty($students)): ?>
      <div class="no-data">Belum ada data siswa terdaftar.</div>
    <?php else: ?>
    <table class="dm-tbl" id="tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>Nama</th>
          <th>Username</th>
          <th>Status</th>
          <th>Terakhir Aktif</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $no = 1;
      foreach ($students as $s):
        $name = htmlspecialchars(fullname($s));
        $uname = htmlspecialchars($s->username);
        $ip    = htmlspecialchars($s->ipaddress ?? '—');
        if (!$s->lastping) {
          $cls = 'tidak'; $label = 'Tidak pakai aplikasi'; $ago = '—';
        } elseif ($s->lastping >= $threshold) {
          $cls = 'aktif'; $label = 'Aktif di Aplikasi';
          $diff = time() - $s->lastping;
          $ago  = $diff < 60 ? 'Baru saja' : floor($diff/60).'m lalu';
        } else {
          $cls = 'keluar'; $label = 'Keluar';
          $diff = time() - $s->lastping;
          $ago  = $diff < 3600
                ? floor($diff/60).'m lalu'
                : userdate($s->lastping, get_string('strftimedatetimeshort','langconfig'));
        }
      ?>
        <tr>
          <td style="color:#9ca3af;font-size:12px"><?= $no++ ?></td>
          <td><strong><?= $name ?></strong></td>
          <td style="color:var(--gray)"><?= $uname ?></td>
          <td><span class="badge <?= $cls ?>"><span class="dot <?= $cls ?>"></span><?= $label ?></span></td>
          <td style="color:var(--gray);font-size:12px"><?= $ago ?></td>
          <td style="color:var(--gray);font-size:12px"><?= $ip ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<script>
let s = 30;
const cd = document.getElementById('cd');
setInterval(() => { s--; if(cd) cd.textContent = s; if(s<=0) location.reload(); }, 1000);

function filterTable() {
  const q = document.getElementById('search').value.toLowerCase();
  document.querySelectorAll('#tbl tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function togglePwd(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
}
// Sembunyikan password field by default (type=password) setelah load
window.addEventListener('DOMContentLoaded', () => {
  ['exit_pwd','unblock_pwd'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.type = 'password';
  });
});
</script>

<?php echo $OUTPUT->footer(); ?>
