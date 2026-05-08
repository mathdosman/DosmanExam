# 📱 Dosman Ujian — Aplikasi Mobile Siswa (Tahap 3)

> **Dosman** = **Do**sen/Guru **Man**aging **Ujian**
> Aplikasi Lockdown Browser untuk siswa saat ujian online di **SMAN 1 GIANYAR**, Bali.

---

## 🏗️ Arsitektur Aplikasi

```
mobile/
├── lib/
│   ├── main.dart                    ← Entry point + Theme + Routes
│   ├── config/
│   │   └── app_config.dart          ← Konstanta global (URL, channel, interval)
│   ├── models/
│   │   ├── course.dart              ← Model data Course
│   │   ├── quiz.dart                ← Model data Quiz
│   │   └── session.dart             ← Model Session, RegisterSessionResult, HeartbeatResult
│   ├── services/
│   │   ├── api_service.dart         ← HTTP client untuk Moodle REST API
│   │   ├── auth_service.dart        ← Login, logout, secure storage token
│   │   └── exam_service.dart        ← register_session, heartbeat, log_activity
│   ├── screens/
│   │   ├── splash_screen.dart       ← Cek auth, animasi logo
│   │   ├── login_screen.dart        ← Form login username/password
│   │   ├── course_list_screen.dart  ← Daftar course (grid)
│   │   ├── quiz_list_screen.dart    ← Daftar quiz dalam course
│   │   ├── exam_ready_screen.dart   ← Info ujian + aturan lockdown
│   │   └── exam_screen.dart         ← ⭐ Lockdown WebView (inti aplikasi)
│   └── widgets/
│       ├── course_card.dart         ← Card course dengan banner warna
│       └── quiz_card.dart           ← Card quiz dengan info jadwal & durasi
└── android/
    └── app/src/main/
        ├── kotlin/com/dosman/ujian/
        │   └── MainActivity.kt      ← FLAG_SECURE via MethodChannel
        └── AndroidManifest.xml      ← Permissions Internet + keamanan
```

---

## ✨ Fitur Lockdown

| Fitur | Implementasi |
|---|---|
| 🔒 **Blokir Screenshot** | `FLAG_SECURE` via Android MethodChannel |
| 📋 **Blokir Copy/Paste** | JavaScript injection di WebView |
| 📱 **Deteksi Minimize** | `WidgetsBindingObserver.didChangeAppLifecycleState` |
| 👁️ **Deteksi Tab Keluar** | JS `visibilitychange` event |
| ✋ **Deteksi Multi-Jari** | JS `touchstart` (3+ jari) |
| ⌨️ **Blokir Shortcut Ctrl+C/V** | JS `keydown` event handler |
| 💓 **Heartbeat 10 Detik** | `Timer.periodic` → `local_dosman_ujian_heartbeat` |
| 🔗 **Blokir Navigasi Luar** | `shouldOverrideUrlLoading` di InAppWebView |
| 🟡 **Overlay Jeda** | Tampil fullscreen saat guru pause |
| 🔴 **Overlay Blokir** | Tampil fullscreen saat sesi diblokir |
| ✅ **Overlay Selesai** | Tampil saat URL review quiz terdeteksi |

---

## 🚀 Cara Setup & Build

### Prasyarat

- [Flutter SDK](https://docs.flutter.dev/get-started/install) versi ≥ 3.3.0
- Android Studio / VS Code dengan Flutter extension
- Android SDK (API level 21+)
- Perangkat Android atau emulator

### Langkah 1 — Generate boilerplate Flutter

```bash
# Di direktori dosman-ujian-project/
flutter create --org com.dosman --project-name dosman_ujian mobile_temp

# Salin file-file yang dihasilkan (KECUALI lib/, pubspec.yaml, android/app/src/main/)
# ke dalam folder mobile/ ini
# Yang perlu disalin dari mobile_temp/:
#   android/  (kecuali app/src/main/kotlin dan AndroidManifest.xml)
#   ios/
#   .gitignore
#   analysis_options.yaml
```

> ⚡ **Alternatif lebih mudah:** Jalankan `flutter create` langsung di folder `mobile/` ini:
> ```bash
> cd mobile
> flutter create --org com.dosman --project-name dosman_ujian .
> ```
> Lalu **replace** file-file berikut dengan versi dari repo ini:
> - `lib/` (seluruh folder)
> - `pubspec.yaml`
> - `android/app/src/main/kotlin/com/dosman/ujian/MainActivity.kt`
> - `android/app/src/main/AndroidManifest.xml`

### Langkah 2 — Install dependencies

```bash
cd mobile
flutter pub get
```

### Langkah 3 — Konfigurasi AndroidManifest.xml

Pastikan file `android/app/src/main/AndroidManifest.xml` memiliki:

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <!-- Permission wajib -->
    <uses-permission android:name="android.permission.INTERNET"/>
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE"/>

    <application
        android:label="Dosman Ujian"
        android:name="${applicationName}"
        android:icon="@mipmap/ic_launcher"
        android:usesCleartextTraffic="false">

        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:launchMode="singleTop"
            android:taskAffinity=""
            android:theme="@style/LaunchTheme"
            android:configChanges="orientation|keyboardHidden|keyboard|screenSize|smallestScreenSize|locale|layoutDirection|fontScale|screenLayout|density|uiMode"
            android:hardwareAccelerated="true"
            android:windowSoftInputMode="adjustResize">

            <meta-data
                android:name="io.flutter.embedding.android.NormalTheme"
                android:resource="@style/NormalTheme"/>

            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity>

        <meta-data
            android:name="flutterEmbedding"
            android:value="2"/>
    </application>
</manifest>
```

### Langkah 4 — Jalankan aplikasi

```bash
# Mode debug
flutter run

# Build APK release
flutter build apk --release

# Build APK split per ABI (ukuran lebih kecil)
flutter build apk --split-per-abi --release
```

APK release ada di: `build/app/outputs/flutter-apk/`

---

## ⚙️ Konfigurasi

Edit file `lib/config/app_config.dart` untuk menyesuaikan:

```dart
class AppConfig {
  static const String moodleUrl    = 'https://dosman.site';  // ← Ganti jika URL server berubah
  static const String serviceName  = 'dosman_ujian_mobile';
  static const int heartbeatInterval = 10;  // detik
  static const int heartbeatTimeout  = 30;  // detik (harus sama dengan heartbeat.php)
}
```

---

## 📱 Alur Aplikasi

```
1. SplashScreen
   └── Cek token di secure storage
       ├── Token valid  → CourseListScreen
       └── Token tidak ada / expired → LoginScreen

2. LoginScreen
   └── Input username + password
       └── POST /login/token.php → dapat token
           └── GET /webservice/rest/server.php → verifikasi + ambil user info
               └── Simpan ke Flutter Secure Storage → CourseListScreen

3. CourseListScreen
   └── GET core_enrol_get_users_courses → tampil grid course
       └── Klik course → QuizListScreen

4. QuizListScreen
   └── GET mod_quiz_get_quizzes_by_courses → tampil list quiz
       └── Klik quiz → ExamReadyScreen

5. ExamReadyScreen
   └── Tampil info ujian + aturan lockdown
       └── Centang persetujuan → Klik "Mulai" → ExamScreen

6. ExamScreen ⭐
   ├── enableLockdown() → FLAG_SECURE aktif
   ├── register_session() → dapat session_id
   ├── WebView load https://dosman.site/login/index.php
   ├── Auto-fill login via JS injection
   ├── Redirect ke quiz URL setelah login
   ├── Inject lockdown JS (blokir copy, monitor violations)
   ├── Log exam_start
   ├── Timer heartbeat tiap 10 detik
   │   └── Cek status: active → lanjut | paused → overlay | blocked → overlay blokir
   ├── Lifecycle monitor:
   │   └── App background → log app_background + stop heartbeat
   └── URL berubah ke /review.php → log exam_end → overlay selesai
```

---

## 🔌 API yang Dipanggil Aplikasi

| Fungsi | Tipe | Kapan |
|---|---|---|
| `login/token.php` | REST | Saat login |
| `core_webservice_get_site_info` | read | Verifikasi token |
| `core_enrol_get_users_courses` | read | Load daftar course |
| `mod_quiz_get_quizzes_by_courses` | read | Load daftar quiz |
| `local_dosman_ujian_register_session` | write | Saat mulai ujian |
| `local_dosman_ujian_heartbeat` | write | Setiap 10 detik |
| `local_dosman_ujian_log_activity` | write | Setiap ada event |

---

## 🛡️ Event yang Dilaporkan ke Server

| Event | Trigger | Mencurigakan |
|---|---|---|
| `exam_start` | Masuk halaman quiz | ❌ |
| `exam_end` | URL berubah ke review page | ❌ |
| `app_background` | App diminimize / inactive | ✅ |
| `copy_attempt` | JS copy/cut/Ctrl+C event | ✅ |
| `focus_lost` | JS visibilitychange | ✅ |
| `multi_finger_gesture` | JS touchstart 3+ jari | ❌ |

---

## 📦 Dependencies

| Package | Versi | Kegunaan |
|---|---|---|
| `flutter_inappwebview` | ^6.1.5 | WebView dengan JS injection & lockdown |
| `flutter_secure_storage` | ^9.2.2 | Simpan token & password terenkripsi |
| `http` | ^1.2.2 | HTTP client untuk Moodle REST API |
| `provider` | ^6.1.2 | State management |

---

## 🔧 Troubleshooting

### ❌ Login gagal "Service not found"
Plugin Moodle `local_dosman_ujian` belum aktif atau service `dosman_ujian_mobile` belum terdaftar.
→ Buka `https://dosman.site/admin/index.php` dan upgrade database.

### ❌ WebView tidak bisa load Moodle
Cek `android:usesCleartextTraffic` di AndroidManifest.xml.
Untuk HTTPS harusnya tidak masalah. Jika server pakai HTTP, set ke `true`.

### ❌ Screenshot masih bisa dilakukan
`FLAG_SECURE` hanya bekerja di device fisik Android.
Di emulator, fitur ini tidak aktif. Test di device nyata.

### ❌ Heartbeat gagal terus
Pastikan token masih valid. Coba logout → login ulang.
Jika token expired, aplikasi otomatis redirect ke halaman login.

### ❌ `flutter pub get` error dependency conflict
```bash
flutter pub upgrade --major-versions
```

---

## 📊 Status Pengembangan

| Fitur | Status |
|---|---|
| Login Moodle | ✅ Selesai |
| Daftar Course | ✅ Selesai |
| Daftar Quiz | ✅ Selesai |
| Halaman Persiapan | ✅ Selesai |
| WebView Lockdown | ✅ Selesai |
| Auto-login WebView | ✅ Selesai |
| Heartbeat | ✅ Selesai |
| Log Violations | ✅ Selesai |
| Blokir Screenshot (Android) | ✅ Selesai |
| Overlay Paused/Blocked | ✅ Selesai |
| Deteksi Ujian Selesai | ✅ Selesai |
| iOS Support | ⏳ Belum diuji |
| Push Notification dari Guru | ⏳ Tahap berikutnya |
| Mode Offline (cache soal) | ⏳ Tahap berikutnya |

---

## 📞 Koneksi ke Sistem

```
┌─────────────────────┐     REST API      ┌─────────────────────┐
│   Aplikasi Mobile   │ ◄────────────────► │   Moodle + Plugin   │
│   (Flutter/Android) │                   │  local_dosman_ujian │
│                     │     WebView       │                     │
│  ExamScreen         │ ──────────────►   │  /mod/quiz/...      │
└─────────────────────┘                   └─────────────────────┘
                                                    │
                                                    ▼ Real-time
                                          ┌─────────────────────┐
                                          │   Dashboard Guru    │
                                          │  (Web Browser)      │
                                          └─────────────────────┘
```

---

*Versi: 1.0.0 | Target: Android 5.0+ (API 21+) | Flutter: ≥ 3.3.0*
*Dikembangkan untuk SMAN 1 GIANYAR, Bali*