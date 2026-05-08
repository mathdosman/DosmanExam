// ============================================================
// EXIT PASSWORD — file terpisah, zero dependency ke dashboard.js
// ============================================================
var ExitPwd = {
  _showing: false,

  load: function () {
    var inp = document.getElementById('ep-input');
    var sts = document.getElementById('ep-status');
    if (!inp || !sts) return;
    sts.style.color = '#6b7280';
    sts.textContent = 'Memuat…';
    var apikey = (typeof CONFIG !== 'undefined' && CONFIG.EXIT_PWD_API_KEY)
      ? CONFIG.EXIT_PWD_API_KEY
      : '';
    fetch('../local/dosman_ujian/exitpwd.php?apikey=' + encodeURIComponent(apikey), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (typeof d.password !== 'string') throw new Error('Format tidak valid');
        inp.value = d.password;
        if (d.password === '') {
          sts.style.color = '#d97706';
          sts.textContent = 'Belum ada password — siswa dapat keluar tanpa konfirmasi.';
        } else {
          sts.style.color = '#16a34a';
          sts.textContent = 'Password aktif: ' + d.password.length + ' karakter.';
        }
      })
      .catch(function (e) {
        sts.style.color = '#dc2626';
        sts.textContent = 'Gagal memuat: ' + e.message;
      });
  },

  toggle: function () {
    var inp = document.getElementById('ep-input');
    var btn = document.getElementById('ep-toggle');
    if (!inp) return;
    this._showing = !this._showing;
    inp.type = this._showing ? 'text' : 'password';
    if (btn) {
      btn.innerHTML = this._showing ? '&#x1F648;' : '&#x1F441;';
      btn.title     = this._showing ? 'Sembunyikan' : 'Tampilkan';
    }
  },

  generate: function () {
    var inp = document.getElementById('ep-input');
    var sts = document.getElementById('ep-status');
    if (!inp) return;
    var digits = Math.floor(100000 + Math.random() * 900000).toString();
    inp.value     = digits;
    inp.type      = 'text';
    this._showing = true;
    var btn = document.getElementById('ep-toggle');
    if (btn) { btn.innerHTML = '&#x1F648;'; btn.title = 'Sembunyikan'; }
    if (sts) {
      sts.style.color = '#2563eb';
      sts.textContent = 'Password digenerate: ' + digits + ' — klik Simpan untuk menyimpan.';
    }
  },

  generateAndSave: function () {
    this.generate();
    this.save();
  },

  clear: function () {
    var inp = document.getElementById('ep-input');
    var sts = document.getElementById('ep-status');
    if (!inp) return;
    inp.value     = '';
    inp.type      = 'text';
    this._showing = true;
    var btn = document.getElementById('ep-toggle');
    if (btn) { btn.innerHTML = '&#x1F648;'; btn.title = 'Sembunyikan'; }
    if (sts) {
      sts.style.color = '#d97706';
      sts.textContent = 'Input dikosongkan — klik Simpan untuk menghapus password.';
    }
    inp.focus();
  },

  save: function () {
    var inp = document.getElementById('ep-input');
    var sts = document.getElementById('ep-status');
    if (!inp || !sts) return;
    var val = inp.value;
    if (val !== '' && val.length < 4) {
      sts.style.color = '#dc2626';
      sts.textContent = 'Password minimal 4 karakter, atau kosongkan.';
      return;
    }
    var token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : '';
    sts.style.color = '#6b7280';
    sts.textContent = 'Menyimpan…';
    fetch('../local/dosman_ujian/setpwd.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, password: val })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          inp.value = val;
          sts.style.color = '#16a34a';
          if (val === '') {
            sts.textContent = '✓ Password dihapus — siswa dapat keluar tanpa konfirmasi.';
          } else {
            sts.textContent = '✓ Password disimpan: ' + val.length + ' karakter.';
          }
        } else {
          sts.style.color = '#dc2626';
          sts.textContent = '✗ ' + (d.message || 'Gagal menyimpan.');
        }
      })
      .catch(function (e) {
        sts.style.color = '#dc2626';
        sts.textContent = '✗ Error: ' + e.message;
      });
  }
};

document.addEventListener('DOMContentLoaded', function () {
  ExitPwd.load();
});
