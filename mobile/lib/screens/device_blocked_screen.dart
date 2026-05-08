import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/device_service.dart';
import '../services/exam_service.dart';

class DeviceBlockedScreen extends StatefulWidget {
  const DeviceBlockedScreen({
    super.key,
    required this.reason,
    this.blockedAt = 0,
  });
  final String reason;
  final int blockedAt;

  @override
  State<DeviceBlockedScreen> createState() => _DeviceBlockedScreenState();
}

class _DeviceBlockedScreenState extends State<DeviceBlockedScreen> {
  Timer? _pollTimer;
  String _deviceId = '';

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    _deviceId = await DeviceService.getDeviceId();
    _pollTimer = Timer.periodic(const Duration(seconds: 15), (_) => _poll());
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    super.dispose();
  }

  Future<void> _poll() async {
    if (_deviceId.isEmpty || !mounted) return;
    final result = await ExamService.checkDevice(_deviceId);
    if (!mounted) return;
    if (!result.blocked) {
      Navigator.of(context).pushNamedAndRemoveUntil('/home', (route) => false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 48),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // ── Ikon ─────────────────────────────────────────────────────
                Container(
                  width: 100,
                  height: 100,
                  decoration: BoxDecoration(
                    color: const Color(0xFF7F1D1D).withValues(alpha: 0.6),
                    borderRadius: BorderRadius.circular(24),
                  ),
                  child: const Center(
                    child: Icon(
                      Icons.phonelink_lock_rounded,
                      color: Color(0xFFEF4444),
                      size: 52,
                    ),
                  ),
                ),
                const SizedBox(height: 28),

                // ── Judul ─────────────────────────────────────────────────────
                const Text(
                  'AKSES DIBLOKIR',
                  style: TextStyle(
                    color: Color(0xFFEF4444),
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 2,
                  ),
                ),
                const SizedBox(height: 16),

                // ── Alasan ────────────────────────────────────────────────────
                Text(
                  widget.reason.isNotEmpty
                      ? widget.reason
                      : 'Perangkat ini telah diblokir karena terdeteksi pelanggaran ujian.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Colors.white70,
                    fontSize: 14,
                    height: 1.6,
                  ),
                ),
                const SizedBox(height: 28),

                // ── Info box ──────────────────────────────────────────────────
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: const Color(0xFF1E293B),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFF334155)),
                  ),
                  child: const Column(
                    children: [
                      Icon(Icons.support_agent_rounded,
                          color: Color(0xFF64748B), size: 22),
                      SizedBox(height: 10),
                      Text(
                        'Hubungi pengawas ujian untuk informasi lebih lanjut.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: Color(0xFF94A3B8),
                          fontSize: 13,
                          height: 1.5,
                        ),
                      ),
                      SizedBox(height: 6),
                      Text(
                        'Jika blokir sudah dibuka oleh pengawas, '
                        'aplikasi akan otomatis diarahkan ke halaman utama.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: Color(0xFF64748B),
                          fontSize: 11,
                          height: 1.5,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ── Status polling ────────────────────────────────────────────
                const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    SizedBox(
                      width: 13,
                      height: 13,
                      child: CircularProgressIndicator(
                        strokeWidth: 1.5,
                        color: Color(0xFF475569),
                      ),
                    ),
                    SizedBox(width: 10),
                    Text(
                      'Memantau status dari server...',
                      style: TextStyle(
                          color: Color(0xFF475569), fontSize: 11),
                    ),
                  ],
                ),
                const SizedBox(height: 36),

                // ── Tombol Keluar ─────────────────────────────────────────────
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () async => SystemNavigator.pop(),
                    icon: const Icon(Icons.exit_to_app_rounded, size: 18),
                    label: const Text(
                      'Keluar Aplikasi',
                      style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: const Color(0xFF94A3B8),
                      side: const BorderSide(color: Color(0xFF334155)),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
