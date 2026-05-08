class Quiz {
  final int id;
  final int coursemodule;
  final int course;
  final String name;
  final String intro;
  final int timeopen;
  final int timeclose;
  final int timelimit;
  final int attempts;
  final double gradepass;
  final double grade;
  final bool visible;

  Quiz({
    required this.id,
    required this.coursemodule,
    required this.course,
    required this.name,
    this.intro = '',
    this.timeopen = 0,
    this.timeclose = 0,
    this.timelimit = 0,
    this.attempts = 0,
    this.gradepass = 0,
    this.grade = 0,
    this.visible = true,
  });

  factory Quiz.fromJson(Map<String, dynamic> json) {
    return Quiz(
      id:           _toInt(json['id']),
      coursemodule: _toInt(json['coursemodule']),
      course:       _toInt(json['course']),
      name:         json['name']          as String? ?? '',
      intro:        _stripHtml(json['intro'] as String? ?? ''),
      timeopen:     _toInt(json['timeopen']),
      timeclose:    _toInt(json['timeclose']),
      timelimit:    _toInt(json['timelimit']),
      attempts:     _toInt(json['attempts']),
      gradepass:    _toDouble(json['gradepass']),
      grade:        _toDouble(json['grade']),
      visible:      _toBool(json['visible'], defaultValue: true),
    );
  }

  static int _toInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value.trim()) ?? 0;
    return 0;
  }

  static double _toDouble(dynamic value) {
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value.trim()) ?? 0;
    return 0;
  }

  static bool _toBool(dynamic value, {bool defaultValue = false}) {
    if (value == null) return defaultValue;
    if (value is bool) return value;
    if (value is num) return value != 0;
    if (value is String) {
      final normalized = value.trim().toLowerCase();
      if (normalized == '1' || normalized == 'true' || normalized == 'yes') {
        return true;
      }
      if (normalized == '0' || normalized == 'false' || normalized == 'no') {
        return false;
      }
    }
    return defaultValue;
  }

  static String _stripHtml(String html) {
    return html
        .replaceAll(RegExp(r'<[^>]*>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&quot;', '"')
        .trim();
  }

  /// URL halaman quiz di Moodle (view quiz sebelum attempt)
  String quizUrl(String moodleUrl) =>
      '$moodleUrl/mod/quiz/view.php?id=$coursemodule';

  /// Apakah quiz sedang dalam periode buka
  bool get isOpen {
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    final openOk  = timeopen  == 0 || now >= timeopen;
    final closeOk = timeclose == 0 || now <= timeclose;
    return openOk && closeOk;
  }

  /// Format durasi timelimit menjadi string (misal "90 menit")
  String get timeLimitLabel {
    if (timelimit <= 0) return 'Tidak ada batas waktu';
    final minutes = timelimit ~/ 60;
    final seconds = timelimit % 60;
    if (seconds == 0) return '$minutes menit';
    return '$minutes menit $seconds detik';
  }

  /// Format waktu buka / tutup
  String get scheduleLabel {
    if (timeopen == 0 && timeclose == 0) return 'Selalu tersedia';
    String label = '';
    if (timeopen > 0) {
      final dt = DateTime.fromMillisecondsSinceEpoch(timeopen * 1000);
      label += 'Buka: ${_formatDate(dt)}';
    }
    if (timeclose > 0) {
      final dt = DateTime.fromMillisecondsSinceEpoch(timeclose * 1000);
      if (label.isNotEmpty) label += ' · ';
      label += 'Tutup: ${_formatDate(dt)}';
    }
    return label;
  }

  String _formatDate(DateTime dt) {
    final d = dt.day.toString().padLeft(2, '0');
    final mo = dt.month.toString().padLeft(2, '0');
    final h  = dt.hour.toString().padLeft(2, '0');
    final mi = dt.minute.toString().padLeft(2, '0');
    return '$d/$mo/${dt.year} $h:$mi';
  }

  @override
  String toString() => 'Quiz(id: $id, name: $name, cmid: $coursemodule)';
}
