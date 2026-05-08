<?php
/**
 * Dashboard monitoring status aplikasi siswa.
 * Menampilkan siapa yang aktif di Dosman Exam dan siapa yang tidak/sudah keluar.
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

// Tangani aksi via form POST
$action         = optional_param('action', '', PARAM_ALPHANUMEXT);
$unblock_userid = optional_param('unblock_userid', 0, PARAM_INT);

// Aksi: buka blokir saja (siswa lanjut sesi lama)
if ($action === 'unblock' && $unblock_userid > 0) {
    require_sesskey();
    $existing = $DB->get_field('config_plugins', 'value',
        ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);
    $blocked = $existing ? (json_decode($existing, true) ?: []) : [];
    unset($blocked[(string)$unblock_userid]);
    $DB->set_field('config_plugins', 'value', json_encode($blocked),
        ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);
    redirect(new moodle_url('/local/dosman_ujian/monitor.php'),
        'Blokir siswa berhasil dibuka.', 3, \core\output\notification::NOTIFY_SUCCESS);
}

// Aksi: hapus sesi + paksa logout (siswa harus login ulang)
if ($action === 'force_logout' && $unblock_userid > 0) {
    require_sesskey();

    // 1. Hapus catatan blokir
    $existing = $DB->get_field('config_plugins', 'value',
        ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);
    $blocked = $existing ? (json_decode($existing, true) ?: []) : [];
    unset($blocked[(string)$unblock_userid]);
    $DB->set_field('config_plugins', 'value', json_encode($blocked),
        ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);

    // 2. Hapus token Moodle milik siswa untuk service dosman_ujian_mobile
    //    → app tidak bisa API call lagi → redirect ke login
    $service = $DB->get_record('external_services', ['shortname' => 'dosman_ujian_mobile']);
    if ($service) {
        $DB->delete_records('external_tokens', [
            'externalserviceid' => $service->id,
            'userid'            => (int)$unblock_userid,
        ]);
    }

    // 3. Hapus record appstatus → tampil "Tidak Pakai Aplikasi" di dashboard
    $DB->delete_records('local_dosman_ujian_appstatus', ['userid' => (int)$unblock_userid]);

    redirect(new moodle_url('/local/dosman_ujian/monitor.php'),
        'Sesi siswa dihapus. Siswa dipaksa login ulang.', 3, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url('/local/dosman_ujian/monitor.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Monitor Aplikasi Siswa — Dosman Exam');
$PAGE->set_heading('Monitor Aplikasi Siswa');

// Threshold: siswa dianggap masih aktif jika ping < 5 menit lalu
$active_threshold = time() - (5 * 60);

// Ambil daftar siswa terblokir dari config_plugins
$blocked_json = $DB->get_field('config_plugins', 'value',
    ['plugin' => 'local_dosman_ujian', 'name' => 'blocked_students']);
$blocked_map = $blocked_json ? (json_decode($blocked_json, true) ?: []) : [];
$total_blocked = count($blocked_map);

// Ambil semua user dengan role student di seluruh sistem
$sql = "
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.username,
           a.lastping, a.ipaddress
      FROM {user} u
      JOIN {role_assignments} ra ON ra.userid = u.id
      JOIN {role} r ON r.id = ra.roleid
      LEFT JOIN {local_dosman_ujian_appstatus} a ON a.userid = u.id
     WHERE r.archetype = 'student'
       AND u.deleted = 0
       AND u.suspended = 0
     ORDER BY (a.lastping IS NULL) ASC, a.lastping DESC, u.lastname ASC, u.firstname ASC
";
$students = $DB->get_records_sql($sql);

$total    = count($students);
$aktif    = 0;
$keluar   = 0;
$tidak    = 0;

foreach ($students as $s) {
    if (!$s->lastping) {
        $tidak++;
    } elseif ($s->lastping >= $active_threshold) {
        $aktif++;
    } else {
        $keluar++;
    }
}

echo $OUTPUT->header();
?>

<style>
  .dm-monitor { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 960px; margin: 0 auto; padding: 0 16px 40px; }
  .dm-stats { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
  .dm-stat { flex: 1; min-width: 140px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; text-align: center; }
  .dm-stat .num { font-size: 32px; font-weight: 700; line-height: 1; }
  .dm-stat .lbl { font-size: 12px; color: #6b7280; margin-top: 6px; }
  .dm-stat.green .num { color: #16a34a; }
  .dm-stat.red   .num { color: #dc2626; }
  .dm-stat.gray  .num { color: #6b7280; }
  .dm-stat.blue   .num { color: #2563eb; }
  .dm-stat.orange .num { color: #d97706; }

  .btn-unblock { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; cursor: pointer; }
  .btn-unblock:hover { background: #ffedd5; }
  .btn-force-logout { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; cursor: pointer; margin-left: 6px; }
  .btn-force-logout:hover { background: #fee2e2; }

  .dm-table-wrap { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
  .dm-top { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #e5e7eb; }
  .dm-top h2 { font-size: 15px; font-weight: 700; color: #1f2937; margin: 0; }
  .dm-refresh { font-size: 12px; color: #6b7280; }

  table.dm { width: 100%; border-collapse: collapse; }
  table.dm th { background: #f9fafb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; padding: 10px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
  table.dm td { padding: 12px 16px; font-size: 14px; color: #1f2937; border-bottom: 1px solid #f3f4f6; }
  table.dm tr:last-child td { border-bottom: none; }
  table.dm tr:hover td { background: #f9fafb; }

  .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .badge.aktif  { background: #dcfce7; color: #15803d; }
  .badge.keluar { background: #fee2e2; color: #b91c1c; }
  .badge.tidak  { background: #f3f4f6; color: #6b7280; }
  .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
  .dot.aktif  { background: #16a34a; animation: pulse 1.5s infinite; }
  .dot.keluar { background: #dc2626; }
  .dot.tidak  { background: #9ca3af; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: .4; }
  }

  .search-wrap { padding: 12px 20px; border-bottom: 1px solid #e5e7eb; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .search-wrap input { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; width:220px; }
  .search-wrap input:focus { border-color: #2563eb; }
  .filter-btns { display:flex; gap:6px; flex-wrap:wrap; }
  .fbtn { padding:6px 14px; border-radius:999px; font-size:12px; font-weight:600; cursor:pointer; border:1.5px solid #e5e7eb; background:#f9fafb; color:#6b7280; }
  .fbtn.active { border-color:currentColor; background:currentColor; }
  .fbtn.f-all.active    { background:#e0e7ff; color:#3730a3; border-color:#a5b4fc; }
  .fbtn.f-aktif.active  { background:#dcfce7; color:#15803d; border-color:#86efac; }
  .fbtn.f-keluar.active { background:#fee2e2; color:#b91c1c; border-color:#fca5a5; }
  .fbtn.f-tidak.active  { background:#f3f4f6; color:#374151; border-color:#d1d5db; }
  .fbtn.f-blokir.active { background:#ffedd5; color:#c2410c; border-color:#fed7aa; }
  .badge-blokir { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#ffedd5;color:#c2410c;margin-left:6px; }
</style>

<div class="dm-monitor">
  <div class="dm-stats">
    <div class="dm-stat blue">
      <div class="num"><?= $total ?></div>
      <div class="lbl">Total Siswa</div>
    </div>
    <div class="dm-stat green">
      <div class="num"><?= $aktif ?></div>
      <div class="lbl">Aktif di Aplikasi</div>
    </div>
    <div class="dm-stat red">
      <div class="num"><?= $keluar ?></div>
      <div class="lbl">Keluar / Tidak Aktif</div>
    </div>
    <div class="dm-stat gray">
      <div class="num"><?= $tidak ?></div>
      <div class="lbl">Tidak Pakai Aplikasi</div>
    </div>
    <div class="dm-stat orange">
      <div class="num"><?= $total_blocked ?></div>
      <div class="lbl">Akun Diblokir</div>
    </div>
  </div>

  <div class="dm-table-wrap">
    <div class="dm-top">
      <h2>Status Siswa</h2>
      <span class="dm-refresh" id="countdown">Refresh otomatis dalam <strong>30</strong> detik</span>
    </div>
    <div class="search-wrap">
      <input type="text" id="search" placeholder="Cari nama atau username..." oninput="filterTable()">
      <div class="filter-btns">
        <button class="fbtn f-all active"    onclick="setFilter('semua')" id="fb-semua">Semua (<?= $total ?>)</button>
        <button class="fbtn f-aktif"          onclick="setFilter('aktif')" id="fb-aktif">🟢 Aktif (<?= $aktif ?>)</button>
        <button class="fbtn f-keluar"         onclick="setFilter('keluar')" id="fb-keluar">🔴 Keluar (<?= $keluar ?>)</button>
        <button class="fbtn f-tidak"          onclick="setFilter('tidak')" id="fb-tidak">⚫ Tidak Pakai (<?= $tidak ?>)</button>
        <button class="fbtn f-blokir"         onclick="setFilter('blokir')" id="fb-blokir">🔒 Terblokir (<?= $total_blocked ?>)</button>
      </div>
    </div>
    <table class="dm" id="studentTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Nama Siswa</th>
          <th>Username</th>
          <th>Status</th>
          <th>Terakhir Aktif</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $no = 1;
        foreach ($students as $s):
            $fullname   = htmlspecialchars(trim($s->firstname . ' ' . $s->lastname));
            $username   = htmlspecialchars($s->username);
            $ip         = htmlspecialchars($s->ipaddress ?? '-');
            $is_blocked = isset($blocked_map[(string)$s->id]);

            if (!$s->lastping) {
                $status_class = 'tidak';
                $status_label = 'Tidak pakai aplikasi';
                $last_active  = '-';
            } elseif ($s->lastping >= $active_threshold) {
                $status_class = 'aktif';
                $status_label = 'Aktif di Aplikasi';
                $diff = time() - $s->lastping;
                $last_active = $diff < 60 ? 'Baru saja' : floor($diff / 60) . ' menit lalu';
            } else {
                $status_class = 'keluar';
                $status_label = 'Keluar / Tidak Aktif';
                $diff = time() - $s->lastping;
                if ($diff < 3600) {
                    $last_active = floor($diff / 60) . ' menit lalu';
                } else {
                    $last_active = userdate($s->lastping, get_string('strftimedatetimeshort', 'langconfig'));
                }
            }
            // data-filter dipakai JS untuk filter tombol
            $data_filter = $is_blocked ? 'blokir' : $status_class;
        ?>
        <tr data-filter="<?= $data_filter ?>" data-name="<?= strtolower(strip_tags($fullname)) ?> <?= strtolower($s->username) ?>">
          <td style="color:#9ca3af"><?= $no++ ?></td>
          <td>
            <strong><?= $fullname ?></strong>
            <?php if ($is_blocked): ?>
              <span class="badge-blokir">🔒 Diblokir</span>
            <?php endif; ?>
          </td>
          <td style="color:#6b7280"><?= $username ?></td>
          <td>
            <span class="badge <?= $status_class ?>">
              <span class="dot <?= $status_class ?>"></span>
              <?= $status_label ?>
            </span>
          </td>
          <td style="color:#6b7280"><?= $last_active ?></td>
          <td style="color:#6b7280;font-size:12px"><?= $ip ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tabel Siswa Terblokir -->
<?php if (!empty($blocked_map)): ?>
<div class="dm-table-wrap" style="margin-top:28px">
  <div class="dm-top">
    <h2 style="color:#d97706">⛔ Siswa Terblokir (<?= $total_blocked ?>)</h2>
    <span class="dm-refresh" style="color:#d97706">Blokir hanya bisa dibuka oleh admin</span>
  </div>

  <!-- Petunjuk admin cara menangani siswa terblokir -->
  <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:16px 20px;margin:0 0 18px 0;font-size:13px;color:#92400e;line-height:1.7">
    <strong style="font-size:14px;color:#b45309">📋 Petunjuk Admin — Cara Menangani Siswa Terblokir</strong>
    <ul style="margin:10px 0 0 18px;padding:0">
      <li><strong>Keluar dari aplikasi saat ujian</strong> — Siswa terdeteksi membuka aplikasi lain atau menutup aplikasi ujian. Konfirmasi dulu ke siswa, lalu klik <em>Buka Blokir</em> jika ada alasan sah.</li>
      <li><strong>Keluar dari browser ujian</strong> — Siswa menutup aplikasi saat menggunakan browser ujian terkunci. Tanyakan alasan, dokumentasikan, lalu buka blokir jika diperlukan.</li>
      <li><strong>Diblokir manual</strong> — Admin memblokir secara manual. Buka blokir hanya jika sudah aman.</li>
    </ul>
    <div style="margin-top:10px;padding:10px 14px;background:#fef3c7;border-radius:7px;color:#78350f;font-size:12px">
      ⚠️ <strong>Penting:</strong> Setelah blokir dibuka, siswa bisa melanjutkan ujian. Pastikan tidak ada kecurangan sebelum membuka blokir. Status blokir diperbarui otomatis di perangkat siswa.
    </div>
    <div style="margin-top:8px;padding:10px 14px;background:#fef2f2;border-radius:7px;color:#7f1d1d;font-size:12px">
      🔴 <strong>Hapus &amp; Paksa Logout:</strong> Gunakan jika siswa perlu login ulang dari awal (misal: ganti perangkat, sesi bermasalah). Token siswa dihapus — aplikasi akan meminta login ulang segera.
    </div>
  </div>

  <table class="dm" id="blockedTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Nama Siswa</th>
        <th>Username</th>
        <th>Diblokir Sejak</th>
        <th>Alasan</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $reasons = [
          'exit_during_exam'    => 'Keluar dari aplikasi saat ujian',
          'exit_during_browser' => 'Keluar dari browser ujian terkunci',
          'screen_cast'         => 'Screen cast / mirroring terdeteksi',
          'split_screen'        => 'Split-screen terdeteksi',
          'overlay'             => 'Overlay app asing terdeteksi',
          'root_detected'       => 'Perangkat terindikasi root',
          'vpn_detected'        => 'VPN aktif terdeteksi',
          'manually_blocked'    => 'Diblokir manual oleh admin',
      ];
      $no = 1;
      // Urutkan dari terbaru
      uasort($blocked_map, fn($a, $b) => ($b['blocked_at'] ?? 0) - ($a['blocked_at'] ?? 0));
      foreach ($blocked_map as $uid => $info):
          $fullname   = htmlspecialchars($info['fullname'] ?? '');
          $username   = htmlspecialchars($info['username'] ?? $uid);
          $since      = userdate($info['blocked_at'] ?? time(), get_string('strftimedatetimeshort', 'langconfig'));
          $reasonText = htmlspecialchars($reasons[$info['reason'] ?? ''] ?? ($info['reason'] ?? '-'));
          $bg = ($no % 2 === 0) ? '#fff' : '#fff7ed';
      ?>
      <tr id="blocked-row-<?= (int)$uid ?>" style="background:<?= $bg ?>">
        <td style="color:#9ca3af"><?= $no++ ?></td>
        <td><strong><?= $fullname ?></strong></td>
        <td style="color:#6b7280"><?= $username ?></td>
        <td style="color:#d97706;white-space:nowrap"><?= $since ?></td>
        <td style="color:#6b7280"><?= $reasonText ?></td>
        <td style="white-space:nowrap">
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="action" value="unblock">
            <input type="hidden" name="unblock_userid" value="<?= (int)$uid ?>">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <button type="submit" class="btn-unblock"
                    onclick="return confirm('Buka blokir akun <?= addslashes($fullname) ?>?\n\nSiswa dapat melanjutkan sesi yang ada.')">
              Buka Blokir
            </button>
          </form>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="action" value="force_logout">
            <input type="hidden" name="unblock_userid" value="<?= (int)$uid ?>">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <button type="submit" class="btn-force-logout"
                    onclick="return confirm('Hapus sesi dan paksa logout <?= addslashes($fullname) ?>?\n\nSiswa harus login ulang ke aplikasi dari awal.')">
              Hapus &amp; Logout
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="dm-table-wrap" style="margin-top:28px">
  <div class="dm-top">
    <h2 style="color:#16a34a">✓ Tidak Ada Siswa Terblokir</h2>
  </div>
  <div style="padding:20px 24px;color:#6b7280;font-size:14px">Semua akun siswa aktif dan tidak diblokir.</div>
</div>
<?php endif; ?>

<script>
// Countdown auto-refresh
let secs = 30;
const countEl = document.querySelector('#countdown strong');
const timer = setInterval(() => {
  secs--;
  if (countEl) countEl.textContent = secs;
  if (secs <= 0) { clearInterval(timer); location.reload(); }
}, 1000);

// State filter aktif
let activeFilter = 'semua';

function setFilter(f) {
  activeFilter = f;
  // update tombol aktif
  ['semua','aktif','keluar','tidak','blokir'].forEach(function(k) {
    var btn = document.getElementById('fb-' + k);
    if (btn) btn.classList.toggle('active', k === f);
  });
  filterTable();
}

function filterTable() {
  var q = (document.getElementById('search').value || '').toLowerCase();
  document.querySelectorAll('#studentTable tbody tr').forEach(function(row) {
    var name   = (row.dataset.name  || '').toLowerCase();
    var filter = (row.dataset.filter || '');
    var matchQ = !q || name.includes(q);
    var matchF = activeFilter === 'semua' || filter === activeFilter;
    row.style.display = (matchQ && matchF) ? '' : 'none';
  });
}
</script>

<?php
echo $OUTPUT->footer();
