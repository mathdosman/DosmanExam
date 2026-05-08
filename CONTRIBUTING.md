# Contributing Guide — Dosman Ujian

Panduan ini untuk memastikan perubahan dari siapa pun tetap konsisten di `mobile`, `dashboard`, dan `moodle-plugin`.

## 1. Prinsip Utama

- Gunakan dokumen `DOKUMENTASI-PROYEK.md` sebagai acuan arsitektur dan kontrak API.
- Jangan ubah kontrak API plugin tanpa sinkronkan consumer (`mobile` + `dashboard`).
- Fokus pada perubahan kecil, jelas, dan mudah di-review.

## 2. Standar Penamaan dan Bahasa

- Nama function/class/variable: bahasa Inggris.
- Teks UI: bahasa Indonesia.
- Komentar teknis boleh Indonesia, tetapi ringkas dan konsisten.

Per stack:
- Dart/Flutter: file `snake_case`, class `PascalCase`, method/field `camelCase`.
- JS dashboard: method/field `camelCase`, object global `PascalCase`.
- PHP Moodle: endpoint `local_dosman_ujian_<action>`, parameter REST `snake_case`.

## 3. Alur Perubahan yang Wajib

Jika mengubah plugin API:
1. Update `classes/external/*.php`.
2. Update `db/services.php`.
3. Bump `version.php`.
4. Upgrade plugin di Moodle.
5. Update consumer:
   - `dashboard/js/api.js`
   - `mobile/lib/services/*`

Jika mengubah UI dashboard:
1. Prioritaskan class CSS di `dashboard/css/style.css`.
2. Hindari menambah inline style baru jika bisa memakai class.
3. Uji desktop + mobile breakpoint.

Jika mengubah mobile:
1. Pisahkan logic API ke `services/`.
2. Jangan hardcode secret/token baru.
3. Jalankan lint dan uji flow ujian utama.

## 4. Checklist Sebelum Merge

- [ ] Tidak ada endpoint yang hilang atau rename tanpa migrasi.
- [ ] Tidak ada perubahan yang memutus login/heartbeat/session.
- [ ] Flow `request_exit` tetap jalan.
- [ ] Flow password keluar tervalidasi:
  - [ ] salah password ditolak,
  - [ ] password benar lanjut ke request exit.
- [ ] UI dashboard tidak rusak di layar kecil.
- [ ] Dokumentasi diperbarui bila kontrak/alur berubah.

## 5. Testing Minimal

- Dashboard: login → pilih course/quiz → monitor tampil.
- Mobile: login → masuk exam → heartbeat normal.
- Integrasi: aksi guru (`pause/resume/reset/approve_exit/reject_exit`) terbaca di mobile.
- Password keluar: ubah di dashboard, lalu verifikasi dari mobile.

## 6. Catatan Keamanan

- Jangan commit password/token nyata.
- Jangan expose nilai config sensitif ke client jika tidak perlu.
- Untuk validasi sensitif, lakukan di server (`verify` endpoint), bukan hanya di client.

