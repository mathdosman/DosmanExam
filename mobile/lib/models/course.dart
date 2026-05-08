class Course {
  final int id;
  final String fullname;
  final String shortname;
  final int categoryid;
  final String categoryname;
  final String summary;
  final int timecreated;
  final int timemodified;
  final bool visible;

  Course({
    required this.id,
    required this.fullname,
    required this.shortname,
    required this.categoryid,
    this.categoryname = '',
    this.summary = '',
    this.timecreated = 0,
    this.timemodified = 0,
    this.visible = true,
  });

  factory Course.fromJson(Map<String, dynamic> json) {
    return Course(
      id: _toInt(json['id']),
      fullname: json['fullname'] as String? ?? '',
      shortname: json['shortname'] as String? ?? '',
      categoryid: _toInt(json['categoryid']),
      categoryname: json['categoryname'] as String? ?? '',
      summary: _stripHtml(json['summary'] as String? ?? ''),
      timecreated: _toInt(json['timecreated']),
      timemodified: _toInt(json['timemodified']),
      visible: _toBool(json['visible'], defaultValue: true),
    );
  }

  static int _toInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value.trim()) ?? 0;
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

  /// Strip HTML tags from summary
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

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'fullname': fullname,
      'shortname': shortname,
      'categoryid': categoryid,
      'categoryname': categoryname,
      'summary': summary,
      'timecreated': timecreated,
      'timemodified': timemodified,
      'visible': visible ? 1 : 0,
    };
  }

  @override
  String toString() => 'Course(id: $id, fullname: $fullname)';
}
