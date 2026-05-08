# dosman_ujian - Plugin Moodle Anti-Kecurangan

Plugin Moodle untuk mendeteksi dan mencatat aktivitas mencurigakan siswa saat ujian online.

---

## Cara Install Plugin ke Moodle

### Langkah 1: Upload Plugin
1. Login ke Moodle sebagai **admin**
2. Masuk ke **Site Administration ? Plugins ? Install plugins**
3. Upload file **dosman_ujian.zip** (zip folder `dosman_ujian` ini)
4. Ikuti wizard instalasi sampai selesai

### Atau Manual (via FTP/File Manager):
1. Copy folder `dosman_ujian` ke direktori `/moodle/local/` di server kamu
2. Login ke Moodle sebagai admin
3. Moodle akan otomatis detect plugin baru dan minta upgrade database
4. Klik **Upgrade Database** dan selesai

---

## Langkah 2: Buat Token API untuk Aplikasi Mobile

1. Login sebagai admin di Moodle
2. Masuk ke **Site Administration ? Server ? Web services ? Manage tokens**
3. Klik **Add**
4. Pilih user (bisa buat user khusus untuk service, atau gunakan admin)
5. Pilih service: **dosman_ujian Mobile Service**
6. Klik **Save changes**
7. **Salin token** yang muncul - ini akan dipakai di aplikasi mobile

---

## Langkah 3: Aktifkan Web Services

1. Masuk ke **Site Administration ? Advanced features**
2. Centang **Enable web services** ? Save
3. Masuk ke **Site Administration ? Server ? Web services ? Overview**
4. Pastikan semua langkah sudah centang hijau

---

## REST API Endpoints

Base URL: `https://dosman.site/webservice/rest/server.php`

### 1. Log Activity (POST)
Kirim log aktivitas siswa

```
wstoken=TOKEN&wsfunction=local_dosman_ujian_log_activity&moodlewsrestformat=json
&userid=5&quizid=3&courseid=2&eventtype=app_background&eventdata={}
```

### 2. Get Exam Status (GET/POST)
Cek status ujian aktif siswa

```
wstoken=TOKEN&wsfunction=local_dosman_ujian_get_exam_status&moodlewsrestformat=json
&userid=5&quizid=3
```

### 3. Get Logs (POST)
Ambil log untuk dashboard guru

```
wstoken=TOKEN&wsfunction=local_dosman_ujian_get_logs&moodlewsrestformat=json
&quizid=3&courseid=2&suspicious_only=1
```

---

## Event Types

| Event Type | Keterangan | Mencurigakan? |
|---|---|---|
| `exam_start` | Ujian dimulai | Tidak |
| `exam_end` | Ujian selesai | Tidak |
| `app_background` | Aplikasi diminimize | **Ya** |
| `screenshot_attempt` | Percobaan screenshot | **Ya** |
| `copy_attempt` | Percobaan copy teks | **Ya** |
| `focus_lost` | Layar tidak fokus | **Ya** |
| `screen_record_attempt` | Percobaan screen record | **Ya** |

---

## Struktur Database

Tabel: `mdl_local_dosman_ujian_logs`

| Field | Type | Keterangan |
|---|---|---|
| id | INT | Primary key |
| userid | INT | ID siswa |
| courseid | INT | ID course |
| quizid | INT | ID quiz |
| eventtype | VARCHAR(100) | Jenis event |
| eventdata | TEXT | Data JSON tambahan |
| suspicious | TINYINT(1) | 1=mencurigakan, 0=normal |
| timecreated | INT | Unix timestamp |
