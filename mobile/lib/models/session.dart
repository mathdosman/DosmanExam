// ===========================
// DOSMAN UJIAN - Session Models
// ===========================

/// Hasil dari register_session API
class RegisterSessionResult {
  final bool success;
  final String status; // active, paused, blocked, error
  final String message;
  final int sessionId;

  RegisterSessionResult({
    required this.success,
    required this.status,
    required this.message,
    required this.sessionId,
  });

  factory RegisterSessionResult.fromJson(Map<String, dynamic> json) {
    return RegisterSessionResult(
      success:   json['success'] as bool? ?? false,
      status:    json['status']  as String? ?? 'error',
      message:   json['message'] as String? ?? '',
      sessionId: (json['session_id'] as num?)?.toInt() ?? 0,
    );
  }

  bool get isActive    => success && status == 'active';
  bool get isPaused    => status == 'paused';
  bool get isBlocked   => status == 'blocked';
  bool get isSuspended => status == 'suspended';
}

/// Hasil dari heartbeat API
class HeartbeatResult {
  final bool success;
  final String status; // active, paused, blocked, exit_approved, exit_rejected, error
  final String message;
  /// none | pending | approved | rejected (dari server)
  final String exitRequest;

  HeartbeatResult({
    required this.success,
    required this.status,
    required this.message,
    this.exitRequest = 'none',
  });

  factory HeartbeatResult.fromJson(Map<String, dynamic> json) {
    return HeartbeatResult(
      success:      json['success'] as bool? ?? false,
      status:       json['status']  as String? ?? 'error',
      message:      json['message'] as String? ?? '',
      exitRequest:  json['exit_request'] as String? ?? 'none',
    );
  }

  factory HeartbeatResult.error(String message) {
    return HeartbeatResult(
      success: false,
      status:  'error',
      message: message,
    );
  }

  bool get isActive       => success && status == 'active';
  bool get isPaused       => status == 'paused';
  bool get isBlocked      => status == 'blocked';
  bool get isSuspended    => status == 'suspended';
  bool get isExitApproved => success && status == 'exit_approved';
  bool get isExitRejected => success && status == 'exit_rejected';
}

/// Data sesi siswa (dari get_sessions — dipakai dashboard, tapi berguna juga di app)
class Session {
  final int id;
  final int userid;
  final String firstname;
  final String lastname;
  final String email;
  final int quizid;
  final int courseid;
  final String status;
  final bool isOffline;
  final int lastHeartbeat;
  final int secondsSinceHeartbeat;
  final String blockedReason;
  final int blockedAt;
  final int pauseGrantedAt;
  final int resetCount;
  final int suspiciousCount;
  final int timecreated;
  final String exitRequest;
  final int exitRequestedAt;
  final int exitProcessedAt;

  Session({
    required this.id,
    required this.userid,
    required this.firstname,
    required this.lastname,
    required this.email,
    required this.quizid,
    required this.courseid,
    required this.status,
    this.isOffline           = false,
    this.lastHeartbeat       = 0,
    this.secondsSinceHeartbeat = 0,
    this.blockedReason       = '',
    this.blockedAt           = 0,
    this.pauseGrantedAt      = 0,
    this.resetCount          = 0,
    this.suspiciousCount     = 0,
    this.timecreated         = 0,
    this.exitRequest         = 'none',
    this.exitRequestedAt     = 0,
    this.exitProcessedAt     = 0,
  });

  factory Session.fromJson(Map<String, dynamic> json) {
    return Session(
      id:                    (json['id']                       as num?)?.toInt() ?? 0,
      userid:                (json['userid']                   as num?)?.toInt() ?? 0,
      firstname:              json['firstname']                as String? ?? '',
      lastname:               json['lastname']                 as String? ?? '',
      email:                  json['email']                    as String? ?? '',
      quizid:                (json['quizid']                   as num?)?.toInt() ?? 0,
      courseid:              (json['courseid']                 as num?)?.toInt() ?? 0,
      status:                 json['status']                   as String? ?? 'active',
      isOffline:              json['is_offline']               as bool? ?? false,
      lastHeartbeat:         (json['last_heartbeat']           as num?)?.toInt() ?? 0,
      secondsSinceHeartbeat: (json['seconds_since_heartbeat']  as num?)?.toInt() ?? 0,
      blockedReason:          json['blocked_reason']           as String? ?? '',
      blockedAt:             (json['blocked_at']               as num?)?.toInt() ?? 0,
      pauseGrantedAt:        (json['pause_granted_at']         as num?)?.toInt() ?? 0,
      resetCount:            (json['reset_count']              as num?)?.toInt() ?? 0,
      suspiciousCount:       (json['suspicious_count']         as num?)?.toInt() ?? 0,
      timecreated:           (json['timecreated']              as num?)?.toInt() ?? 0,
      exitRequest:            json['exit_request']             as String? ?? 'none',
      exitRequestedAt:       (json['exit_requested_at']        as num?)?.toInt() ?? 0,
      exitProcessedAt:       (json['exit_processed_at']        as num?)?.toInt() ?? 0,
    );
  }

  String get fullname => '$firstname $lastname'.trim();

  bool get isActive    => status == 'active' && !isOffline;
  bool get isPaused    => status == 'paused';
  bool get isBlocked   => status == 'blocked';
  bool get isCompleted => status == 'completed';

  @override
  String toString() => 'Session(id: $id, user: $fullname, status: $status)';
}
