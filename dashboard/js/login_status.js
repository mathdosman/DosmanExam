// ===========================
// DOSMAN UJIAN - STATUS LOGIN SISWA
// Menampilkan semua siswa yang terdeteksi login via Android app maupun iOS/SEB
// ===========================

var LoginStatus = {
  users: [],
  suspendedUsers: [],
  deviceBlockedUsers: [],
  _deviceBlockedTimer: null,
  _appUsersStaleTimer: null,
  _lastSeenTicker: null,
  loaded: false,
  searchTouched: false,
  searchInputArmed: false,
  suppressAutofillSearch: true,
  selectedUserIds: {},
  selectedSuspendedIds: {},
  _partialRetryTimer: null,
  _partialRetryScheduled: false,

  load: function () {
    var self = this;
    var token = Auth.getToken();
    var tbody = document.getElementById('login-status-body');
    var quizTbodyLoad = document.getElementById('quiz-status-body');
    var counter = document.getElementById('login-status-count');
    var prevUsers = (self.users || []).slice();
    // Jangan langsung kosongkan tabel jika sudah ada data lama.
    if (!prevUsers.length) {
      var loadingHtml = '<tr><td colspan="9" style="padding:16px;text-align:center;color:#9ca3af;font-size:13px">&#x23F3; Memuat...</td></tr>';
      if (tbody) tbody.innerHTML = loadingHtml;
      if (quizTbodyLoad) quizTbodyLoad.innerHTML = loadingHtml;
    }
    self.sanitizeSearchInput();

    fetch('../local/dosman_ujian/get_app_users.php?token=' + encodeURIComponent(token))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) throw new Error(d.error);
        var nextUsers = d.users || [];
        // Hindari list tiba-tiba hilang saat backend sesaat mengembalikan data kosong.
        if (!nextUsers.length && prevUsers.length) {
          self.users = prevUsers;
          self._schedulePartialRetry();
        } else {
          self.users = nextUsers;
          self._clearPartialRetry();
        }
        self.selectedUserIds = {};
        self.loaded = true;
        self.rebuildLoginClassOptions();
        self.render();
      })
      .catch(function (e) {
        // Saat gagal refresh, pertahankan data terakhir agar tombol aksi tetap bisa dipakai.
        if (prevUsers.length) {
          self.users = prevUsers;
          self.rebuildLoginClassOptions();
          self.render();
          self._schedulePartialRetry();
        } else {
          var errHtml = '<tr><td colspan="9" style="padding:20px;text-align:center;color:#dc2626">&#x274C; Gagal memuat: ' + e.message + '</td></tr>';
          if (tbody) tbody.innerHTML = errHtml;
          if (quizTbodyLoad) quizTbodyLoad.innerHTML = errHtml;
          if (counter) counter.textContent = '—';
        }
      });
  },

  _schedulePartialRetry: function () {
    var self = this;
    if (self._partialRetryScheduled) return;
    self._partialRetryScheduled = true;
    if (self._partialRetryTimer) clearTimeout(self._partialRetryTimer);
    // Retry cepat agar refresh benar-benar jalan saat backend sempat kosong.
    self._partialRetryTimer = setTimeout(function () {
      self._partialRetryScheduled = false;
      self.load();
    }, 3000);
  },

  _clearPartialRetry: function () {
    if (this._partialRetryTimer) clearTimeout(this._partialRetryTimer);
    this._partialRetryTimer = null;
    this._partialRetryScheduled = false;
  },

  getSearchText: function () {
    var el = document.getElementById('login-status-search');
    if (!el) return '';
    var raw = (el.value || '').trim();
    if (!raw) return '';
    // Abaikan autofill browser (contoh: "admin") sampai user benar-benar mengetik.
    if (!this.searchTouched) {
      var uname = (typeof Auth !== 'undefined' && Auth.getUsername ? String(Auth.getUsername() || '') : '').trim().toLowerCase();
      if (uname && raw.toLowerCase() === uname) return '';
    }
    return raw.toLowerCase();
  },

  _getEffectiveSearchText: function () {
    return this.getSearchText();
  },

  sanitizeSearchInput: function () {
    var el = document.getElementById('login-status-search');
    if (!el) return;
    var raw = (el.value || '').trim();
    if (!raw) return;
    if (!this.suppressAutofillSearch) return;
    if (this.searchTouched) return;
    var uname = (typeof Auth !== 'undefined' && Auth.getUsername ? String(Auth.getUsername() || '') : '').trim().toLowerCase();
    if (uname && raw.toLowerCase() === uname) {
      el.value = '';
      this.searchTouched = false;
      this.searchInputArmed = false;
    }
  },

  getLoginClassFilter: function () {
    var sel = document.getElementById('filter-login-class');
    return sel ? (sel.value || '') : '';
  },

  getQuizSearchText: function () {
    var el = document.getElementById('quiz-status-search');
    return el ? (el.value || '').trim().toLowerCase() : '';
  },

  getQuizClassFilter: function () {
    var sel = document.getElementById('filter-quiz-class');
    return sel ? (sel.value || '') : '';
  },

  clearQuizSearch: function () {
    var el = document.getElementById('quiz-status-search');
    if (el) el.value = '';
    var sel = document.getElementById('filter-quiz-class');
    if (sel) sel.value = '';
    this.render();
  },

  _applyFilter: function (users, q, kelas) {
    return users.filter(function (u) {
      if (kelas && (u.department || '').trim() !== kelas) return false;
      if (!q) return true;
      var tokens = q.split(/\s+/).filter(Boolean);
      var hay = (
        (u.firstname || '') + ' ' + (u.lastname || '') + ' ' +
        (u.username || '') + ' ' + (u.department || '')
      ).toLowerCase();
      for (var i = 0; i < tokens.length; i++) {
        if (hay.indexOf(tokens[i]) < 0) return false;
      }
      return true;
    });
  },

  getFilteredUsers: function () {
    return this._applyFilter(this.users, this.getSearchText(), this.getLoginClassFilter());
  },

  rebuildLoginClassOptions: function () {
    var set = {};
    this.users.forEach(function (u) {
      var d = (u.department || '').trim();
      if (d) set[d] = true;
    });
    var keys = Object.keys(set).sort(function (a, b) { return a.localeCompare(b, 'id'); });
    ['filter-login-class', 'filter-quiz-class'].forEach(function (selId) {
      var sel = document.getElementById(selId);
      if (!sel) return;
      var preserved = sel.value;
      sel.innerHTML = '<option value="">🏷️ Semua Kelas</option>';
      keys.forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = k;
        sel.appendChild(o);
      });
      if (preserved && set[preserved]) sel.value = preserved;
    });
  },

  render: function () {
    var self = this;
    var quizTbody  = document.getElementById('quiz-status-body');
    var loginTbody = document.getElementById('login-status-body');
    var counter    = document.getElementById('login-status-count');
    if (!quizTbody && !loginTbody) return;

    // Unfiltered totals for the top summary (bird's-eye view)
    var allOnQuiz     = self.users.filter(function (u) { return u.current_quizid > 0 && (u.lock_status === 1 || u.client_type === 'ios'); });
    var allIdleActive = self.users.filter(function (u) { return u.lock_status === 1 && u.current_quizid === 0; });
    var allIosActive  = self.users.filter(function (u) { return u.client_type === 'ios' && u.current_quizid === 0; });
    var allPaused     = self.users.filter(function (u) { return u.lock_status === 3; });
    var allBlocked    = self.users.filter(function (u) { return u.lock_status === 2; });

    if (counter) {
      var parts = [];
      if (allOnQuiz.length)     parts.push('<span style="color:#2563eb;font-weight:700">' + allOnQuiz.length + ' sedang mengerjakan</span>');
      if (allIdleActive.length) parts.push('<span style="color:#16a34a">' + allIdleActive.length + ' aktif (Android)</span>');
      if (allIosActive.length)  parts.push('<span style="color:#0369a1">' + allIosActive.length + ' aktif (iOS)</span>');
      if (allPaused.length)     parts.push('<span style="color:#d97706;font-weight:700">' + allPaused.length + ' dijeda</span>');
      if (allBlocked.length)    parts.push('<span style="color:#dc2626;font-weight:700">' + allBlocked.length + ' terblokir</span>');
      counter.innerHTML = parts.join(', ') || '0 siswa';
    }

    // Quiz table: filter only quiz users by quiz-specific search
    var onQuiz = self._applyFilter(allOnQuiz, self.getQuizSearchText(), self.getQuizClassFilter());

    // Login table: filter non-quiz users by login-specific search
    var allLoginRaw = self.users.filter(function (u) { return !(u.current_quizid > 0 && (u.lock_status === 1 || u.client_type === 'ios')); });
    var loginFiltered = self._applyFilter(allLoginRaw, self.getSearchText(), self.getLoginClassFilter());
    var blocked    = loginFiltered.filter(function (u) { return u.lock_status === 2; });
    var paused     = loginFiltered.filter(function (u) { return u.lock_status === 3; });
    var idleActive = loginFiltered.filter(function (u) { return u.lock_status === 1 && u.current_quizid === 0; });
    var iosActive  = loginFiltered.filter(function (u) { return u.client_type === 'ios'; });

    // Update per-table count badges — update semua instance (nav cards + tab switcher)
    document.querySelectorAll('.js-quiz-count').forEach(function(el) { el.textContent = String(onQuiz.length); });
    document.querySelectorAll('.js-idle-count').forEach(function(el) { el.textContent = String(loginFiltered.length); });

    var NCOLS = 9;

    function buildRow(u, rowNum) {
      var isBlocked = u.lock_status === 2;
      var isPaused  = u.lock_status === 3;
      var isOnQuiz  = u.current_quizid > 0 && (u.lock_status === 1 || u.client_type === 'ios');
      var isIOS     = u.client_type === 'ios';
      var name      = u.firstname + ' ' + u.lastname;
      var kelas     = (u.department && u.department.trim()) ? u.department.trim() : '—';

      var statusBadge = isBlocked
        ? '<span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700">&#x1F534; Terblokir</span>'
        : isPaused
          ? '<span style="background:#fffbeb;color:#d97706;border:1px solid #fde68a;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700">&#x23F8;&#xFE0F; Dijeda</span>'
          : isOnQuiz
            ? '<span style="background:#eff6ff;color:#2563eb;border:1px solid #93c5fd;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700">&#x1F4DD; Mengerjakan</span>'
            : isIOS
              ? '<span style="background:#f0f9ff;color:#0369a1;border:1px solid #7dd3fc;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700">&#x1F34E; Aktif</span>'
              : '<span style="background:#f0fdf4;color:#16a34a;border:1px solid #86efac;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700">&#x1F7E2; Aktif</span>';

      var deviceBadge = isIOS
        ? '<span class="badge-device badge-ios">iOS / SEB</span>'
        : '<span class="badge-device badge-android">Android</span>';

      var quizCell = isOnQuiz
        ? '<span style="font-size:12px;color:#1d4ed8;font-weight:600">' + _escHtml(u.quiz_name || 'Quiz #' + u.current_quizid) + '</span>'
        : '<span style="font-size:12px;color:#9ca3af">—</span>';

      var lastSeen = self._formatLastSeen(u.lastping);

      var nm  = name.replace(/'/g, "\\'");
      var uid = u.userid;
      var btns = '';

      var suspendBtn =
        '<button type="button" class="btn-act btn-act-suspend"' +
        ' onclick="LoginStatus.suspend(' + uid + ',\'' + nm + '\'); return false;"' +
        ' title="SUSPEND — Paksa logout dan kunci akun Moodle siswa. Siswa tidak bisa login sampai guru mengaktifkan kembali.">' +
        '&#x26D4; Suspend</button>';

      var deleteBtn =
        '<button type="button" class="btn-act btn-act-delete"' +
        ' onclick="LoginStatus.reset(' + uid + ',\'' + nm + '\'); return false;"' +
        ' title="RESET — Hapus sesi monitoring dan paksa login ulang. Akun tidak di-suspend, siswa bisa login kembali.">' +
        '&#x1F504; Reset</button>';

      if (isIOS) {
        btns = suspendBtn + ' ' + deleteBtn;
      } else if (isBlocked) {
        btns =
          '<button type="button" class="btn-act btn-act-unblock"' +
          ' onclick="LoginStatus.unblock(' + uid + ',\'' + nm + '\'); return false;"' +
          ' title="BUKA BLOKIR — Aktifkan kembali akun yang terblokir global. Siswa bisa melanjutkan ujian.">' +
          '&#x1F513; Buka Blokir</button> ' +
          suspendBtn + ' ' + deleteBtn;
      } else if (isPaused) {
        btns =
          '<button type="button" class="btn-act btn-act-resume"' +
          ' onclick="LoginStatus.resume(' + uid + ',\'' + nm + '\'); return false;"' +
          ' title="LANJUTKAN — Aktifkan kembali sesi ujian yang sedang dijeda. Siswa bisa mengerjakan soal kembali.">' +
          '&#x25B6; Lanjutkan</button> ' +
          suspendBtn + ' ' + deleteBtn;
      } else {
        btns = suspendBtn + ' ' + deleteBtn;
      }

      var rowBg = isBlocked ? '#fef2f2' : isPaused ? '#fffbeb' : isOnQuiz ? '#eff6ff' : isIOS ? '#f8fbff' : '';
      var checked = self.selectedUserIds[uid] ? 'checked' : '';
      var P = 'padding:6px 10px;';
      return '<tr style="background:' + rowBg + ';border-bottom:1px solid #f3f4f6">' +
        '<td style="' + P + 'text-align:center">' +
          '<input type="checkbox" class="login-row-cb" data-userid="' + uid + '" ' + checked +
          ' onchange="LoginStatus.onToggleUser(this)" style="width:13px;height:13px;cursor:pointer">' +
        '</td>' +
        '<td style="' + P + 'color:#9ca3af;font-size:11px">' + rowNum + '</td>' +
        '<td style="' + P + 'font-size:13px"><strong>' + _escHtml(name) + '</strong></td>' +
        '<td style="' + P + 'font-size:12px;color:#374151">' + _escHtml(kelas) + '</td>' +
        '<td style="' + P + '">' + deviceBadge + '</td>' +
        '<td style="' + P + '">' + statusBadge + '</td>' +
        '<td style="' + P + 'max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + quizCell + '</td>' +
        '<td class="live-last-seen" data-lastping="' + (u.lastping || 0) + '" style="' + P + 'font-size:11px;color:#9ca3af;white-space:nowrap">' + lastSeen + '</td>' +
        '<td style="' + P + '"><div style="display:flex;gap:3px;flex-wrap:nowrap">' + btns + '</div></td>' +
        '</tr>';
    }

    // --- Tabel kuis: hanya siswa yang sedang mengerjakan ---
    var quizHtml = '';
    if (onQuiz.length === 0) {
      quizHtml = '<tr><td colspan="' + NCOLS + '" style="padding:24px;text-align:center;color:#9ca3af;font-size:13px">Tidak ada siswa yang sedang mengerjakan kuis saat ini</td></tr>';
    } else {
      onQuiz.forEach(function (u, i) { quizHtml += buildRow(u, i + 1); });
    }
    if (quizTbody) quizTbody.innerHTML = quizHtml;

    // --- Tabel login: aktif Android (idle) + iOS + dijeda + terblokir ---
    var loginOrdered = idleActive.concat(iosActive).concat(paused).concat(blocked);
    var loginHtml = '';
    if (loginOrdered.length === 0) {
      loginHtml = '<tr><td colspan="' + NCOLS + '" style="padding:24px;text-align:center;color:#9ca3af;font-size:13px">Tidak ada siswa yang aktif login</td></tr>';
    } else {
      var iIdleEnd   = idleActive.length;
      var iIosEnd    = iIdleEnd + iosActive.length;
      var iPausedEnd = iIosEnd + paused.length;
      var groupCount = [idleActive, iosActive, paused, blocked].filter(function (g) { return g.length > 0; }).length;

      loginOrdered.forEach(function (u, i) {
        if (groupCount > 1) {
          if (i === 0 && idleActive.length > 0) {
            loginHtml += '<tr><td colspan="' + NCOLS + '" style="padding:4px 10px;background:#f0fdf4;border-bottom:2px solid #86efac;font-size:11px;color:#166534;font-weight:700;letter-spacing:.5px">&#x1F7E2; AKTIF ANDROID (' + idleActive.length + ')</td></tr>';
          }
          if (i === iIdleEnd && iosActive.length > 0) {
            loginHtml += '<tr><td colspan="' + NCOLS + '" style="padding:4px 10px;background:#f0f9ff;border-bottom:2px solid #7dd3fc;font-size:11px;color:#0369a1;font-weight:700;letter-spacing:.5px">&#x1F4F1; AKTIF iOS / SEB (' + iosActive.length + ')</td></tr>';
          }
          if (i === iIosEnd && paused.length > 0) {
            loginHtml += '<tr><td colspan="' + NCOLS + '" style="padding:4px 10px;background:#fffbeb;border-bottom:2px solid #fde68a;font-size:11px;color:#92400e;font-weight:700;letter-spacing:.5px">&#x23F8; DIJEDA OLEH PENGAWAS (' + paused.length + ')</td></tr>';
          }
          if (i === iPausedEnd && blocked.length > 0) {
            loginHtml += '<tr><td colspan="' + NCOLS + '" style="padding:4px 10px;background:#fef2f2;border-bottom:2px solid #fca5a5;font-size:11px;color:#991b1b;font-weight:700;letter-spacing:.5px">&#x26D4; TERBLOKIR GLOBAL (' + blocked.length + ')</td></tr>';
          }
        }
        loginHtml += buildRow(u, i + 1);
      });
    }
    if (loginTbody) loginTbody.innerHTML = loginHtml;

    self._refreshLastSeenCells();
    self._syncMasterCheckbox();
    self._updateBulkBar();
  },

  _formatLastSeen: function (lastping) {
    var ts = parseInt(lastping, 10) || 0;
    if (ts <= 0) return '—';
    var nowSec = Math.floor(Date.now() / 1000);
    var secAgo = Math.max(0, nowSec - ts);
    if (secAgo < 60) return secAgo + ' dtk lalu';
    if (secAgo < 3600) return Math.floor(secAgo / 60) + ' mnt lalu';
    return new Date(ts * 1000).toLocaleString('id-ID');
  },

  _refreshLastSeenCells: function () {
    var self = this;
    document.querySelectorAll('.live-last-seen').forEach(function (cell) {
      var ts = cell.getAttribute('data-lastping');
      cell.textContent = self._formatLastSeen(ts);
    });
  },

  _startLastSeenTicker: function () {
    var self = this;
    if (self._lastSeenTicker) clearInterval(self._lastSeenTicker);
    self._lastSeenTicker = setInterval(function () {
      self._refreshLastSeenCells();
    }, 1000);
  },

  onToggleUser: function (cb) {
    var uid = parseInt(cb.getAttribute('data-userid'), 10);
    if (!uid) return;
    if (cb.checked) this.selectedUserIds[uid] = true;
    else delete this.selectedUserIds[uid];
    this._syncMasterCheckbox();
    this._updateBulkBar();
  },

  toggleSelectAll: function (master) {
    var self = this;
    var scope = master.id === 'quiz-select-all' ? '#quiz-status-body .login-row-cb' : '#login-status-body .login-row-cb';
    document.querySelectorAll(scope).forEach(function (cb) {
      cb.checked = master.checked;
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      if (master.checked) self.selectedUserIds[uid] = true;
      else delete self.selectedUserIds[uid];
    });
    self._updateBulkBar();
    self._syncMasterCheckbox();
  },

  clearSelection: function () {
    this.selectedUserIds = {};
    ['quiz-select-all', 'login-select-all'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.checked = false; el.indeterminate = false; }
    });
    document.querySelectorAll('.login-row-cb').forEach(function (cb) { cb.checked = false; });
    this._updateBulkBar();
  },

  _syncMasterCheckbox: function () {
    [
      { masterId: 'quiz-select-all',  scope: '#quiz-status-body .login-row-cb'  },
      { masterId: 'login-select-all', scope: '#login-status-body .login-row-cb' },
    ].forEach(function (cfg) {
      var master = document.getElementById(cfg.masterId);
      if (!master) return;
      var rows    = Array.prototype.slice.call(document.querySelectorAll(cfg.scope));
      var checked = rows.filter(function (cb) { return cb.checked; }).length;
      master.checked       = rows.length > 0 && checked === rows.length;
      master.indeterminate = checked > 0 && checked < rows.length;
    });
  },

  _updateBulkBar: function () {
    var count = Object.keys(this.selectedUserIds).length;
    var bar = document.getElementById('login-bulk-bar');
    var txt = document.getElementById('login-bulk-count');
    if (!bar) return;
    if (count > 0) {
      bar.style.display = 'flex';
      if (txt) txt.textContent = String(count);
    } else {
      bar.style.display = 'none';
    }
  },

  _setBulkLoading: function (on) {
    var el = document.getElementById('login-bulk-loading');
    if (el) el.style.display = on ? 'inline' : 'none';
  },

  clearSearch: function () {
    var el = document.getElementById('login-status-search');
    if (el) el.value = '';
    var sel = document.getElementById('filter-login-class');
    if (sel) sel.value = '';
    this.searchTouched = false;
    this.searchInputArmed = false;
    this.render();
  },

  forceResetSearch: function () {
    var el = document.getElementById('login-status-search');
    if (el) el.value = '';
    this.searchTouched = false;
    this.searchInputArmed = false;
  },

  selectBlockedOnly: function () {
    var self = this;
    self.selectedUserIds = {};
    document.querySelectorAll('.login-row-cb').forEach(function (cb) {
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      var user = self.users.find(function (u) { return u.userid === uid; });
      var blocked = !!(user && user.lock_status === 2);
      cb.checked = blocked;
      if (blocked) self.selectedUserIds[uid] = true;
    });
    self._syncMasterCheckbox();
    self._updateBulkBar();
  },

  selectVisibleOnly: function () {
    var self = this;
    self.selectedUserIds = {};
    document.querySelectorAll('.login-row-cb').forEach(function (cb) {
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      cb.checked = true;
      self.selectedUserIds[uid] = true;
    });
    self._syncMasterCheckbox();
    self._updateBulkBar();
  },

  selectPausedOnly: function () {
    var self = this;
    self.selectedUserIds = {};
    document.querySelectorAll('.login-row-cb').forEach(function (cb) {
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      var user = self.users.find(function (u) { return u.userid === uid; });
      var paused = !!(user && user.lock_status === 3);
      cb.checked = paused;
      if (paused) self.selectedUserIds[uid] = true;
    });
    self._syncMasterCheckbox();
    self._updateBulkBar();
  },

  selectActiveOnly: function () {
    var self = this;
    self.selectedUserIds = {};
    document.querySelectorAll('.login-row-cb').forEach(function (cb) {
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      var user = self.users.find(function (u) { return u.userid === uid; });
      var active = !!(user && user.lock_status === 1);
      cb.checked = active;
      if (active) self.selectedUserIds[uid] = true;
    });
    self._syncMasterCheckbox();
    self._updateBulkBar();
  },

  getSelectedUsers: function () {
    var self = this;
    var ids = Object.keys(self.selectedUserIds).map(function (x) { return parseInt(x, 10); });
    return self.users.filter(function (u) { return ids.indexOf(u.userid) >= 0; });
  },

  _setLockRequest: function (userid, lockStatus) {
    var token = Auth.getToken();
    return fetch('../local/dosman_ujian/set_lock_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, userid: userid, lock_status: lockStatus })
    }).then(function (r) { return r.json(); });
  },

  _suspendRequest: function (userid) {
    var token = Auth.getToken();
    return fetch('../local/dosman_ujian/suspend_student.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, userid: userid })
    }).then(function (r) { return r.json(); });
  },

  _unsuspendRequest: function (userid) {
    var token = Auth.getToken();
    return fetch('../local/dosman_ujian/unsuspend_student.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, userid: userid })
    }).then(function (r) { return r.json(); });
  },

  _forceLogoutRequest: function (userid) {
    var token = Auth.getToken();
    return fetch('../local/dosman_ujian/force_logout_student.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, userid: userid })
    }).then(function (r) { return r.json(); });
  },

  _runBulkUsers: function (title, taskFactory, doneMsg) {
    var self = this;
    var targets = self.getSelectedUsers();
    if (!targets.length) {
      Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih minimal 1 siswa terlebih dahulu.' });
      return;
    }
    Swal.fire({
      title: title,
      text: title + ' untuk ' + targets.length + ' siswa terpilih?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Lanjutkan',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setBulkLoading(true);
      Promise.allSettled(targets.map(function (u) { return taskFactory(u); }))
        .then(function (results) {
          var ok = results.filter(function (r) {
            return r.status === 'fulfilled' && r.value && r.value.success;
          }).length;
          var fail = results.length - ok;
          if (typeof Dashboard !== 'undefined') {
            Dashboard.showAlert(fail ? 'warn' : 'success', '&#x2705; Aksi Massal',
              doneMsg + ' — berhasil: ' + ok + ', gagal: ' + fail);
          }
          self.clearSelection();
          self.load();
          self.loadSuspended();
        })
        .finally(function () {
          self._setBulkLoading(false);
        });
    });
  },

  bulkPause: function () {
    this._runBulkUsers('Jeda ujian', function (u) {
      return LoginStatus._setLockRequest(u.userid, 3);
    }, 'Jeda massal selesai');
  },

  bulkResume: function () {
    this._runBulkUsers('Lanjutkan ujian', function (u) {
      return LoginStatus._setLockRequest(u.userid, 1);
    }, 'Lanjutkan massal selesai');
  },





  bulkSuspend: function () {
    this._runBulkUsers('Suspend akun', function (u) {
      return LoginStatus._suspendRequest(u.userid);
    }, 'Suspend massal selesai');
  },

  bulkReset: function () {
    this._runBulkUsers('Reset sesi & paksa login ulang', function (u) {
      return LoginStatus._forceLogoutRequest(u.userid);
    }, 'Reset massal selesai');
  },

  blockGlobal: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Blokir Siswa?',
      html: '<strong>' + name + '</strong><ul style="text-align:left;padding-left:20px;margin:10px 0 0"><li>Akun Moodle langsung di-suspend</li><li>Siswa di-logout dari app dan browser</li><li>Tidak dapat login sampai blokir dibuka</li></ul>',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Ya, Blokir',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setLock(userid, 2, function () {
        if (typeof Dashboard !== 'undefined') {
          Dashboard.showAlert('success', '&#x1F6AB; Diblokir & Di-Suspend', name + ' berhasil diblokir dan akun di-suspend.');
        }
        self.load();
        self.loadSuspended();
      });
    });
  },

  pause: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Jeda Ujian?',
      text: '"' + name + '" akan melihat halaman tunggu sampai dilanjutkan.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Jeda',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#d97706',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setLock(userid, 3, function () {
        if (typeof Dashboard !== 'undefined') {
          Dashboard.showAlert('success', '&#x23F8;&#xFE0F; Dijeda', name + ' berhasil dijeda.');
        }
        self.load();
      });
    });
  },

  resume: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Lanjutkan Ujian?',
      text: '"' + name + '" dapat kembali mengerjakan ujian.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Lanjutkan',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#16a34a',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setLock(userid, 1, function () {
        if (typeof Dashboard !== 'undefined') {
          Dashboard.showAlert('success', '&#x25B6;&#xFE0F; Dilanjutkan', name + ' berhasil dilanjutkan.');
        }
        self.load();
      });
    });
  },

  unblock: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Buka Blokir?',
      html: '<strong>' + name + '</strong><br><small style="color:#9ca3af">Catatan: jika akun masih suspend, aktifkan dari panel Akun Tersuspend.</small>',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Buka Blokir',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setLock(userid, 1, function () {
        if (typeof Dashboard !== 'undefined') {
          Dashboard.showAlert('success', '&#x1F513; Blokir Dibuka', name + ' berhasil dibuka blokirnya. Jika masih suspend, aktifkan dari panel admin.');
        }
        self.load();
        self.loadSuspended();
      });
    });
  },

  _setLock: function (userid, lockStatus, onSuccess) {
    this._setLockRequest(userid, lockStatus)
      .then(function (d) {
        if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.error || 'Unknown error' }); return; }
        if (onSuccess) onSuccess();
      })
      .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
  },

  reset: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Reset Sesi?',
      html: '<strong>' + name + '</strong><ul style="text-align:left;padding-left:20px;margin:10px 0 0"><li>Siswa dipaksa login ulang ke aplikasi</li><li>Hilang dari tabel monitoring</li><li>Akun Moodle <b>TIDAK</b> di-suspend</li><li>Jawaban ujian yang sudah tersimpan <b>TIDAK</b> hilang</li></ul>',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Reset',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._forceLogoutRequest(userid)
        .then(function (d) {
          if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.error || 'Unknown error' }); return; }
          if (typeof Dashboard !== 'undefined') {
            Dashboard.showAlert('success', '&#x1F504; Reset Berhasil', name + ' telah direset — siswa perlu login ulang.');
          }
          self.load();
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  suspend: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Suspend Akun?',
      html: '<strong>' + name + '</strong><ul style="text-align:left;padding-left:20px;margin:10px 0 0"><li>Siswa langsung di-logout dari app dan Moodle</li><li>Akun tidak bisa digunakan sampai diaktifkan kembali</li></ul>',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Ya, Suspend',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._suspendRequest(userid)
        .then(function (d) {
          if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.message || 'Unknown error' }); return; }
          if (typeof Dashboard !== 'undefined') {
            Dashboard.showAlert('success', '&#x1F6D1; Akun Di-Suspend', name + ' telah di-logout dan akun di-suspend.');
          }
          self.load();
          self.loadSuspended();
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  unsuspend: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Aktifkan Kembali?',
      text: '"' + name + '" akan dapat login kembali ke aplikasi.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Aktifkan',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#16a34a',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._unsuspendRequest(userid)
        .then(function (d) {
          if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.message || 'Unknown error' }); return; }
          if (typeof Dashboard !== 'undefined') {
            Dashboard.showAlert('success', '&#x2705; Akun Diaktifkan', name + ' dapat login kembali.');
          }
          self.loadSuspended();
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  loadSuspended: function () {
    var self = this;
    var token = Auth.getToken();
    var tbody = document.getElementById('suspended-students-body');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" style="padding:14px;text-align:center;color:#9ca3af">&#x23F3; Memuat...</td></tr>';

    fetch('../local/dosman_ujian/get_suspended_students.php?token=' + encodeURIComponent(token))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) throw new Error(d.error);
        self.suspendedUsers = d.students || [];
        self.selectedSuspendedIds = {};
        var count = self.suspendedUsers.length || '0';
        document.querySelectorAll('.js-suspended-count').forEach(function(el) { el.textContent = count; });
        self.rebuildSuspendedClassOptions();
        self.renderSuspended(self.suspendedUsers);
      })
      .catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding:14px;text-align:center;color:#dc2626">&#x274C; ' + e.message + '</td></tr>';
        if (counter) counter.textContent = '—';
      });
  },

  getSuspendedSearchText: function () {
    var el = document.getElementById('suspended-search');
    return (el && el.value ? el.value : '').trim().toLowerCase();
  },

  getSuspendedClassFilter: function () {
    var sel = document.getElementById('filter-suspended-class');
    return sel ? (sel.value || '') : '';
  },

  getFilteredSuspended: function () {
    var q = this.getSuspendedSearchText();
    var kelas = this.getSuspendedClassFilter();
    return this.suspendedUsers.filter(function (s) {
      if (kelas && (s.department || '').trim() !== kelas) return false;
      if (!q) return true;
      var tokens = q.split(/\s+/).filter(Boolean);
      var hay = (
        (s.firstname || '') + ' ' + (s.lastname || '') + ' ' +
        (s.username || '') + ' ' + (s.department || '')
      ).toLowerCase();
      for (var i = 0; i < tokens.length; i++) {
        if (hay.indexOf(tokens[i]) < 0) return false;
      }
      return true;
    });
  },

  rebuildSuspendedClassOptions: function () {
    var sel = document.getElementById('filter-suspended-class');
    if (!sel) return;
    var preserved = sel.value;
    var set = {};
    this.suspendedUsers.forEach(function (s) {
      var d = (s.department || '').trim();
      if (d) set[d] = true;
    });
    var keys = Object.keys(set).sort(function (a, b) { return a.localeCompare(b, 'id'); });
    sel.innerHTML = '<option value="">🏷️ Semua Kelas</option>';
    keys.forEach(function (k) {
      var o = document.createElement('option');
      o.value = k;
      o.textContent = k;
      sel.appendChild(o);
    });
    if (preserved && set[preserved]) sel.value = preserved;
  },

  renderSuspended: function (students) {
    var tbody = document.getElementById('suspended-students-body');
    if (!tbody) return;
    var filtered = this.getFilteredSuspended();
    if (!filtered.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="padding:16px;text-align:center;color:#9ca3af">Tidak ada akun tersuspend yang cocok dengan pencarian</td></tr>';
      this._updateSuspendedBulkBar();
      return;
    }
    var html = '';
    var self = this;
    filtered.forEach(function (s, i) {
      var name  = s.firstname + ' ' + s.lastname;
      var kelas = (s.department && s.department.trim()) ? s.department.trim() : '—';
      var when  = s.timemodified ? new Date(s.timemodified * 1000).toLocaleString('id-ID') : '—';
      var nm    = name.replace(/'/g, "\\'");
      var checked = self.selectedSuspendedIds[s.userid] ? 'checked' : '';
      html += '<tr style="border-bottom:1px solid #f3f4f6;background:#fdf4ff">' +
        '<td style="padding:9px 10px;text-align:center">' +
          '<input type="checkbox" class="suspended-row-cb" data-userid="' + s.userid + '" ' + checked +
          ' onchange="LoginStatus.onToggleSuspended(this)" style="width:14px;height:14px;cursor:pointer">' +
        '</td>' +
        '<td style="padding:9px 12px;color:#6b7280;font-size:12px">' + (i + 1) + '</td>' +
        '<td style="padding:9px 12px"><strong>' + _escHtml(name) + '</strong></td>' +
        '<td style="padding:9px 12px;font-size:12px;color:#374151">' + _escHtml(kelas) + '</td>' +
        '<td style="padding:9px 12px;font-size:12px;color:#6b7280;white-space:nowrap">' + when + '</td>' +
        '<td style="padding:9px 12px">' +
          '<button onclick="LoginStatus.unsuspend(' + s.userid + ',\'' + nm + '\')" ' +
          'style="background:#16a34a;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">' +
          '&#x2705; Aktifkan Kembali</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
    this._syncSuspendedMasterCheckbox();
    this._updateSuspendedBulkBar();
  },

  onToggleSuspended: function (cb) {
    var uid = parseInt(cb.getAttribute('data-userid'), 10);
    if (!uid) return;
    if (cb.checked) this.selectedSuspendedIds[uid] = true;
    else delete this.selectedSuspendedIds[uid];
    this._syncSuspendedMasterCheckbox();
    this._updateSuspendedBulkBar();
  },

  toggleSelectAllSuspended: function (master) {
    var self = this;
    document.querySelectorAll('.suspended-row-cb').forEach(function (cb) {
      cb.checked = master.checked;
      var uid = parseInt(cb.getAttribute('data-userid'), 10);
      if (!uid) return;
      if (master.checked) self.selectedSuspendedIds[uid] = true;
      else delete self.selectedSuspendedIds[uid];
    });
    self._updateSuspendedBulkBar();
  },

  clearSuspendedSelection: function () {
    this.selectedSuspendedIds = {};
    var master = document.getElementById('suspended-select-all');
    if (master) master.checked = false;
    document.querySelectorAll('.suspended-row-cb').forEach(function (cb) { cb.checked = false; });
    this._updateSuspendedBulkBar();
  },

  _syncSuspendedMasterCheckbox: function () {
    var master = document.getElementById('suspended-select-all');
    if (!master) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('.suspended-row-cb'));
    if (!rows.length) {
      master.checked = false;
      return;
    }
    var checkedCount = rows.filter(function (cb) { return cb.checked; }).length;
    master.checked = checkedCount > 0 && checkedCount === rows.length;
  },

  _updateSuspendedBulkBar: function () {
    var count = Object.keys(this.selectedSuspendedIds).length;
    var bar = document.getElementById('suspended-bulk-bar');
    var txt = document.getElementById('suspended-bulk-count');
    if (!bar) return;
    if (count > 0) {
      bar.style.display = 'flex';
      if (txt) txt.textContent = String(count);
    } else {
      bar.style.display = 'none';
    }
  },

  _setSuspendedBulkLoading: function (on) {
    var el = document.getElementById('suspended-bulk-loading');
    if (el) el.style.display = on ? 'inline' : 'none';
  },

  clearSuspendedSearch: function () {
    var el = document.getElementById('suspended-search');
    if (el) el.value = '';
    var sel = document.getElementById('filter-suspended-class');
    if (sel) sel.value = '';
    this.renderSuspended(this.suspendedUsers);
  },

  bulkUnsuspend: function () {
    var self = this;
    var ids = Object.keys(self.selectedSuspendedIds).map(function (x) { return parseInt(x, 10); });
    var targets = self.suspendedUsers.filter(function (u) { return ids.indexOf(u.userid) >= 0; });
    if (!targets.length) {
      Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih minimal 1 akun suspend terlebih dahulu.' });
      return;
    }
    Swal.fire({
      title: 'Aktifkan Kembali?',
      text: 'Aktifkan kembali ' + targets.length + ' akun terpilih?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Aktifkan',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#16a34a',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._setSuspendedBulkLoading(true);
      Promise.allSettled(targets.map(function (u) {
        return self._unsuspendRequest(u.userid);
      })).then(function (results) {
        var ok = results.filter(function (r) {
          return r.status === 'fulfilled' && r.value && r.value.success;
        }).length;
        var fail = results.length - ok;
        if (typeof Dashboard !== 'undefined') {
          Dashboard.showAlert(fail ? 'warn' : 'success', '&#x2705; Batch Unsuspend',
            'Aktifkan akun selesai — berhasil: ' + ok + ', gagal: ' + fail);
        }
        self.clearSuspendedSelection();
        self.loadSuspended();
      }).finally(function () {
        self._setSuspendedBulkLoading(false);
      });
    });
  },

  _escHtml: function (str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  },

  loadDeviceBlocked: function () { /* Tier 1 dihapus — tidak ada lagi */ },

  renderDeviceBlocked: function () { /* Tier 1 dihapus — tidak ada lagi */ },

  _startDeviceBlockedAutoRefresh: function () {
    var self = this;
    // Perbarui tabel login/aktif setiap 30 detik agar stale-check berjalan
    // meskipun guru tidak membuka tab secara manual.
    if (self._appUsersStaleTimer) clearInterval(self._appUsersStaleTimer);
    self._appUsersStaleTimer = setInterval(function () {
      self.load();
    }, 30000);
  },

  unblockDevice: function (deviceId, name) {
    var self = this;
    Swal.fire({
      title: 'Buka Blokir Perangkat?',
      text: '"' + name + '" dapat melanjutkan ujian dari perangkat ini.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Buka Blokir',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      var token = Auth.getToken();
      fetch('../local/dosman_ujian/unblock_device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: token, device_id: deviceId })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.message || 'Unknown error' }); return; }
          if (typeof Dashboard !== 'undefined') {
            Dashboard.showAlert('success', '&#x1F513; Blokir Dibuka', name + ' dapat kembali menggunakan perangkatnya.');
          }
          self.loadDeviceBlocked();
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  escalateNow: function (userid, name) {
    var self = this;
    Swal.fire({
      title: 'Suspend Sekarang?',
      text: '"' + name + '" — akun tidak dapat login sampai diaktifkan kembali.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Ya, Suspend',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
    }).then(function (res) {
      if (!res.isConfirmed) return;
      self._suspendRequest(userid).then(function (d) {
        if (d && d.success && typeof Dashboard !== 'undefined') {
          Dashboard.showAlert('success', '&#x1F6D1; Di-Suspend', name + ' berhasil di-suspend.');
        }
        self.loadDeviceBlocked();
        self.loadSuspended();
      }).catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

};

function _escHtml(s) {
  if (!s) return '';
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', function () {
  LoginStatus._startLastSeenTicker();
  var search = document.getElementById('login-status-search');
  if (search) {
    search.setAttribute('autocomplete', 'off');
    search.setAttribute('autocorrect', 'off');
    search.setAttribute('autocapitalize', 'off');
    search.setAttribute('spellcheck', 'false');
    // Bersihkan nilai autofill browser yang sering menyisipkan username ("admin").
    LoginStatus.sanitizeSearchInput();
    search.addEventListener('focus', function () {
      LoginStatus.sanitizeSearchInput();
    });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') e.preventDefault();
      if (e.key === 'Escape') {
        LoginStatus.clearSearch();
        return;
      }
      LoginStatus.searchInputArmed = true;
    });
    search.addEventListener('paste', function () {
      LoginStatus.searchInputArmed = true;
    });
    search.addEventListener('input', function () {
      // Abaikan input yang bukan interaksi langsung user (autofill/script restore).
      if (document.activeElement !== search) {
        LoginStatus.forceResetSearch();
        LoginStatus.render();
        return;
      }
      if (LoginStatus.searchInputArmed) {
        LoginStatus.searchTouched = true;
      } else {
        // Input yang datang dari autofill tidak dianggap filter aktif.
        LoginStatus.sanitizeSearchInput();
      }
      LoginStatus.render();
    });
    search.addEventListener('blur', function () {
      LoginStatus.searchInputArmed = false;
      LoginStatus.sanitizeSearchInput();
    });
    // Guard tambahan: beberapa browser isi autofill setelah DOM ready.
    setTimeout(function () {
      if (!LoginStatus.searchTouched) {
        LoginStatus.forceResetSearch();
        LoginStatus.render();
      }
    }, 200);
  }
  var loginClassFilter = document.getElementById('filter-login-class');
  if (loginClassFilter) {
    loginClassFilter.addEventListener('change', function () {
      LoginStatus.render();
    });
  }

  var quizSearch = document.getElementById('quiz-status-search');
  if (quizSearch) {
    quizSearch.addEventListener('input', function () { LoginStatus.render(); });
    quizSearch.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') LoginStatus.clearQuizSearch();
    });
  }
  var quizClassFilter = document.getElementById('filter-quiz-class');
  if (quizClassFilter) {
    quizClassFilter.addEventListener('change', function () { LoginStatus.render(); });
  }

  var suspendedSearch = document.getElementById('suspended-search');
  if (suspendedSearch) {
    suspendedSearch.addEventListener('input', function () {
      LoginStatus.renderSuspended(LoginStatus.suspendedUsers || []);
    });
  }

  var suspendedClassFilter = document.getElementById('filter-suspended-class');
  if (suspendedClassFilter) {
    suspendedClassFilter.addEventListener('change', function () {
      LoginStatus.renderSuspended(LoginStatus.suspendedUsers || []);
    });
  }

  ['quiz-select-all', 'login-select-all'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener('change', function () { LoginStatus.toggleSelectAll(this); });
    }
  });

  var suspendedSelectAll = document.getElementById('suspended-select-all');
  if (suspendedSelectAll) {
    suspendedSelectAll.addEventListener('change', function () {
      LoginStatus.toggleSelectAllSuspended(this);
    });
  }
});
