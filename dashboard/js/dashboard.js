// ===========================
// DOSMAN UJIAN - DASHBOARD v2
// Card-based course & quiz selection
// ===========================

var Dashboard = {
  currentQuizId:   null,
  currentCourseId: null,
  currentQuizName: '',
  refreshTimer:    null,
  countdownTimer:  null,
  countdown:       10,
  allSessions:     [],
  /** Pesan error API terakhir (get_sessions / get_logs) — jangan tampilkan "kosong" jika sebenarnya gagal. */
  sessionFetchError: null,
  allLogs:         [],
  knownBlockedIds: {},
  knownOfflineIds: {},
  knownExitRequestIds: {},
  appStatusMap:    {},
  selectedBlockedIds: {},
  activeStudentsExpanded: false,
  currentCourses:  [],
  currentQuizzes:  [],
  pendingHashState: null,
  hashRestored: false,

  // Warna banner course
  COLORS: ['c1','c2','c3','c4','c5','c6','c7','c8'],
  getColor: function(id) { return this.COLORS[id % this.COLORS.length]; },

  _escapeHtml: function (s) {
    if (s == null || s === '') return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  },

  /** Pencarian nama/email/department: beberapa kata, tiap kata harus muncul (seperti %kata% di SQL). */
  matchesStudentSearch: function (row) {
    var q = (document.getElementById('search-student') && document.getElementById('search-student').value || '').trim().toLowerCase();
    if (!q) return true;
    var tokens = q.split(/\s+/).filter(Boolean);
    var hay = (
      (row.firstname || '') + ' ' + (row.lastname || '') + ' ' +
      (row.email || '') + ' ' + (row.department || '')
    ).toLowerCase();
    for (var i = 0; i < tokens.length; i++) {
      if (hay.indexOf(tokens[i]) < 0) return false;
    }
    return true;
  },

  /** Filter kelas = nilai persis field department (diisi dari dropdown). */
  matchesStudentClass: function (row) {
    var sel = document.getElementById('filter-student-class');
    if (!sel || !sel.value) return true;
    return ((row.department || '').trim() === sel.value);
  },

  getFilteredSessions: function () {
    var self = this;
    return self.allSessions.filter(function (s) {
      return self.matchesStudentSearch(s) && self.matchesStudentClass(s);
    });
  },

  /** Isi dropdown kelas dari nilai department unik di data sesi. */
  rebuildClassFilterOptions: function () {
    var sel = document.getElementById('filter-student-class');
    if (!sel) return;
    var preserved = sel.value;
    var set = {};
    this.allSessions.forEach(function (s) {
      var d = (s.department || '').trim();
      if (d) set[d] = true;
    });
    var keys = Object.keys(set).sort(function (a, b) { return a.localeCompare(b, 'id'); });
    sel.innerHTML = '<option value="">&#x1F3F7;&#xFE0F; Semua kelas</option>';
    keys.forEach(function (k) {
      var o = document.createElement('option');
      o.value = k;
      o.textContent = k;
      sel.appendChild(o);
    });
    if (preserved && set[preserved]) sel.value = preserved;
  },

  clearMonitorFilters: function () {
    var si = document.getElementById('search-student');
    var fc = document.getElementById('filter-student-class');
    if (si) si.value = '';
    if (fc) fc.value = '';
    this.renderSessions();
    this.renderLogs();
  },

  init: function () {
    if (!Auth.isLoggedIn()) { window.location.href = 'login.html'; return; }
    this.bindEvents();
    window.addEventListener('hashchange', this.handleHashChange.bind(this));
    this.loadCourses();
  },

  // ===== NAVIGASI HALAMAN =====
  showPage: function (pageId) {
    document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById(pageId).classList.add('active');
  },

  setHashState: function (page, courseId, quizId, adminTab) {
    var hash = '#'+page;
    var params = [];
    if (courseId) params.push('course=' + encodeURIComponent(courseId));
    if (quizId) params.push('quiz=' + encodeURIComponent(quizId));
    if (page === 'courses') {
      var tab = adminTab || (typeof AdminCourseTabs !== 'undefined' ? AdminCourseTabs.current : '');
      if (tab) params.push('tab=' + encodeURIComponent(tab));
    }
    if (params.length) hash += '?' + params.join('&');
    window.history.replaceState(null, '', hash);
  },

  parseHashState: function () {
    var hash = window.location.hash || '';
    if (!hash) return { page: 'courses' };
    var parts = hash.substring(1).split('?');
    var page = parts[0] || 'courses';
    var query = parts[1] || '';
    var params = {};
    query.split('&').forEach(function (part) {
      var kv = part.split('=');
      if (kv.length === 2) {
        params[kv[0]] = decodeURIComponent(kv[1]);
      }
    });
    return {
      page: page,
      courseId: params.course ? parseInt(params.course, 10) || null : null,
      quizId: params.quiz ? parseInt(params.quiz, 10) || null : null,
      adminTab: params.tab || null,
    };
  },

  handleHashChange: function () {
    var state = this.parseHashState();
    if (state.page === 'courses') {
      if (typeof AdminCourseTabs !== 'undefined' && AdminCourseTabs.open) {
        AdminCourseTabs.open(state.adminTab || undefined);
      }
      this.showCourses();
    } else if (state.page === 'quizzes' && state.courseId) {
      var course = this.currentCourses.find(function (c) { return c.id === state.courseId; });
      if (course) this.openCourse(course);
    } else if (state.page === 'monitor' && state.courseId && state.quizId) {
      var course = this.currentCourses.find(function (c) { return c.id === state.courseId; });
      if (course) {
        var quiz = this.currentQuizzes.find(function (q) { return q.id === state.quizId; });
        if (quiz) {
          this.startMonitoring(state.quizId, state.courseId, quiz.name);
        } else {
          this.openCourse(course);
          this.pendingHashState = state;
        }
      }
    }
  },

  restoreFromHash: function () {
    if (this.hashRestored) return;
    var state = this.parseHashState();
    if (state.page === 'courses') {
      if (typeof AdminCourseTabs !== 'undefined' && AdminCourseTabs.open) {
        AdminCourseTabs.open(state.adminTab || undefined);
      }
      this.showCourses();
      this.hashRestored = true;
      return;
    }
    if (state.page === 'quizzes' && state.courseId) {
      var course = this.currentCourses.find(function (c) { return c.id === state.courseId; });
      if (course) {
        this.openCourse(course);
        this.hashRestored = true;
        return;
      }
    }
    if (state.page === 'monitor' && state.courseId && state.quizId) {
      var course = this.currentCourses.find(function (c) { return c.id === state.courseId; });
      if (course) {
        this.openCourse(course);
        this.pendingHashState = state;
      }
      this.hashRestored = true;
    }
  },

  showCourses: function () {
    this.showPage('page-courses');
    if (typeof AdminCourseTabs !== 'undefined' && AdminCourseTabs.open) {
      AdminCourseTabs.open(AdminCourseTabs.getSaved ? AdminCourseTabs.getSaved() : AdminCourseTabs.current);
    }
    document.getElementById('nav-badge').textContent = 'Dashboard';
    this.stopAutoRefresh();
    this.setHashState('courses', null, null, typeof AdminCourseTabs !== 'undefined' ? AdminCourseTabs.current : null);
  },

  showQuizzes: function () {
    this.showPage('page-quizzes');
    this.stopAutoRefresh();
    if (this.currentCourseId) {
      this.setHashState('quizzes', this.currentCourseId);
    } else {
      this.setHashState('courses');
    }
  },

  // ===== LOAD COURSES =====
  loadCourses: function () {
    var self = this;
    var grid = document.getElementById('course-grid');
    grid.innerHTML = '<p style="color:var(--gray-400);font-size:13px;padding:20px">&#x23F3; Memuat course...</p>';

    // Ambil userid dulu, lalu ambil courses
    API.getUserId()
      .then(function (userid) {
        var token = Auth.getToken();
        var url   = API.buildUrl('webservice', {
          wstoken:            token,
          wsfunction:         'core_enrol_get_users_courses',
          moodlewsrestformat: 'json',
          userid:             userid
        });
        return fetch(url).then(function(r){ return r.json(); })
          .then(function(data){
            if (data && data.exception) {
              if (data.errorcode === 'invalidtoken') { Auth.logout(); throw new Error(data.message); }
              throw new Error(data.message);
            }
            if (Array.isArray(data) && data.length > 0) return data.filter(function(c){ return c.id > 1; });
            // Fallback: semua course
            var url2 = API.buildUrl('webservice', {
              wstoken:            token,
              wsfunction:         'core_course_get_courses',
              moodlewsrestformat: 'json'
            });
            return fetch(url2).then(function(r){ return r.json(); })
              .then(function(d){
                if (d && d.exception) {
                  if (d.errorcode === 'invalidtoken') { Auth.logout(); throw new Error(d.message); }
                  throw new Error(d.message);
                }
                if (Array.isArray(d)) return d.filter(function(c){ return c.id > 1; });
                return [];
              });
          });
      })
      .then(function (courses) {
        self.currentCourses = courses;
        self.renderCourseCards(courses);
        self.restoreFromHash();
      })
      .catch(function (e) {
        grid.innerHTML =
          '<div style="grid-column:1/-1;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:20px;color:#dc2626">'+
          '<strong>&#x274C; Gagal memuat course</strong><br><br>' +
          '<code style="font-size:12px">' + e.message + '</code><br><br>' +
          '<button onclick="Dashboard.loadCourses()" style="background:#2563eb;color:white;border:none;padding:8px 16px;border-radius:6px;cursor:pointer">&#x1F504; Coba Lagi</button>' +
          '</div>';
      });
  },

  renderCourseCards: function (courses, skipBuildFilter) {
    var self = this;
    var grid = document.getElementById('course-grid');

    // Build filter dropdown hanya saat pertama kali load
    if (!skipBuildFilter) this.buildCategoryFilter(this.currentCourses);

    // Update counter
    var counter = document.getElementById('course-count');
    if (counter) {
      var total = this.currentCourses.length;
      var shown = courses ? courses.length : 0;
      counter.textContent = shown === total ? total + ' course' : shown + ' dari ' + total + ' course';
    }

    if (!courses || courses.length === 0) {
      grid.innerHTML = '<div class="empty-courses"><div class="icon">&#x1F50D;</div><p>Tidak ada course yang cocok dengan pencarian.<br><button onclick="Dashboard.clearFilter()" style="margin-top:10px;padding:8px 16px;background:#2563eb;color:white;border:none;border-radius:6px;cursor:pointer">&#x2715; Reset Filter</button></p></div>';
      return;
    }

    grid.innerHTML = '';
    courses.forEach(function (course) {
      var colorClass = self.getColor(course.id);
      var courseName = self._escapeHtml(course.fullname || course.shortname || '');
      var shortName = self._escapeHtml(course.shortname || '');
      var card = document.createElement('div');
      card.className = 'course-card';
      card.innerHTML =
        '<div class="course-banner ' + colorClass + '"></div>' +
        '<div class="course-body">' +
          '<div class="course-name">' + courseName + '</div>' +
          '<div class="course-short">' + shortName + '</div>' +
          '<div class="course-footer">' +
            '<span class="course-id">ID: ' + course.id + '</span>' +
            '<button class="course-btn">&#x1F50D; Monitor Ujian</button>' +
          '</div>' +
        '</div>';

      card.addEventListener('click', function () {
        self.openCourse(course);
      });
      grid.appendChild(card);
    });
  },

  // ===== BUKA COURSE -> TAMPIL QUIZ =====
  // ===== FILTER COURSE =====
  filterCourses: function () {
    var keyword  = (document.getElementById('search-course') ? document.getElementById('search-course').value : '').toLowerCase().trim();
    var category = document.getElementById('filter-course-cat') ? document.getElementById('filter-course-cat').value.toLowerCase() : '';
    var courses  = this.currentCourses;

    var filtered = courses.filter(function (c) {
      var name      = (c.fullname || c.shortname || '').toLowerCase();
      var shortname = (c.shortname || '').toLowerCase();
      var matchKey  = !keyword || name.indexOf(keyword) >= 0 || shortname.indexOf(keyword) >= 0;
      var matchCat  = !category || name.indexOf(category) >= 0;
      return matchKey && matchCat;
    });

    // Update counter
    var counter = document.getElementById('course-count');
    if (counter) {
      counter.textContent = filtered.length + ' dari ' + courses.length + ' course';
    }

    this.renderCourseCards(filtered);
  },

  clearFilter: function () {
    var inp = document.getElementById('search-course');
    var sel = document.getElementById('filter-course-cat');
    if (inp) inp.value = '';
    if (sel) sel.value = '';
    this.filterCourses();
  },

  // Isi dropdown kategori dari nama course (ambil kata pertama)
  buildCategoryFilter: function (courses) {
    var sel = document.getElementById('filter-course-cat');
    if (!sel) return;

    // Ambil kata unik dari awal nama course (misal: "US", "XI", "XII")
    var keywords = {};
    courses.forEach(function (c) {
      var name  = c.fullname || c.shortname || '';
      var parts = name.trim().split(/\s+/);
      if (parts.length > 0) {
        var key = parts[0].toUpperCase();
        if (key.length >= 2) keywords[key] = true;
      }
    });

    // Bersihkan option lama (kecuali "Semua")
    while (sel.options.length > 1) sel.remove(1);

    Object.keys(keywords).sort().forEach(function (k) {
      var opt = document.createElement('option');
      opt.value       = k.toLowerCase();
      opt.textContent = '🏷️ ' + k;
      sel.appendChild(opt);
    });
  },
  openCourse: function (course) {
    var self  = this;
    var grid  = document.getElementById('quiz-grid');

    this.currentCourseId = course.id;
    document.getElementById('quiz-page-title').textContent = course.fullname || course.shortname;
    this.showPage('page-quizzes');
    this.setHashState('quizzes', course.id);

    grid.innerHTML = '<p style="color:var(--gray-400);font-size:13px">&#x23F3; Memuat daftar ujian...</p>';

    API.getQuizzes(course.id)
      .then(function (quizzes) {
        self.currentQuizzes = quizzes;
        self.renderQuizCards(quizzes, course);
        self.restoreFromHash();
        if (self.pendingHashState && self.pendingHashState.page === 'monitor' &&
            self.pendingHashState.courseId === course.id && self.pendingHashState.quizId) {
          var quiz = self.currentQuizzes.find(function(q) { return q.id === self.pendingHashState.quizId; });
          if (quiz) {
            self.startMonitoring(quiz.id, course.id, quiz.name);
          }
          self.pendingHashState = null;
        }
      })
      .catch(function (e) {
        grid.innerHTML = '<div class="empty-courses"><div class="icon">&#x26A0;&#xFE0F;</div><p>Gagal memuat quiz: ' + e.message + '</p></div>';
      });
  },

  renderQuizCards: function (quizzes, course) {
    var self = this;
    var grid = document.getElementById('quiz-grid');

    if (!quizzes || quizzes.length === 0) {
      grid.innerHTML = '<div class="empty-courses"><div class="icon">&#x1F4DD;</div><p>Tidak ada ujian (quiz) di course ini.</p></div>';
      return;
    }

    grid.innerHTML = '';
    quizzes.forEach(function (quiz) {
      var quizName = self._escapeHtml(quiz.name || '');
      var courseName = self._escapeHtml(course.shortname || course.fullname || '');
      var card = document.createElement('div');
      card.className = 'quiz-card';
      card.innerHTML =
        '<div class="quiz-name">' + quizName + '</div>' +
        '<div class="quiz-info">&#x1F4CC; ' + courseName + '</div>' +
        '<button class="quiz-start-btn">&#x1F7E2; Mulai Monitor</button>';

      card.querySelector('.quiz-start-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        self.startMonitoring(quiz.id, course.id, quiz.name);
      });
      card.addEventListener('click', function () {
        self.startMonitoring(quiz.id, course.id, quiz.name);
      });
      grid.appendChild(card);
    });
  },

  // ===== MULAI MONITORING =====
  startMonitoring: function (quizId, courseId, quizName) {
    this.currentQuizId   = quizId;
    this.currentCourseId = courseId;
    this.currentQuizName = quizName;
    this.setHashState('monitor', courseId, quizId);
    this.allSessions     = [];
    this.allLogs         = [];
    this.knownBlockedIds     = {};
    this.knownOfflineIds     = {};
    this.knownExitRequestIds = {};
    this.appStatusMap        = {};
    this.selectedBlockedIds  = {};
    this.activeStudentsExpanded = false;
    this.sessionFetchError   = null;
    var si = document.getElementById('search-student');
    var fc = document.getElementById('filter-student-class');
    if (si) si.value = '';
    if (fc) {
      fc.innerHTML = '<option value="">&#x1F3F7;&#xFE0F; Semua kelas</option>';
    }

    var decodedName = (function(s){ var t=document.createElement('textarea'); t.innerHTML=s; return t.value; })(quizName);
    document.getElementById('quiz-title').textContent  = decodedName;
    document.getElementById('nav-badge').textContent   = decodedName;
    document.querySelectorAll('#alert-wrap .alert-item').forEach(function(e){e.remove();});

    this.showPage('page-monitor');

    // Restore tab monitor terakhir yang aktif (sessions / completed / logs)
    (function () {
      var saved;
      try { saved = localStorage.getItem('dosman_monitor_tab'); } catch (_) {}
      var tab = (saved === 'completed' || saved === 'logs') ? saved : 'sessions';
      document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
      document.querySelectorAll('.tab-panel').forEach(function (p) { p.style.display = 'none'; });
      var btn   = document.querySelector('.tab-btn[data-tab="' + tab + '"]');
      var panel = document.getElementById('tab-' + tab);
      if (btn)   btn.classList.add('active');
      if (panel) panel.style.display = 'block';
    })();

    this.fetchData();
    this.startAutoRefresh();
  },

  // ===== FETCH DATA =====
  fetchData: function () {
    var self = this;
    if (!self.currentQuizId) return;

    Promise.all([
      API.getSessions(self.currentQuizId, self.currentCourseId),
      API.getLogs(self.currentQuizId, self.currentCourseId),
      API.getAppStatus()
    ]).then(function (results) {
      self.sessionFetchError = null;
      var sessData   = results[0];
      var logData    = results[1];
      var appStatus  = results[2];
      if (appStatus && !appStatus.error) self.appStatusMap = appStatus;

      if (sessData && sessData.sessions) {
        self.checkStatusChanges(sessData.sessions);
        self.allSessions = sessData.sessions;
        self.rebuildClassFilterOptions();
        document.getElementById('stat-active').textContent  = sessData.total_active  || 0;
        document.getElementById('stat-paused').textContent  = sessData.total_paused  || 0;
        document.getElementById('stat-offline').textContent = sessData.total_offline || 0;
        var sep = document.getElementById('stat-exit-pending');
        if (sep) sep.textContent = sessData.total_exit_pending || 0;
      }
      if (logData && logData.logs) self.allLogs = logData.logs;

      self.renderSessions();
      self.renderCompleted();
      self.renderLogs();
    }).catch(function (e) {
      console.error('fetchData error:', e.message);
      self.sessionFetchError = e.message || String(e);
      self.showAlert('danger', 'Gagal memuat data monitoring',
        'Periksa izin akun (guru harus bisa melihat nilai/laporan quiz di course ini) atau jaringan. ' + self.sessionFetchError);
      self.renderSessions();
      self.renderCompleted();
      self.renderLogs();
    });
  },

  checkStatusChanges: function (sessions) {
    var self = this;
    sessions.forEach(function (s) {
      var name = s.firstname + ' ' + s.lastname;
      var sKey = s.id > 0 ? s.id : ('u' + s.userid);
      if (s.status === 'blocked' && !self.knownBlockedIds[sKey]) {
        self.knownBlockedIds[sKey] = true;
        self.showAlert('danger', '&#x1F534; DIBLOKIR', name + ' - ' + (s.blocked_reason || 'Keluar tanpa izin'));
        self.playBeep();
      }
      if (s.is_offline && s.status === 'active' && !self.knownOfflineIds[sKey]) {
        self.knownOfflineIds[sKey] = true;
        self.showAlert('warn', '&#x26AB; OFFLINE', name + ' - Tidak ada sinyal dari perangkat');
      }
      if (!s.is_offline && s.status === 'active') delete self.knownOfflineIds[sKey];
      if (s.status !== 'blocked') delete self.knownBlockedIds[sKey];

      if (s.exit_request === 'pending' && !self.knownExitRequestIds[sKey]) {
        self.knownExitRequestIds[sKey] = true;
        self.showAlert('warn', 'MINTA IZIN KELUAR', name + ' meminta izin menutup aplikasi');
        self.playBeep();
      }
      if (s.exit_request !== 'pending') delete self.knownExitRequestIds[sKey];
    });
  },

  showAlert: function (type, title, message) {
    var iconMap = { danger: 'error', warn: 'warning', success: 'success' };
    var html = message
      ? '<strong>' + title + '</strong><br><span style="font-size:0.85em;opacity:0.85">' + message + '</span>'
      : '<strong>' + title + '</strong>';
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: iconMap[type] || 'info',
      html: html,
      showConfirmButton: false,
      showCloseButton: true,
      timer: type === 'danger' ? 7000 : 4000,
      timerProgressBar: true,
    });
  },

  playBeep: function () {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var osc = ctx.createOscillator();
      osc.connect(ctx.destination);
      osc.frequency.value = 880;
      osc.start();
      osc.stop(ctx.currentTime + 0.3);
    } catch(e) {}
  },

  renderSessions: function () {
    var self     = this;
    var sessions = self.getFilteredSessions();

    var tbody = document.getElementById('sessions-tbody');
    if (self.sessionFetchError) {
      tbody.innerHTML =
        '<tr><td colspan="10" class="text-center" style="padding:24px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px">' +
        '<div style="color:#b91c1c;font-weight:700;margin-bottom:8px">&#x26A0;&#xFE0F; Gagal memuat daftar siswa</div>' +
        '<div style="color:#7f1d1d;font-size:13px;max-width:520px;margin:0 auto">' + self._escapeHtml(self.sessionFetchError) + '</div>' +
        '<div style="color:#64748b;font-size:12px;margin-top:12px">Tip: pastikan plugin Moodle di-upgrade, dan akun Anda punya peran guru (bisa nilai / kelola quiz) di course ini.</div>' +
        '</td></tr>';
      return;
    }
    if (!sessions.length) {
      var emptyMsg = !self.allSessions.length
        ? 'Belum ada siswa dalam sesi ini'
        : 'Tidak ada siswa yang cocok dengan pencarian / filter kelas — ubah filter atau klik Hapus filter';
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted" style="padding:30px">' + emptyMsg + '</td></tr>';
      self._renderActiveStudentsSummary([]);
      return;
    }

    self._renderActiveStudentsSummary(sessions);

    // Siswa terblokir/offline tampil duluan, sisanya urut normal
    var blocked  = sessions.filter(function(s) { return s.status === 'blocked' || (s.is_offline && s.status === 'active'); });
    var others   = sessions.filter(function(s) { return !(s.status === 'blocked' || (s.is_offline && s.status === 'active')); });
    var ordered  = blocked.concat(others);

    var html = '';
    var separatorAdded = false;

    ordered.forEach(function (s, i) {
      var name        = s.firstname + ' ' + s.lastname;
      var kelas       = (s.department && String(s.department).trim()) ? self._escapeHtml(String(s.department).trim()) : '<span class="text-muted">—</span>';
      var isOffline   = s.is_offline && s.status === 'active';
      var isBlocked   = s.status === 'blocked' || isOffline;
      var exitPending = (s.exit_request === 'pending');
      var statusKey   = isOffline ? 'offline' : s.status;
      var statusInfo  = CONFIG.STATUS_INFO[statusKey] || CONFIG.STATUS_INFO['active'];
      var rowClass    = isBlocked ? 'row-danger' : statusKey === 'paused' ? 'row-warning' : '';
      if (exitPending) rowClass += ' row-exit-pending';
      var dotColor    = isOffline ? 'red' : s.status === 'active' ? 'green' : s.status === 'paused' ? 'yellow' : 'gray';
      var hbText      = isOffline ? s.seconds_since_heartbeat + 'd lalu' : statusInfo.label;
      var nm          = name.replace(/'/g, "\\'");

      // Garis pemisah antara siswa terblokir dan aktif
      if (!separatorAdded && i === blocked.length && blocked.length > 0 && others.length > 0) {
        separatorAdded = true;
        html += '<tr><td colspan="10" style="padding:4px 10px;background:#f0fdf4;border-top:2px solid #86efac;border-bottom:2px solid #86efac;font-size:11px;color:#166534;font-weight:700;letter-spacing:.5px">&#x1F7E2; SISWA AKTIF</td></tr>';
      }

      // Checkbox: untuk semua siswa yang punya session record (id > 0)
      var cbCell;
      if (s.id > 0) {
        var checked = self.selectedBlockedIds[s.id] ? 'checked' : '';
        cbCell = '<td style="text-align:center"><input type="checkbox" class="session-cb" data-id="' + s.id + '" ' + checked +
                 ' onchange="Dashboard.onSessionCheckbox(this)" style="width:15px;height:15px;cursor:pointer"></td>';
      } else {
        cbCell = '<td></td>';
      }

      var btns = '';
      if (s.id === 0) {
        // Appstatus-only entry: tidak ada sesi, gunakan aksi global (lock_status)
        if (isBlocked) {
          btns = '<button class="btn btn-success btn-sm" onclick="Dashboard.unblockGlobal(' + s.userid + ',\'' + nm + '\')">&#x1F513; Buka Blokir</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.doForceLogout(' + s.userid + ',\'' + nm + '\')" style="background:#dc2626;border-color:#dc2626">&#x1F5D1; Hapus &amp; Logout</button>';
        } else if (s.status === 'paused') {
          btns = '<button class="btn btn-success btn-sm" onclick="Dashboard.resumeGlobal(' + s.userid + ',\'' + nm + '\')">&#x25B6;&#xFE0F; Lanjutkan</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.doForceLogout(' + s.userid + ',\'' + nm + '\')" style="background:#dc2626;border-color:#dc2626">&#x1F5D1; Hapus &amp; Logout</button>';
        } else {
          btns = '<button class="btn btn-warning btn-sm" onclick="Dashboard.pauseGlobal(' + s.userid + ',\'' + nm + '\')">&#x23F8;&#xFE0F; Jeda</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.blockGlobal(' + s.userid + ',\'' + nm + '\')" style="background:#dc2626;border-color:#dc2626">&#x1F6AB; Blokir</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.doForceLogout(' + s.userid + ',\'' + nm + '\')" style="background:#6b7280;border-color:#6b7280">&#x1F5D1; Hapus</button>';
        }
      } else {
        if (exitPending) {
          btns = '<button class="btn btn-success btn-sm" onclick="Dashboard.doAction(' + s.id + ',\'approve_exit\',\'' + nm + '\')">&#x2705; Setujui keluar</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.doAction(' + s.id + ',\'reject_exit\',\'' + nm + '\')">&#x274C; Tolak</button> ';
        }
        if (s.status === 'active' && !isOffline) {
          btns += '<button class="btn btn-warning btn-sm" onclick="Dashboard.doAction(' + s.id + ',\'pause\',\'' + nm + '\')">&#x23F8;&#xFE0F;</button>' +
                  '<button class="btn btn-secondary btn-sm" onclick="Dashboard.showDetail(' + s.userid + ',\'' + nm + '\')">Detail</button>';
        } else if (s.status === 'paused') {
          btns = '<button class="btn btn-success btn-sm" onclick="Dashboard.doAction(' + s.id + ',\'resume\',\'' + nm + '\')">&#x25B6;&#xFE0F;</button>' +
                 '<button class="btn btn-secondary btn-sm" onclick="Dashboard.showDetail(' + s.userid + ',\'' + nm + '\')">Detail</button>';
        } else if (isBlocked) {
          btns = '<button class="btn btn-success btn-sm" onclick="Dashboard.doAction(' + s.id + ',\'reset\',\'' + nm + '\')">&#x1F513; Buka Blokir</button>' +
                 '<button class="btn btn-danger btn-sm" onclick="Dashboard.doForceLogout(' + s.userid + ',\'' + nm + '\')" style="background:#dc2626;border-color:#dc2626">&#x1F5D1; Hapus &amp; Logout</button>' +
                 '<button class="btn btn-secondary btn-sm" onclick="Dashboard.showDetail(' + s.userid + ',\'' + nm + '\')">Detail</button>';
        } else if (s.status === 'released' || s.status === 'completed') {
          btns = '<button class="btn btn-danger btn-sm" onclick="Dashboard.doForceLogout(' + s.userid + ',\'' + nm + '\')" style="background:#dc2626;border-color:#dc2626">&#x1F5D1; Hapus dari Tabel</button>' +
                 '<button class="btn btn-secondary btn-sm" onclick="Dashboard.showDetail(' + s.userid + ',\'' + nm + '\')">Detail</button>';
        }
      }

      var violColor = s.suspicious_count >= CONFIG.DANGER_THRESHOLD ? 'color:var(--danger);font-weight:700'
                    : s.suspicious_count >= CONFIG.WARNING_THRESHOLD ? 'color:var(--warning);font-weight:700'
                    : 'color:var(--success)';

      // Indikator perangkat dengan badge yang lebih jelas
      var appInfo = self.appStatusMap[s.userid];
      var nowSec  = Math.floor(Date.now() / 1000);
      var ct      = s.client_type || 'unknown';
      var appCell;
      if (ct === 'android') {
        var byHb    = !isOffline && s.status === 'active' && (s.seconds_since_heartbeat <= 15);
        var byApp   = appInfo && appInfo.lastping && (nowSec - appInfo.lastping <= 300);
        var active  = !!(byHb || byApp);
        var dot     = active
          ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px;vertical-align:middle;flex-shrink:0"></span>'
          : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#9ca3af;margin-right:5px;vertical-align:middle;flex-shrink:0"></span>';
        appCell = '<span style="display:inline-flex;align-items:center;background:#dbeafe;color:#1d4ed8;border-radius:6px;padding:3px 7px;font-size:11px;font-weight:700;white-space:nowrap">' +
                  dot + '&#x1F4F1; Android</span>';
      } else if (ct === 'ios' || ct === 'seb') {
        appCell = '<span style="display:inline-flex;align-items:center;background:#f3e8ff;color:#6d28d9;border-radius:6px;padding:3px 7px;font-size:11px;font-weight:700;white-space:nowrap">' +
                  '&#xF8FF; iOS</span>';
      } else if (ct === 'browser') {
        appCell = '<span style="display:inline-flex;align-items:center;background:#f3f4f6;color:#374151;border-radius:6px;padding:3px 7px;font-size:11px;font-weight:600;white-space:nowrap">' +
                  '&#x1F310; Browser</span>';
      } else {
        appCell = '<span style="font-size:11px;color:#9ca3af">&#x26AB; —</span>';
      }

      html += '<tr class="' + rowClass + '">' +
        cbCell +
        '<td>' + (i+1) + '</td>' +
        '<td><strong>' + self._escapeHtml(name) + '</strong>' +
        (exitPending ? '<div style="font-size:11px;color:#c2410c;margin-top:4px;font-weight:600">&#x1F6AA; Minta izin keluar aplikasi</div>' : '') +
        (s.status === 'blocked' ? '<div class="blocked-reason">' + self._escapeHtml(s.blocked_reason||'') + '</div>' : '') + '</td>' +
        '<td style="font-size:12px;max-width:140px">' + kelas + '</td>' +
        '<td><span class="badge ' + statusInfo.badge + '">' + statusInfo.icon + ' ' + statusInfo.label + '</span></td>' +
        '<td>' + appCell + '</td>' +
        '<td><div class="heartbeat"><div class="hb-dot ' + dotColor + '"></div><span>' + hbText + '</span></div></td>' +
        '<td style="' + violColor + '">' + s.suspicious_count + 'x</td>' +
        '<td>' + s.reset_count + 'x</td>' +
        '<td><div class="action-btns">' + btns + '</div></td>' +
        '</tr>';
    });

    // Tambahkan header "SISWA TERBLOKIR" di awal jika ada yang terblokir
    if (blocked.length > 0) {
      html = '<tr><td colspan="10" style="padding:5px 10px;background:#fef2f2;border-bottom:2px solid #fca5a5;font-size:11px;color:#991b1b;font-weight:700;letter-spacing:.5px">&#x26D4; SISWA TERBLOKIR / OFFLINE (' + blocked.length + ')</td></tr>' + html;
    }

    tbody.innerHTML = html;
    self._updateSessionBulkBar();
  },

  onSessionCheckbox: function (cb) {
    var id = parseInt(cb.dataset.id, 10);
    if (cb.checked) {
      this.selectedBlockedIds[id] = true;
    } else {
      delete this.selectedBlockedIds[id];
    }
    this._updateSessionBulkBar();
  },



  selectVisibleSessions: function () {
    var self = this;
    self.selectedBlockedIds = {};
    document.querySelectorAll('.session-cb').forEach(function (cb) {
      cb.checked = true;
      var id = parseInt(cb.dataset.id, 10);
      if (id) self.selectedBlockedIds[id] = true;
    });
    self._syncSessionMasterCheckbox();
    self._updateSessionBulkBar();
  },



  selectPausedSessionsOnly: function () {
    this._selectSessionsByPredicate(function (s) {
      return s.status === 'paused';
    });
  },

  selectActiveSessionsOnly: function () {
    this._selectSessionsByPredicate(function (s) {
      return s.status === 'active' && !s.is_offline;
    });
  },

  _selectSessionsByPredicate: function (predicate) {
    var self = this;
    self.selectedBlockedIds = {};
    var mapById = {};
    self.getFilteredSessions().forEach(function (s) {
      if (s.id > 0) mapById[s.id] = s;
    });
    document.querySelectorAll('.session-cb').forEach(function (cb) {
      var id = parseInt(cb.dataset.id, 10);
      var row = mapById[id];
      var ok = !!(row && predicate(row));
      cb.checked = ok;
      if (ok) self.selectedBlockedIds[id] = true;
    });
    self._syncSessionMasterCheckbox();
    self._updateSessionBulkBar();
  },

  clearSessionSelection: function () {
    this.selectedBlockedIds = {};
    var masterCb = document.getElementById('select-all-blocked');
    if (masterCb) masterCb.checked = false;
    document.querySelectorAll('.session-cb').forEach(function (cb) { cb.checked = false; });
    this._updateSessionBulkBar();
  },

  _syncSessionMasterCheckbox: function () {
    var masterCb = document.getElementById('select-all-blocked');
    if (!masterCb) return;
    var cbs = Array.prototype.slice.call(document.querySelectorAll('.session-cb'));
    if (!cbs.length) {
      masterCb.checked = false;
      return;
    }
    var checkedCount = cbs.filter(function (cb) { return cb.checked; }).length;
    masterCb.checked = checkedCount > 0 && checkedCount === cbs.length;
  },

  _updateSessionBulkBar: function () {
    var count = Object.keys(this.selectedBlockedIds).length;
    var bar   = document.getElementById('sessions-bulk-bar');
    var el    = document.getElementById('sessions-bulk-count');
    if (!bar) return;
    if (count > 0) {
      bar.style.display = 'flex';
      if (el) el.textContent = count;
    } else {
      bar.style.display = 'none';
    }
  },

  _setSessionBulkLoading: function (on) {
    var el = document.getElementById('sessions-bulk-loading');
    if (el) el.style.display = on ? 'inline' : 'none';
  },

  _getSelectedSessionTargets: function () {
    var ids = Object.keys(this.selectedBlockedIds).map(Number);
    return this.allSessions.filter(function (s) { return ids.indexOf(s.id) >= 0; });
  },

  _bulkSelectedAction: function (title, action, predicate, successPrefix) {
    var self = this;
    var targets = self._getSelectedSessionTargets().filter(predicate);
    if (!targets.length) { Swal.fire({ icon: 'warning', title: 'Tidak Ada Siswa', text: 'Tidak ada siswa terpilih yang cocok untuk aksi ini.' }); return; }
    self.showConfirm(title, title + ' untuk ' + targets.length + ' siswa terpilih?', function () {
      self._setSessionBulkLoading(true);
      Promise.allSettled(targets.map(function (s) {
        return API.manageSession(s.id, self.currentCourseId, action).catch(function () {});
      })).then(function (results) {
        var ok = results.filter(function (r) { return r.status === 'fulfilled'; }).length;
        var fail = results.length - ok;
        self.showAlert(fail ? 'warn' : 'success', '&#x2705; Aksi Massal',
          successPrefix + ' — berhasil: ' + ok + ', gagal: ' + fail);
        self.clearSessionSelection();
        self.fetchData();
      }).finally(function () {
        self._setSessionBulkLoading(false);
      });
    });
  },

  bulkPauseSelected: function () {
    this._bulkSelectedAction(
      'Jeda Terpilih',
      'pause',
      function (s) { return s.status === 'active' && !s.is_offline; },
      'Jeda terpilih selesai'
    );
  },

  bulkResumeSelected: function () {
    this._bulkSelectedAction(
      'Lanjutkan Terpilih',
      'resume',
      function (s) { return s.status === 'paused'; },
      'Lanjutkan terpilih selesai'
    );
  },



  bulkForceLogoutSelected: function () {
    var self = this;
    var targets = self._getSelectedSessionTargets();
    if (!targets.length) { Swal.fire({ icon: 'warning', title: 'Tidak Ada Siswa', text: 'Tidak ada siswa yang dipilih.' }); return; }
    self.showConfirm('Hapus Terpilih', 'Hapus sesi & paksa logout ' + targets.length + ' siswa terpilih?', function () {
      self._setSessionBulkLoading(true);
      Promise.allSettled(targets.map(function (s) {
        var token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : '';
        return fetch('../local/dosman_ujian/force_logout_student.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ token: token, userid: s.userid })
        }).then(function (r) { return r.json(); });
      })).then(function (results) {
        var ok = results.filter(function (r) {
          return r.status === 'fulfilled' && r.value && r.value.success;
        }).length;
        var fail = results.length - ok;
        self.showAlert(fail ? 'warn' : 'success', '&#x2705; Hapus & Logout',
          'Aksi selesai — berhasil: ' + ok + ', gagal: ' + fail);
        self.clearSessionSelection();
        self.fetchData();
      }).finally(function () {
        self._setSessionBulkLoading(false);
      });
    });
  },

  bulkResetSelected: function () {
    this._bulkSelectedAction(
      'Buka Blokir Terpilih',
      'reset',
      function (s) { return s.status === 'blocked' || (s.is_offline && s.status === 'active'); },
      'Buka blokir terpilih selesai'
    );
  },

  _renderActiveStudentsSummary: function (sessions) {
    var wrap = document.getElementById('monitor-active-students');
    var countEl = document.getElementById('monitor-active-students-count');
    var listEl = document.getElementById('monitor-active-students-list');
    if (!wrap || !countEl || !listEl) return;

    var active = (sessions || []).filter(function (s) {
      return s.status === 'active' && !s.is_offline;
    });
    countEl.textContent = String(active.length);
    if (!active.length) {
      wrap.style.display = 'none';
      listEl.innerHTML = '';
      return;
    }

    var names = active.map(function (s, idx) {
      var kelas = (s.department || '').trim();
      var base = (idx + 1) + '. ' + this._escapeHtml(s.firstname) + ' ' + this._escapeHtml(s.lastname);
      return kelas ? (base + ' (' + this._escapeHtml(kelas) + ')') : base;
    }, this);
    listEl.innerHTML = names.map(function (x) { return '<div>' + x + '</div>'; }).join('');
    wrap.style.display = 'block';
    listEl.style.display = this.activeStudentsExpanded ? 'block' : 'none';
  },

  toggleActiveStudentsDetails: function () {
    this.activeStudentsExpanded = !this.activeStudentsExpanded;
    var list = document.getElementById('monitor-active-students-list');
    if (list) list.style.display = this.activeStudentsExpanded ? 'block' : 'none';
  },

  renderLogs: function () {
    var self        = this;
    var filterSus = document.getElementById('filter-suspicious').checked;
    var logs        = this.allLogs.slice();
    if (filterSus) logs = logs.filter(function(l) { return l.suspicious; });
    logs = logs.filter(function (l) { return self.matchesStudentSearch(l) && self.matchesStudentClass(l); });

    var tbody = document.getElementById('logs-tbody');
    if (!logs.length) {
      var logEmpty = !this.allLogs.length
        ? 'Belum ada log aktivitas'
        : 'Tidak ada log yang cocok dengan filter — ubah pencarian / kelas atau Hapus filter';
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding:30px">' + logEmpty + '</td></tr>';
      return;
    }

    var html = '';
    logs.slice(0, 100).forEach(function (log) {
      var info  = CONFIG.EVENT_LABELS[log.eventtype] || { label: log.eventtype, icon: '&#x1F4CC;' };
      var time  = new Date(log.timecreated * 1000).toLocaleString('id-ID');
      var badge = log.suspicious
        ? '<span class="badge badge-danger">&#x26A0;&#xFE0F; Mencurigakan</span>'
        : '<span class="badge badge-gray">&#x2705; Normal</span>';
      html += '<tr class="' + (log.suspicious ? 'row-danger' : '') + '">' +
        '<td class="text-muted" style="font-size:12px;white-space:nowrap">' + time + '</td>' +
        '<td><strong>' + self._escapeHtml(log.firstname) + ' ' + self._escapeHtml(log.lastname) + '</strong></td>' +
        '<td>' + info.icon + ' ' + info.label + '</td>' +
        '<td>' + badge + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  },

  renderCompleted: function () {
    var self = this;
    var tbody = document.getElementById('completed-tbody');
    if (!tbody) return;

    var sessions = self.getFilteredSessions().filter(function (s) {
      return s.status === 'released' || s.status === 'completed';
    });

    if (!sessions.length) {
      var emptyMsg = !self.allSessions.length
        ? 'Belum ada data sesi siswa'
        : 'Belum ada siswa yang selesai sesuai filter saat ini';
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:30px">' + emptyMsg + '</td></tr>';
      return;
    }

    var html = '';
    sessions.forEach(function (s, i) {
      var name = (s.firstname || '') + ' ' + (s.lastname || '');
      var kelas = (s.department && String(s.department).trim())
        ? self._escapeHtml(String(s.department).trim())
        : '<span class="text-muted">—</span>';
      var finalStatus = s.status === 'completed'
        ? '<span class="badge badge-success">&#x2705; Completed</span>'
        : '<span class="badge badge-gray">&#x1F513; Released</span>';
      var finishedAtTs = (s.timemodified || s.exit_processed_at || s.blocked_at || s.last_heartbeat || 0);
      var finishedAt = finishedAtTs
        ? new Date(finishedAtTs * 1000).toLocaleString('id-ID')
        : '—';
      var violStyle = s.suspicious_count > 0
        ? 'color:var(--danger);font-weight:700'
        : 'color:var(--success)';

      html += '<tr>' +
        '<td>' + (i + 1) + '</td>' +
        '<td><strong>' + self._escapeHtml(name) + '</strong></td>' +
        '<td>' + kelas + '</td>' +
        '<td>' + finalStatus + '</td>' +
        '<td style="white-space:nowrap;font-size:12px;color:var(--gray-600)">' + finishedAt + '</td>' +
        '<td style="' + violStyle + '">' + (s.suspicious_count || 0) + 'x</td>' +
        '<td>' + (s.reset_count || 0) + 'x</td>' +
      '</tr>';
    });

    tbody.innerHTML = html;
  },

  doAction: function (sessionId, action, studentName) {
    var self   = this;
    var labels = {
      pause:  { title: 'Jeda Ujian',   msg: 'Jeda ujian untuk ' + studentName + '?' },
      resume: { title: 'Lanjutkan',    msg: 'Lanjutkan sesi ' + studentName + '?' },
      reset:  { title: 'Buka Blokir',  msg: 'Buka blokir ' + studentName + '?' },
      block:  { title: 'Blokir',       msg: 'Blokir ' + studentName + '?' },
      approve_exit: { title: 'Setujui keluar', msg: 'Setujui ' + studentName + ' menutup aplikasi? Siswa bisa login lagi setelahnya.' },
      reject_exit:  { title: 'Tolak permintaan', msg: 'Tolak izin keluar untuk ' + studentName + '? Siswa melanjutkan ujian.' },
    };
    var info = labels[action] || { title: action, msg: 'Lanjutkan?' };
    self.showConfirm(info.title, info.msg, function () {
      API.manageSession(sessionId, self.currentCourseId, action)
        .then(function (result) {
          if (result && result.success) {
            self.showAlert('success', '&#x2705; Berhasil', result.message);
            if (action === 'reset') {
              delete self.selectedBlockedIds[sessionId];
              self._updateSessionBulkBar();
            }
            self.fetchData();
          } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: result ? result.message : 'Unknown error' });
          }
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  doForceLogout: function (userid, studentName) {
    var self = this;
    self.showConfirm(
      'Hapus & Logout',
      'Hapus sesi dan paksa logout ' + studentName + '?\n\nSiswa harus login ulang ke aplikasi dari awal.',
      function () {
        var token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : '';
        fetch('../local/dosman_ujian/force_logout_student.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ token: token, userid: userid })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.error || 'Unknown error' }); return; }
            self.showAlert('success', '&#x2705; Berhasil', studentName + ' telah di-logout dan sesi dihapus.');
            self.fetchData();
          })
          .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
      }
    );
  },

  _setLockGlobal: function (userid, lockStatus, confirmTitle, confirmMsg, successTitle, successMsg) {
    var self = this;
    self.showConfirm(confirmTitle, confirmMsg, function () {
      var token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : '';
      fetch('../local/dosman_ujian/set_lock_status.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ token: token, userid: userid, lock_status: lockStatus })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.success) { Swal.fire({ icon: 'error', title: 'Gagal', text: d.error || 'Unknown error' }); return; }
          self.showAlert('success', successTitle, successMsg);
          self.fetchData();
        })
        .catch(function (e) { Swal.fire({ icon: 'error', title: 'Error', text: e.message }); });
    });
  },

  unblockGlobal: function (userid, studentName) {
    this._setLockGlobal(userid, 1, 'Buka Blokir', 'Buka blokir global ' + studentName + '?',
      '&#x1F513; Berhasil', studentName + ' berhasil dibuka blokirnya.');
  },

  blockGlobal: function (userid, studentName) {
    this._setLockGlobal(userid, 2, 'Blokir Global', 'Blokir ' + studentName + ' dari semua halaman Moodle?',
      '&#x1F6AB; Diblokir', studentName + ' berhasil diblokir secara global.');
  },

  pauseGlobal: function (userid, studentName) {
    this._setLockGlobal(userid, 3, 'Jeda Ujian', 'Jeda ujian ' + studentName + '? Siswa akan melihat halaman tunggu.',
      '&#x23F8;&#xFE0F; Dijeda', studentName + ' berhasil dijeda.');
  },

  resumeGlobal: function (userid, studentName) {
    this._setLockGlobal(userid, 1, 'Lanjutkan Ujian', 'Lanjutkan ujian ' + studentName + '?',
      '&#x25B6;&#xFE0F; Dilanjutkan', studentName + ' berhasil dilanjutkan.');
  },

  bulkAction: function (action) {
    var self    = this;
    var targets = self.allSessions.filter(function(s) {
      if (s.id === 0) return false;
      if (action === 'pause')  return s.status === 'active' && !s.is_offline;
      if (action === 'resume') return s.status === 'paused';
      if (action === 'reset')  return s.status === 'blocked' || (s.is_offline && s.status === 'active');
      return false;
    });
    if (!targets.length) { Swal.fire({ icon: 'warning', title: 'Tidak Ada Siswa', text: 'Tidak ada siswa yang perlu diproses.' }); return; }
    Promise.all(targets.map(function(s) {
      return API.manageSession(s.id, self.currentCourseId, action).catch(function(){});
    })).then(function () {
      self.showAlert('success', '&#x2705; Aksi Massal', action + ' diterapkan ke ' + targets.length + ' siswa');
      self.fetchData();
    });
  },

  showDetail: function (userId, userName) {
    var self     = this;
    var logs     = self.allLogs.filter(function(l) { return l.userid === userId; });
    var session  = self.allSessions.find(function(s) { return s.userid === userId; });
    var suspicious = logs.filter(function(l) { return l.suspicious; }).length;
    var statusKey  = session ? (session.is_offline ? 'offline' : session.status) : 'active';
    var statusInfo = CONFIG.STATUS_INFO[statusKey] || CONFIG.STATUS_INFO['active'];

    document.getElementById('modal-title').textContent = userName;
    var deptLine = session && session.department
      ? '<div style="font-size:12px;color:var(--gray-400);margin-bottom:10px">&#x1F3F7;&#xFE0F; Kelas: <strong>' + self._escapeHtml(session.department) + '</strong></div>'
      : '';
    var statsHtml = deptLine + '<div class="modal-stats">' +
      '<div class="mstat"><div class="mnum" style="color:var(--primary)">' + logs.length + '</div><div class="mlbl">Total Event</div></div>' +
      '<div class="mstat"><div class="mnum" style="color:var(--danger)">' + suspicious + '</div><div class="mlbl">Pelanggaran</div></div>' +
      '<div class="mstat"><div class="mnum">' + statusInfo.icon + '</div><div class="mlbl">' + statusInfo.label + '</div></div>' +
      '<div class="mstat"><div class="mnum" style="color:var(--gray-600)">' + (session ? session.reset_count : 0) + 'x</div><div class="mlbl">Reset</div></div>' +
      '</div>';

    var logsHtml = '<div class="section-title">Log Aktivitas</div><div class="modal-log-list">';
    if (!logs.length) {
      logsHtml += '<p class="text-center text-muted" style="padding:20px">Belum ada log</p>';
    } else {
      logs.forEach(function(log) {
        var info = CONFIG.EVENT_LABELS[log.eventtype] || { label: log.eventtype, icon: '&#x1F4CC;' };
        var time = new Date(log.timecreated * 1000).toLocaleString('id-ID');
        logsHtml += '<div class="modal-log-item"><span class="mli-icon">' + info.icon + '</span>' +
          '<div class="mli-info"><div class="mli-event" style="' + (log.suspicious?'color:var(--danger)':'') + '">' + info.label + '</div>' +
          '<div class="mli-time">' + time + '</div></div></div>';
      });
    }
    logsHtml += '</div>';
    document.getElementById('modal-body').innerHTML = statsHtml + logsHtml;
    document.getElementById('modal-detail').classList.remove('modal-hidden');
  },

  showConfirm: function (title, message, onOk) {
    Swal.fire({
      title: title,
      text: message,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Ya, Lanjutkan',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
    }).then(function (result) {
      if (result.isConfirmed) onOk();
    });
  },

  hideAllModals: function () {
    document.getElementById('modal-confirm').classList.add('modal-hidden');
    document.getElementById('modal-detail').classList.add('modal-hidden');
  },

  exportCSV: function () {
    if (!this.allLogs.length) { Swal.fire({ icon: 'info', title: 'Tidak Ada Data', text: 'Tidak ada data untuk diekspor.' }); return; }
    var headers = ['Waktu','Nama Siswa','Kelas (Department)','Email','Aktivitas','Mencurigakan'];
    var rows = this.allLogs.map(function(log) {
      var info = CONFIG.EVENT_LABELS[log.eventtype] || { label: log.eventtype };
      return [
        new Date(log.timecreated*1000).toLocaleString('id-ID'),
        log.firstname+' '+log.lastname,
        (log.department || '').replace(/"/g,'""'),
        log.email,
        info.label,
        log.suspicious?'Ya':'Tidak'
      ];
    });
    var csv = [headers].concat(rows).map(function(row) {
      return row.map(function(c) { return '"' + String(c).replace(/"/g,'""') + '"'; }).join(',');
    }).join('\n');
    var blob = new Blob(['\uFEFF'+csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'dosman_' + this.currentQuizName + '_' + new Date().toLocaleDateString('id-ID') + '.csv';
    link.click();
  },

  startAutoRefresh: function () {
    var self = this;
    this.stopAutoRefresh();
    self.countdown = 10;
    self.countdownTimer = setInterval(function () {
      self.countdown--;
      var el = document.getElementById('countdown');
      if (el) el.textContent = self.countdown;
      if (self.countdown <= 0) self.countdown = 10;
    }, 1000);
    self.refreshTimer = setInterval(function () {
      self.fetchData();
      self.countdown = 10;
    }, CONFIG.REFRESH_INTERVAL);
  },

  stopAutoRefresh: function () {
    if (this.refreshTimer)   clearInterval(this.refreshTimer);
    if (this.countdownTimer) clearInterval(this.countdownTimer);
    this.refreshTimer    = null;
    this.countdownTimer  = null;
  },

  bindEvents: function () {
    var self = this;

    // Null-safe event binder
    function on(id, ev, fn) {
      var el = document.getElementById(id);
      if (el) el.addEventListener(ev, fn);
    }

    // Logout & username
    on('logout-btn', 'click', function () {
      Swal.fire({
        title: 'Keluar dari Dashboard?',
        text: 'Sesi Anda akan diakhiri.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Keluar',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
      }).then(function (res) { if (res.isConfirmed) Auth.logout(); });
    });
    var navUser = document.getElementById('nav-user');
    if (navUser) navUser.textContent = Auth.getUsername();

    // Tabs
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.tab-panel').forEach(function(p) { p.style.display = 'none'; });
        var panel = document.getElementById('tab-' + this.dataset.tab);
        if (panel) panel.style.display = 'block';
        try { localStorage.setItem('dosman_monitor_tab', this.dataset.tab); } catch (_) {}
      });
    });
    document.querySelectorAll('.admin-tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var tabId = this.getAttribute('data-tab');
        if (typeof AdminCourseTabs !== 'undefined' && AdminCourseTabs.open) {
          AdminCourseTabs.open(tabId);
        }
      });
    });

    // Filter & cari siswa
    on('filter-suspicious', 'change', function () { self.renderLogs(); });
    on('search-student',    'input',  function () { self.renderSessions(); self.renderLogs(); });
    on('filter-student-class', 'change', function () { self.renderSessions(); self.renderLogs(); });
    on('btn-clear-monitor-filters', 'click', function () { self.clearMonitorFilters(); });
    on('search-course', 'input', function () { self.filterCourses(); });
    on('filter-course-cat', 'change', function () { self.filterCourses(); });

    // Tombol monitor
    on('btn-refresh',    'click', function () { if (self.currentQuizId) self.fetchData(); });
    on('btn-export',     'click', function () { self.exportCSV(); });
    on('btn-pause-all',  'click', function () {
      self.showConfirm('Jeda Semua', 'Jeda semua siswa aktif?', function () { self.bulkAction('pause'); });
    });
    on('btn-resume-all', 'click', function () {
      self.showConfirm('Lanjutkan Semua', 'Lanjutkan semua sesi yang dijeda?', function () { self.bulkAction('resume'); });
    });
    on('btn-reset-all',  'click', function () {
      self.showConfirm('Reset Semua', 'Reset semua blokir?', function () { self.bulkAction('reset'); });
    });

    // Modal detail
    on('modal-close', 'click', function () {
      document.getElementById('modal-detail').classList.add('modal-hidden');
    });
    var modalDetail = document.getElementById('modal-detail');
    if (modalDetail) modalDetail.addEventListener('click', function (e) {
      if (e.target === this) this.classList.add('modal-hidden');
    });

    // Modal konfirmasi
    on('confirm-close',  'click', function () { document.getElementById('modal-confirm').classList.add('modal-hidden'); });
    on('confirm-cancel', 'click', function () { document.getElementById('modal-confirm').classList.add('modal-hidden'); });
    on('select-all-blocked', 'change', function (e) {
      if (e.target.checked) self.selectVisibleSessions();
      else self.clearSessionSelection();
    });
  }
};

var AdminCourseTabs = {
  current: 'login',
  storageKey: 'dosman_admin_course_tab',
  tabs: ['security', 'login', 'courses'],
  visibleTabs: ['security', 'login'],
  loginDataInitialized: false,

  getSaved: function () {
    try {
      var saved = localStorage.getItem(this.storageKey);
      if (this.visibleTabs.indexOf(saved) >= 0) return saved;
    } catch (_) {}
    return 'login';
  },

  open: function (tabId) {
    var desired = tabId;
    if (this.visibleTabs.indexOf(desired) < 0) desired = this.getSaved();
    this.current = this.visibleTabs.indexOf(desired) >= 0 ? desired : 'login';
    try { localStorage.setItem(this.storageKey, this.current); } catch (_) {}

    this.tabs.forEach(function (id) {
      var panel = document.getElementById('admin-tab-panel-' + id);
      var btn = document.getElementById('admin-tab-btn-' + id);
      var active = id === AdminCourseTabs.current;
      if (panel) {
        panel.style.display = active ? 'block' : 'none';
        panel.classList.toggle('active', active);
      }
      if (btn) {
        btn.classList.toggle('active', active);
        btn.style.background = active ? '#2563eb' : '#f3f4f6';
        btn.style.color = active ? '#fff' : '#374151';
        btn.style.borderColor = active ? '#2563eb' : '#d1d5db';
      }
    });

    if (this.current === 'login' && typeof LoginStatus !== 'undefined') {
      // Selalu netralisir filter pencarian saat masuk tab login (hindari autofill "admin").
      LoginStatus.forceResetSearch();
      if (!this.loginDataInitialized) {
        // Setup satu kali: suspended, device-blocked, auto-refresh timer
        LoginStatus.loadSuspended();
        LoginStatus.loadDeviceBlocked();
        LoginStatus._startDeviceBlockedAutoRefresh();
        this.loginDataInitialized = true;
      }
      // Selalu fetch data baru saat tab dibuka — siswa baru login langsung terlihat
      LoginStatus.load();
      setTimeout(function () { LoginStatus.forceResetSearch(); }, 250);

      // Tampilkan sub-halaman login terakhir yang dibuka (default: Sedang Kuis)
      if (typeof Dashboard !== 'undefined' && Dashboard.showPage) {
        var savedSub;
        try { savedSub = localStorage.getItem('dosman_login_subpage'); } catch (_) {}
        var subpageMap = { quiz: 'page-login-quiz', active: 'page-login-active', suspended: 'page-login-suspended' };
        Dashboard.showPage(subpageMap[savedSub] || 'page-login-quiz');
      }
    }

    if (this.current === 'security') {
      if (typeof SebPwd !== 'undefined')       SebPwd.load();
      if (typeof SebQRHistory !== 'undefined') SebQRHistory.load();
      if (typeof SebLog !== 'undefined')       SebLog.load();
    }

    // Saat berada di halaman courses, sinkronkan tab ke hash URL agar bisa dibookmark.
    var hash = (window.location.hash || '').replace('#', '');
    var page = hash.split('?')[0] || 'courses';
    if (page === 'courses' && typeof Dashboard !== 'undefined' && Dashboard.setHashState) {
      Dashboard.setHashState('courses', null, null, this.current);
    }
  }
};

document.addEventListener('DOMContentLoaded', function () {
  Dashboard.hideAllModals();
  if (window.Notification && Notification.permission === 'default') Notification.requestPermission();
  var state = Dashboard.parseHashState();
  AdminCourseTabs.open(state.adminTab || undefined);
  Dashboard.init();
});

// Pastikan modal tertutup saat halaman di-restore dari back/forward cache
window.addEventListener('pageshow', function (e) {
  if (e.persisted) Dashboard.hideAllModals();
});

