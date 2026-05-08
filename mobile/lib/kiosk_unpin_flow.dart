// ===========================
// Alur password setelah siswa melepas screen pin (Recent + Back).
// Dipanggil dari native lewat MethodChannel.
// ===========================

import 'package:flutter/material.dart';
import 'services/kiosk_controller.dart';
import 'widgets/exit_password_dialog.dart';

Future<void> handleKioskUnpinPasswordRequest() async {
  final ctx = KioskController.instance.rootNavigatorKey?.currentContext;
  if (ctx == null || !ctx.mounted) {
    await KioskController.instance.ackUnpinPassword(ok: false);
    return;
  }

  final fetch = await KioskController.instance.fetchExitPassword();
  if (!ctx.mounted) {
    await KioskController.instance.ackUnpinPassword(ok: false);
    return;
  }

  if (!fetch.success) {
    ScaffoldMessenger.of(ctx).showSnackBar(
      const SnackBar(
        content: Text(
          'Tidak dapat memuat password keluar. Periksa koneksi lalu coba lagi.',
        ),
        backgroundColor: Color(0xFFD97706),
        duration: Duration(seconds: 5),
      ),
    );
    await KioskController.instance.ackUnpinPassword(ok: false);
    return;
  }

  if (fetch.password.isEmpty) {
    await KioskController.instance.unlock();
    await KioskController.instance.ackUnpinPassword(ok: true);
    return;
  }

  final pwdOk = await showDialog<bool>(
    context: ctx,
    barrierDismissible: false,
    builder: (_) => const ExitPasswordDialog(forUnpinScreen: true),
  );

  if (pwdOk == true) {
    await KioskController.instance.unlock();
    await KioskController.instance.ackUnpinPassword(ok: true);
    return;
  }

  await KioskController.instance.ackUnpinPassword(ok: false);
  if (ctx.mounted && pwdOk == false) {
    ScaffoldMessenger.of(ctx).showSnackBar(
      SnackBar(
        content: const Row(
          children: [
            Icon(Icons.error_outline, color: Colors.white, size: 18),
            SizedBox(width: 8),
            Expanded(
              child: Text(
                'Kata sandi salah. Aplikasi akan disematkan kembali.',
                style: TextStyle(fontSize: 13),
              ),
            ),
          ],
        ),
        backgroundColor: const Color(0xFFDC2626),
        behavior: SnackBarBehavior.floating,
        margin: const EdgeInsets.all(16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        duration: const Duration(seconds: 4),
      ),
    );
  }
}
