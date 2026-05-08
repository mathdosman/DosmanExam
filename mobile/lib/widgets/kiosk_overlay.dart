// ===========================
// DOSMAN UJIAN - Kiosk Overlay
// Tombol "Keluar" mengambang — OverlayEntry agar bisa di-tap di atas WebView.
// Dialog password pakai context dari OverlayEntry yang sudah di dalam Navigator.
// ===========================

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/kiosk_controller.dart';
import 'exit_password_dialog.dart';

class KioskOverlay extends StatefulWidget {
  final Widget child;
  const KioskOverlay({required this.child, super.key});

  @override
  State<KioskOverlay> createState() => _KioskOverlayState();
}

class _KioskOverlayState extends State<KioskOverlay> {
  final _kiosk = KioskController.instance;
  OverlayEntry? _entry;

  @override
  void initState() {
    super.initState();
    _kiosk.addListener(_onChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) => _syncOverlay());
  }

  @override
  void dispose() {
    _kiosk.removeListener(_onChanged);
    _entry?.remove();
    _entry = null;
    super.dispose();
  }

  void _onChanged() {
    if (!mounted) return;
    _syncOverlay();
  }

  // Floating button dinonaktifkan — tombol keluar kini ada di navbar
  bool get _showButton => false;

  void _syncOverlay() {
    if (_showButton) {
      if (_entry == null) {
        _entry = OverlayEntry(
          // context (ctx) di sini sudah di dalam Navigator — aman untuk showDialog
          builder: (ctx) => _FloatingExitButton(
            overlayContext: ctx,
            onExit: () {
              _entry?.remove();
              _entry = null;
            },
          ),
        );
        Overlay.of(context).insert(_entry!);
      } else {
        _entry!.markNeedsBuild();
      }
    } else {
      _entry?.remove();
      _entry = null;
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

// ── Tombol mengambang ─────────────────────────────────────────────────────────

class _FloatingExitButton extends StatefulWidget {
  final BuildContext overlayContext;
  final VoidCallback onExit;

  const _FloatingExitButton({
    required this.overlayContext,
    required this.onExit,
  });

  @override
  State<_FloatingExitButton> createState() => _FloatingExitButtonState();
}

class _FloatingExitButtonState extends State<_FloatingExitButton> {
  Future<void> _onPressed() async {
    final fetch = await KioskController.instance.fetchExitPassword();
    if (!context.mounted) return;

    if (!fetch.success) {
      ScaffoldMessenger.of(widget.overlayContext).showSnackBar(
        const SnackBar(
          content: Text(
            'Tidak dapat memuat pengaturan password keluar. '
            'Periksa koneksi atau coba lagi.',
          ),
          backgroundColor: Color(0xFFD97706),
          duration: Duration(seconds: 5),
        ),
      );
      return;
    }

    // Hanya jika server mengonfirmasi tidak ada password → keluar tanpa dialog
    if (fetch.password.isEmpty) {
      await KioskController.instance.unlock();
      widget.onExit();
      await SystemNavigator.pop();
      return;
    }

    // Ada password → tampilkan dialog input
    final pwdOk = await showDialog<bool>(
      context: widget.overlayContext,
      barrierDismissible: false,
      builder: (_) => const ExitPasswordDialog(),
    );

    if (!context.mounted) return;
    if (pwdOk == null) return; // Batal

    if (!pwdOk) {
      ScaffoldMessenger.of(widget.overlayContext).showSnackBar(
        SnackBar(
          content: const Row(
            children: [
              Icon(Icons.error_outline, color: Colors.white, size: 18),
              SizedBox(width: 8),
              Text('Kata sandi salah. Coba lagi.',
                  style: TextStyle(fontSize: 13)),
            ],
          ),
          backgroundColor: const Color(0xFFDC2626),
          behavior: SnackBarBehavior.floating,
          margin: const EdgeInsets.all(16),
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10)),
          duration: const Duration(seconds: 3),
        ),
      );
      return;
    }

    // Password benar — lepas kunci, hapus tombol, tutup app
    await KioskController.instance.unlock();
    widget.onExit();
    await SystemNavigator.pop();
  }

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.of(context).padding.top + 6;
    return Positioned(
      top: top,
      right: 10,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: _onPressed,
          borderRadius: BorderRadius.circular(20),
          child: Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
            decoration: BoxDecoration(
              color: const Color(0xFFDC2626).withValues(alpha: 0.92),
              borderRadius: BorderRadius.circular(20),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.28),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.logout, color: Colors.white, size: 14),
                SizedBox(width: 5),
                Text(
                  'Keluar',
                  style: TextStyle(
                    color:         Colors.white,
                    fontSize:      12,
                    fontWeight:    FontWeight.w700,
                    letterSpacing: 0.2,
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
