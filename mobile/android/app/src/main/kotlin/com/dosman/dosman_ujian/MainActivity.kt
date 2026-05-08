package com.dosman.dosman_ujian

import android.accessibilityservice.AccessibilityServiceInfo
import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.hardware.display.DisplayManager
import android.app.NotificationManager
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Display
import android.view.WindowManager
import android.view.accessibility.AccessibilityManager
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {

    private val lockdownChannel = "com.dosman.ujian/lockdown"
    private val mainHandler = Handler(Looper.getMainLooper())

    private var kioskActive           = false   // kiosk sedang berjalan
    private var backLocked            = false   // Back button dikunci
    private var pinLoopOn             = false   // loop 1-detik berjalan
    private var pinAttempts           = 0       // jumlah percobaan re-pin dalam siklus ini
    private val maxPinAttempts        = 60      // 60x → tutup app
    private var wasPinnedSuccessfully = false   // true setelah lock task pertama kali aktif

    // Filter DND sebelum ujian dimulai — dikembalikan saat exitLockTask
    private var previousDndFilter = NotificationManager.INTERRUPTION_FILTER_UNKNOWN

    // Referensi channel untuk mengirim event ke Flutter (split-screen, overlay, dll.)
    private var flutterChannel: MethodChannel? = null

    // Deteksi overlay: jika focus hilang lebih dari 5 detik saat kiosk aktif → overlay asing
    private var overlayCheckRunnable: Runnable? = null
    private val overlayThresholdMs   = 5_000L

    // Deteksi screen cast / mirroring: DisplayListener memantau display baru saat kiosk aktif
    private val displayListener = object : DisplayManager.DisplayListener {
        override fun onDisplayAdded(displayId: Int) {
            if (!kioskActive) return
            mainHandler.post {
                flutterChannel?.invokeMethod("onScreenCastDetected", null)
            }
        }
        override fun onDisplayRemoved(displayId: Int) {}
        override fun onDisplayChanged(displayId: Int) {}
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private fun isLockTaskActive(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return false
        val am = getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        return am.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE
    }

    private fun isDeviceOwner(): Boolean {
        val dpm = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        return dpm.isDeviceOwnerApp(packageName)
    }

    private fun whitelistLockTask() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
        try {
            val dpm  = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
            val comp = ComponentName(this, ExamDeviceAdmin::class.java)
            if (dpm.isDeviceOwnerApp(packageName)) dpm.setLockTaskPackages(comp, arrayOf(packageName))
        } catch (_: Exception) {}
    }

    /** Minta Android menampilkan dialog "Pin this app?" — hanya saat app sudah di depan. */
    private fun requestPin() {
        if (!kioskActive) return
        try { whitelistLockTask() } catch (_: Exception) {}
        try { startLockTask() }     catch (_: Exception) {}
    }

    /** Paksa app kembali ke depan (dari background/recent) lalu pin. */
    private fun bringToFrontAndPin() {
        if (!kioskActive) return
        try { whitelistLockTask() } catch (_: Exception) {}
        try { startLockTask() }     catch (_: Exception) {}
        try {
            startActivity(Intent(this, MainActivity::class.java).apply {
                addFlags(
                    Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or
                    Intent.FLAG_ACTIVITY_SINGLE_TOP       or
                    Intent.FLAG_ACTIVITY_NEW_TASK
                )
            })
        } catch (_: Exception) {}
    }

    /**
     * Loop 1 detik: jika pin tidak aktif → re-pin langsung.
     * Setelah 60x gagal → tutup app.
     * Audio alarm HANYA dibunyikan jika app sebelumnya sudah berhasil terpinned
     * (wasPinnedSuccessfully = true) — bukan pada percobaan pin pertama.
     */
    private val pinLoop: Runnable = object : Runnable {
        override fun run() {
            if (!kioskActive || !pinLoopOn) return
            if (!isLockTaskActive()) {
                if (pinAttempts == 0 && wasPinnedSuccessfully) {
                    // Lock task hilang SETELAH sebelumnya berhasil pinned —
                    // siswa mencabut pin secara aktif. Bunyikan alarm dan beritahu Flutter.
                    forceMaxVolume()
                    mainHandler.post {
                        flutterChannel?.invokeMethod("onLockTaskLost", null)
                    }
                    playUnpinAlert()
                }
                pinAttempts++
                if (pinAttempts > maxPinAttempts) {
                    stopKiosk()
                    try { finishAndRemoveTask() } catch (_: Exception) { finish() }
                    return
                }
                // App mungkin di background → paksa ke depan sekaligus pin
                bringToFrontAndPin()
            } else {
                // Lock task aktif — catat sebagai berhasil pinned pertama kali
                if (!wasPinnedSuccessfully) wasPinnedSuccessfully = true
                pinAttempts = 0
            }
            mainHandler.postDelayed(this, 1_000L)
        }
    }

    private fun startPinLoop() {
        mainHandler.removeCallbacks(pinLoop)
        pinAttempts = 0
        pinLoopOn   = true
        mainHandler.post(pinLoop)
    }

    private fun stopKiosk() {
        mainHandler.removeCallbacks(pinLoop)
        pinLoopOn             = false
        kioskActive           = false
        pinAttempts           = 0
        wasPinnedSuccessfully = false
        try {
            val dm = getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
            dm.unregisterDisplayListener(displayListener)
        } catch (_: Exception) {}
    }

    /**
     * Paksa volume STREAM_ALARM ke maksimum dan ringer mode ke normal.
     * STREAM_ALARM berbunyi bahkan saat HP di-silent/vibrate — tidak butuh permission khusus
     * selain MODIFY_AUDIO_SETTINGS yang sudah ada di manifest.
     */
    private fun forceMaxVolume() {
        try {
            val am = getSystemService(Context.AUDIO_SERVICE) as AudioManager
            am.ringerMode = AudioManager.RINGER_MODE_NORMAL
            val maxVol = am.getStreamMaxVolume(AudioManager.STREAM_ALARM)
            am.setStreamVolume(AudioManager.STREAM_ALARM, maxVol, 0)
        } catch (_: Exception) {}
    }

    /**
     * Putar nada alarm mendesak saat siswa mencoba mencabut pin (pertama kali terdeteksi).
     * Menggunakan STREAM_ALARM agar berbunyi meski HP di-silent.
     */
    private fun playUnpinAlert() {
        try {
            val tg = ToneGenerator(AudioManager.STREAM_ALARM, 100)
            // Tiga nada panjang berurutan (total ~2850 ms, 3x lebih panjang dari sebelumnya)
            val handler = Handler(Looper.getMainLooper())
            tg.startTone(ToneGenerator.TONE_CDMA_EMERGENCY_RINGBACK, 750)
            handler.postDelayed({ tg.startTone(ToneGenerator.TONE_CDMA_EMERGENCY_RINGBACK, 750) }, 1050)
            handler.postDelayed({ tg.startTone(ToneGenerator.TONE_CDMA_EMERGENCY_RINGBACK, 750); tg.release() }, 2100)
        } catch (_: Exception) {}
    }

    // ── DND (Do Not Disturb) ──────────────────────────────────────────────────

    private fun isDndGranted(): Boolean {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return nm.isNotificationPolicyAccessGranted
    }

    /** Aktifkan DND saat kiosk dimulai — simpan filter sebelumnya untuk di-restore. */
    private fun enableDnd() {
        if (!isDndGranted()) return
        try {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            previousDndFilter = nm.currentInterruptionFilter
            // INTERRUPTION_FILTER_NONE: blokir semua — telepon, SMS, notifikasi app.
            // ToneGenerator(STREAM_ALARM) masih berbunyi karena akses langsung ke audio hardware.
            nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_NONE)
        } catch (_: Exception) {}
    }

    /** Kembalikan DND ke kondisi sebelum ujian saat kiosk dimatikan. */
    private fun disableDnd() {
        if (!isDndGranted()) return
        try {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val restore = if (
                previousDndFilter == NotificationManager.INTERRUPTION_FILTER_UNKNOWN ||
                previousDndFilter == NotificationManager.INTERRUPTION_FILTER_NONE
            ) {
                NotificationManager.INTERRUPTION_FILTER_ALL
            } else {
                previousDndFilter
            }
            nm.setInterruptionFilter(restore)
            previousDndFilter = NotificationManager.INTERRUPTION_FILTER_UNKNOWN
        } catch (_: Exception) {}
    }

    // ── System bars ───────────────────────────────────────────────────────────

    private fun hideSystemBars() {
        WindowCompat.setDecorFitsSystemWindows(window, false)
        WindowInsetsControllerCompat(window, window.decorView).apply {
            hide(WindowInsetsCompat.Type.systemBars())
            systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        }
    }

    private fun showSystemBars() {
        WindowInsetsControllerCompat(window, window.decorView)
            .show(WindowInsetsCompat.Type.systemBars())
        WindowCompat.setDecorFitsSystemWindows(window, true)
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Setiap kali app kembali ke depan: jika kiosk aktif tapi pin lepas,
     * langsung pin ulang tanpa pengecekan kondisi lain.
     */
    override fun onResume() {
        super.onResume()
        if (!kioskActive) return
        try { hideSystemBars() } catch (_: Exception) {}
        // Volume hanya dipaksa max setelah app sudah berhasil pinned sebelumnya,
        // sehingga saat siswa mencabut pin dan kembali ke app, alarm berbunyi keras.
        if (wasPinnedSuccessfully) forceMaxVolume()
        // App sudah di depan — cukup startLockTask(), jangan startActivity (loop!)
        if (!isLockTaskActive()) requestPin()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus && kioskActive) {
            try { hideSystemBars() } catch (_: Exception) {}
            // Focus kembali — batalkan pengecekan overlay yang pending
            overlayCheckRunnable?.let { mainHandler.removeCallbacks(it) }
            overlayCheckRunnable = null
        } else if (!hasFocus && kioskActive && wasPinnedSuccessfully) {
            // Focus hilang saat kiosk aktif DAN app sudah berhasil terpinned.
            // Sebelum pinned, focus hilang karena dialog "App pinned" — wajar, bukan overlay.
            // Jadwalkan pengecekan: jika masih tidak ada focus setelah [overlayThresholdMs],
            // kemungkinan besar ada overlay app asing di atas layar ujian.
            overlayCheckRunnable?.let { mainHandler.removeCallbacks(it) }
            val check = Runnable {
                overlayCheckRunnable = null
                if (!hasWindowFocus() && kioskActive) {
                    flutterChannel?.invokeMethod("onOverlayDetected", null)
                }
            }
            overlayCheckRunnable = check
            mainHandler.postDelayed(check, overlayThresholdMs)
        }
    }

    /**
     * Deteksi split-screen / multi-window saat kiosk aktif.
     * Android 7.0+ (API 24) memungkinkan siswa membuka dua app bersamaan.
     * Ketika terdeteksi: coba re-pin + kirim event ke Flutter untuk trigger blokir.
     */
    @Suppress("OVERRIDE_DEPRECATION")
    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (isInMultiWindowMode && kioskActive) {
            // Coba paksa full-screen kembali (berhasil di beberapa device)
            try { startLockTask() } catch (_: Exception) {}
            // Beritahu Flutter untuk memicu alur pelanggaran
            mainHandler.post {
                flutterChannel?.invokeMethod("onSplitScreenDetected", null)
            }
        }
    }

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (backLocked) return
        super.onBackPressed()
    }

    // ── MethodChannel ─────────────────────────────────────────────────────────

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        val channel = MethodChannel(
            flutterEngine.dartExecutor.binaryMessenger,
            lockdownChannel
        )
        flutterChannel = channel
        channel.setMethodCallHandler { call, result ->
            when (call.method) {

                "enableLockdown" -> {
                    try {
                        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        result.success(true)
                    } catch (e: Exception) {
                        result.error("LOCKDOWN_ERROR", e.message, null)
                    }
                }

                "disableLockdown" -> {
                    try {
                        window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        result.success(true)
                    } catch (e: Exception) {
                        result.error("LOCKDOWN_ERROR", e.message, null)
                    }
                }

                "enterLockTask" -> {
                    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.LOLLIPOP) {
                        result.success(false); return@setMethodCallHandler
                    }
                    try {
                        hideSystemBars()
                        kioskActive = true
                        // forceMaxVolume() tidak dipanggil di sini agar notifikasi "App pinned"
                        // pertama kali muncul tanpa audio. Volume akan dipaksa max hanya
                        // setelah app berhasil pinned dan siswa mencoba mencabutnya.
                        enableDnd()
                        requestPin()
                        if (!isDeviceOwner()) startPinLoop()
                        // Mulai pantau display baru (screen cast / mirroring)
                        val dm = getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
                        dm.registerDisplayListener(displayListener, mainHandler)
                        result.success(true)
                    } catch (e: Exception) {
                        stopKiosk()
                        result.success(false)
                    }
                }

                "exitLockTask" -> {
                    stopKiosk()
                    disableDnd()
                    try { stopLockTask()   } catch (_: Exception) {}
                    try { showSystemBars() } catch (_: Exception) {}
                    result.success(true)
                }

                "checkScreenCast" -> {
                    // Cek apakah ada display tambahan (mirroring / Chromecast / screen recorder virtual display).
                    // Display.TYPE_BUILT_IN = 1 (layar fisik utama). Jika ada display lain, kemungkinan screen cast.
                    val casting = try {
                        val dm = getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
                        val displays = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                            dm.getDisplays(DisplayManager.DISPLAY_CATEGORY_PRESENTATION)
                        } else {
                            @Suppress("DEPRECATION")
                            dm.displays.filter { it.displayId != Display.DEFAULT_DISPLAY }
                                .toTypedArray()
                        }
                        displays.isNotEmpty()
                    } catch (_: Exception) { false }
                    result.success(casting)
                }

                "lockBackButton"   -> { backLocked = true;  result.success(true) }
                "unlockBackButton" -> { backLocked = false; result.success(true) }

                "isScreenPinningEnabled" -> result.success(
                    try { Settings.Secure.getInt(contentResolver, "lock_to_app_enabled", 0) == 1 }
                    catch (_: Exception) { false }
                )

                "openScreenPinningSettings" -> {
                    val candidates = listOf(
                        "android.settings.SCREEN_PINNING_SETTINGS",
                        Settings.ACTION_SECURITY_SETTINGS,
                        Settings.ACTION_SETTINGS,
                    )
                    var opened = false
                    for (action in candidates) {
                        try {
                            startActivity(Intent(action).apply { addFlags(Intent.FLAG_ACTIVITY_NEW_TASK) })
                            opened = true; break
                        } catch (_: Exception) {}
                    }
                    result.success(opened)
                }

                "checkAccessibilityServices" -> {
                    // Kembalikan daftar accessibility service pihak ketiga yang aktif.
                    // Service sistem (com.android.*, android.*) dan app sendiri diabaikan.
                    val systemPrefixes = listOf(
                        "com.android.", "android.", "com.google.android.",
                        "com.samsung.android.", "com.miui.", "com.huawei.",
                    )
                    try {
                        val am = getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager
                        val active = am.getEnabledAccessibilityServiceList(
                            AccessibilityServiceInfo.FEEDBACK_ALL_MASK
                        )
                        val suspicious = active
                            .filter { info ->
                                val pkg = info.resolveInfo.serviceInfo.packageName
                                pkg != packageName &&
                                systemPrefixes.none { pkg.startsWith(it) }
                            }
                            .map { info ->
                                info.resolveInfo.serviceInfo
                                    .loadLabel(packageManager).toString()
                            }
                        result.success(suspicious)
                    } catch (_: Exception) {
                        result.success(emptyList<String>())
                    }
                }

                "checkSecurityFlags" -> {
                    // Cek USB Debugging dan Developer Options.
                    // Keduanya tidak butuh permission khusus — readable oleh semua app.
                    val adbEnabled = try {
                        Settings.Global.getInt(
                            contentResolver,
                            Settings.Global.ADB_ENABLED, 0
                        ) == 1
                    } catch (_: Exception) { false }

                    val devOptions = try {
                        Settings.Global.getInt(
                            contentResolver,
                            Settings.Global.DEVELOPMENT_SETTINGS_ENABLED, 0
                        ) == 1
                    } catch (_: Exception) { false }

                    result.success(mapOf(
                        "usbDebugging"  to adbEnabled,
                        "developerMode" to devOptions,
                    ))
                }

                "checkRootStatus" -> {
                    // Deteksi root tanpa library eksternal.
                    // Tiga sinyal independen: (1) su binary, (2) build tags, (3) root app packages.
                    // Minimal satu sinyal positif → kemungkinan besar device di-root.

                    // (1) Cari binary 'su' di path umum
                    val suPaths = listOf(
                        "/system/bin/su", "/system/xbin/su", "/sbin/su",
                        "/system/su", "/system/bin/.ext/su", "/system/usr/we-need-root/su",
                        "/system/app/Superuser.apk", "/data/local/su", "/data/local/bin/su",
                        "/data/local/xbin/su",
                    )
                    val suFound = suPaths.any { java.io.File(it).exists() }

                    // (2) Build tag "test-keys" — ROM custom/rooted sering tidak signed dengan release-keys
                    val testKeys = Build.TAGS?.contains("test-keys") == true

                    // (3) Paket root management apps yang umum dipakai
                    val rootPackages = listOf(
                        "com.topjohnwu.magisk",       // Magisk
                        "eu.chainfire.supersu",        // SuperSU
                        "com.koushikdutta.superuser",  // CWM Superuser
                        "com.noshufou.android.su",     // ChainsDD Superuser
                        "com.thirdparty.superuser",
                        "com.yellowes.su",
                        "com.kingroot.kinguser",       // KingRoot
                        "com.kingo.root",              // KingoRoot
                        "com.smedialink.oneclickroot",
                        "com.zhiqupk.root.global",
                        "com.alephzain.framaroot",
                    )
                    val rootAppFound = rootPackages.any { pkg ->
                        try {
                            packageManager.getPackageInfo(pkg, 0)
                            true
                        } catch (_: Exception) { false }
                    }

                    result.success(mapOf(
                        "suBinaryFound" to suFound,
                        "testKeys"      to testKeys,
                        "rootAppFound"  to rootAppFound,
                        "isRooted"      to (suFound || testKeys || rootAppFound),
                    ))
                }

                "isLockTaskActive" -> {
                    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
                        result.success(true)
                    } else {
                        result.success(isLockTaskActive())
                    }
                }

                /** Minta dialog pin / lock task lagi (saat kiosk aktif). */
                "requestScreenPin" -> {
                    if (!kioskActive) {
                        result.success(false)
                    } else {
                        try { bringToFrontAndPin() } catch (_: Exception) {}
                        result.success(true)
                    }
                }

                "checkVpn" -> {
                    // Cek apakah ada koneksi VPN aktif via NetworkCapabilities.
                    // TRANSPORT_VPN true = ada interface VPN terhubung saat ini.
                    val vpnActive = try {
                        val cm = getSystemService(Context.CONNECTIVITY_SERVICE)
                            as android.net.ConnectivityManager
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                            val active = cm.activeNetwork
                            val caps   = if (active != null) cm.getNetworkCapabilities(active) else null
                            caps?.hasTransport(android.net.NetworkCapabilities.TRANSPORT_VPN) == true
                        } else {
                            @Suppress("DEPRECATION")
                            cm.activeNetworkInfo?.typeName?.contains("VPN", ignoreCase = true) == true
                        }
                    } catch (_: Exception) { false }
                    result.success(vpnActive)
                }

                "isScreenInteractive" -> {
                    // true  = layar menyala & interaktif (siswa mungkin pindah app)
                    // false = layar mati / screen-off (siswa mungkin sedang mengerjakan soal di kertas)
                    val pm = getSystemService(Context.POWER_SERVICE) as android.os.PowerManager
                    result.success(pm.isInteractive)
                }

                "isDndPermissionGranted" -> result.success(isDndGranted())

                "openDndSettings" -> {
                    try {
                        startActivity(
                            Intent(android.provider.Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS)
                                .apply { addFlags(Intent.FLAG_ACTIVITY_NEW_TASK) }
                        )
                        result.success(true)
                    } catch (_: Exception) {
                        result.success(false)
                    }
                }

                "openWifiSettings" -> {
                    startActivity(Intent(Settings.ACTION_WIFI_SETTINGS))
                    result.success(null)
                }

                "keepScreenOn" -> {
                    runOnUiThread {
                        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    }
                    result.success(true)
                }

                "clearScreenOn" -> {
                    runOnUiThread {
                        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    }
                    result.success(true)
                }

                else -> result.notImplemented()
            }
        }
    }
}
