// ===========================
// Banner: kiosk aktif tetapi aplikasi belum dalam mode screen pin (lock task).
// Meminta siswa menyematkan lagi + tombol untuk memicu dialog dari native.
// Juga menampilkan overlay bawah sementara untuk menutup toast Android
// "To unpin this app..." yang muncul saat screen pinning diaktifkan.
// ===========================

import 'dart:async';

import 'package:flutter/material.dart';

import '../services/kiosk_controller.dart';

/// Bungkus konten app; menampel banner jika kiosk aktif dan pin belum aktif.
class PinReminderLayer extends StatefulWidget {
  final Widget child;

  const PinReminderLayer({required this.child, super.key});

  @override
  State<PinReminderLayer> createState() => _PinReminderLayerState();
}

class _PinReminderLayerState extends State<PinReminderLayer>
    with WidgetsBindingObserver {
  final _kiosk = KioskController.instance;
  Timer? _pollTimer;
  Timer? _maskTimer;
  bool _pinned = true;
  bool _showBottomMask = false;
  bool _wasActive = false;

  @override
  void initState() {
    super.initState();
    _wasActive = _kiosk.active;
    _kiosk.addListener(_onKioskChanged);
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _syncPolling());
  }

  @override
  void dispose() {
    _kiosk.removeListener(_onKioskChanged);
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    _maskTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Setiap kali Home ditekan saat pinned → Android tampilkan toast → tutup dengan mask
    if (_kiosk.active &&
        (state == AppLifecycleState.inactive ||
         state == AppLifecycleState.paused)) {
      _triggerBottomMask();
    }
  }

  void _onKioskChanged() {
    // Tampilkan mask saat kiosk baru aktif (login berhasil → enterLockTask)
    if (_kiosk.active && !_wasActive) {
      _triggerBottomMask();
    }
    _wasActive = _kiosk.active;
    _syncPolling();
  }

  /// Tampilkan overlay bawah selama 5 detik untuk menutup toast Android.
  void _triggerBottomMask() {
    _maskTimer?.cancel();
    if (mounted) setState(() => _showBottomMask = true);
    _maskTimer = Timer(const Duration(seconds: 5), () {
      if (mounted) setState(() => _showBottomMask = false);
    });
  }

  void _syncPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
    if (!_kiosk.active) {
      if (mounted && !_pinned) setState(() => _pinned = true);
      return;
    }
    _pollPinned();
    _pollTimer = Timer.periodic(const Duration(milliseconds: 800), (_) {
      _pollPinned();
    });
  }

  Future<void> _pollPinned() async {
    final ok = await _kiosk.isLockTaskPinned();
    if (!mounted) return;
    if (ok != _pinned) setState(() => _pinned = ok);
  }

  Future<void> _onTapPinAgain() async {
    _triggerBottomMask(); // tutup toast re-pin
    await _kiosk.requestScreenPinAgain();
    await Future<void>.delayed(const Duration(milliseconds: 400));
    if (mounted) await _pollPinned();
  }

  @override
  Widget build(BuildContext context) {
    final showBanner = _kiosk.active && !_pinned;
    final bottomPadding = MediaQuery.of(context).padding.bottom;

    return Stack(
      fit: StackFit.expand,
      clipBehavior: Clip.none,
      children: [
        widget.child,

        // Banner atas: minta siswa sematkan ulang jika pin lepas
        if (showBanner)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: Material(
                color: const Color(0xFF1E40AF),
                elevation: 8,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.push_pin, color: Colors.white, size: 22),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              'Aplikasi harus disematkan (App pinned). '
                              'Ikuti petunjuk di layar Android lalu ketuk Sematkan / Pin. '
                              'Jika dialog tidak muncul, ketuk tombol di bawah.',
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: 0.98),
                                fontSize: 13,
                                height: 1.45,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton.tonal(
                          onPressed: _onTapPinAgain,
                          style: FilledButton.styleFrom(
                            foregroundColor: const Color(0xFF1E3A8A),
                            backgroundColor: Colors.white,
                          ),
                          child: const Text(
                            'Minta sematkan lagi',
                            style: TextStyle(fontWeight: FontWeight.w700),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),

        // Overlay bawah: tutup toast Android "To unpin this app..." selama 5 detik
        if (_showBottomMask)
          Positioned(
            bottom: 0,
            left: 0,
            right: 0,
            child: Container(
              height: 100 + bottomPadding,
              color: const Color(0xFF0F172A),
              alignment: Alignment.topCenter,
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
              child: const Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.warning_amber_rounded, color: Color(0xFFFBBF24), size: 20),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Siswa tidak diperkenankan keluar dari aplikasi selama ujian berlangsung. '
                      'Keluar dari aplikasi akan menyebabkan perangkat Anda diblokir secara otomatis.',
                      style: TextStyle(
                        color: Color(0xFFCBD5E1),
                        fontSize: 12,
                        height: 1.5,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
