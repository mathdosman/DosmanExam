import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/kiosk_controller.dart';

class DndPermissionScreen extends StatefulWidget {
  final String nextRoute;
  const DndPermissionScreen({super.key, required this.nextRoute});

  @override
  State<DndPermissionScreen> createState() => _DndPermissionScreenState();
}

class _DndPermissionScreenState extends State<DndPermissionScreen>
    with WidgetsBindingObserver {
  bool _opening  = false;
  // true setelah user setidaknya sekali mencoba buka Settings.
  // Dipakai untuk memunculkan tombol bypass jika deteksi izin gagal (mis. Vivo FuntouchOS).
  bool _attempted = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Jika DND sudah granted (misal: dibuka ulang), langsung lanjut tanpa tampil UI.
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkAndProceed());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _attempted) {
      _checkAndProceed();
      // Setelah kembali dari Settings, tampilkan tombol bypass
      // agar HP yang API-nya broken (mis. Vivo) tetap bisa lanjut.
      if (mounted) setState(() {});
    }
  }

  Future<void> _checkAndProceed() async {
    final granted = await KioskController.instance.isDndPermissionGranted();
    if (granted && mounted) {
      Navigator.of(context).pushReplacementNamed(widget.nextRoute);
    }
  }

  Future<void> _requestPermission() async {
    setState(() => _opening = true);
    final opened = await KioskController.instance.openDndSettings();
    if (!mounted) return;
    setState(() {
      _opening  = false;
      _attempted = true;
    });
    if (!opened) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Tidak bisa membuka Pengaturan secara otomatis.\n'
            'Buka Pengaturan HP → Notifikasi → Akses DND → aktifkan Dosman Exam.',
          ),
          duration: Duration(seconds: 6),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
    // Lifecycle observer memanggil _checkAndProceed() saat user kembali.
  }

  void _forceproceed() {
    if (mounted) Navigator.of(context).pushReplacementNamed(widget.nextRoute);
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF0F172A),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => SystemNavigator.pop(),
          backgroundColor: Colors.red.withValues(alpha: 0.15),
          foregroundColor: Colors.red.withValues(alpha: 0.85),
          elevation: 2,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: BorderSide(color: Colors.red.withValues(alpha: 0.3)),
          ),
          icon: const Icon(Icons.exit_to_app_rounded, size: 16),
          label: const Text(
            'Keluar',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
          ),
        ),
        floatingActionButtonLocation: FloatingActionButtonLocation.startFloat,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 100,
                  height: 100,
                  decoration: BoxDecoration(
                    color: const Color(0xFFEF4444).withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(28),
                  ),
                  child: const Icon(
                    Icons.do_not_disturb_on_rounded,
                    size: 52,
                    color: Color(0xFFEF4444),
                  ),
                ),
                const SizedBox(height: 32),
                const Text(
                  'Izin Diperlukan',
                  style: TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                Text(
                  'Aplikasi membutuhkan izin Do Not Disturb untuk memblokir '
                  'telepon masuk dan notifikasi selama ujian berlangsung.',
                  style: TextStyle(
                    fontSize: 15,
                    color: Colors.white.withValues(alpha: 0.7),
                    height: 1.6,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 20),
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.05),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.white.withValues(alpha: 0.1)),
                  ),
                  child: Column(
                    children: [
                      _step('1', 'Ketuk tombol Izinkan di bawah'),
                      const SizedBox(height: 10),
                      _step('2', 'Cari "Dosman Exam" di halaman yang terbuka'),
                      const SizedBox(height: 10),
                      _step('3', 'Aktifkan izin, lalu kembali ke aplikasi'),
                    ],
                  ),
                ),
                const SizedBox(height: 40),
                SizedBox(
                  width: double.infinity,
                  height: 54,
                  child: ElevatedButton.icon(
                    onPressed: _opening ? null : _requestPermission,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF2563EB),
                      foregroundColor: Colors.white,
                      disabledBackgroundColor: const Color(0xFF1D4ED8),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                      ),
                    ),
                    icon: _opening
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white70,
                            ),
                          )
                        : const Icon(Icons.settings_rounded, size: 20),
                    label: Text(
                      _opening ? 'Membuka Pengaturan...' : 'Izinkan Akses DND',
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ),
                // Tombol bypass — muncul setelah user pernah coba buka Settings.
                // Diperlukan untuk HP yang API-nya tidak melaporkan izin dengan benar
                // (mis. Vivo FuntouchOS) sehingga _checkAndProceed() tidak pernah lanjut.
                if (_attempted) ...[
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    height: 46,
                    child: TextButton(
                      onPressed: _forceproceed,
                      style: TextButton.styleFrom(
                        foregroundColor: Colors.white54,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                          side: const BorderSide(color: Colors.white24),
                        ),
                      ),
                      child: const Text(
                        'Sudah Diizinkan, Lanjutkan',
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _step(String num, String text) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 22,
          height: 22,
          decoration: BoxDecoration(
            color: const Color(0xFF2563EB),
            borderRadius: BorderRadius.circular(11),
          ),
          alignment: Alignment.center,
          child: Text(
            num,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.75),
              fontSize: 13,
              height: 1.5,
            ),
          ),
        ),
      ],
    );
  }
}
