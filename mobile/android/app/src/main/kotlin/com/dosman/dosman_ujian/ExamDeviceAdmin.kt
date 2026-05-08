package com.dosman.dosman_ujian

import android.app.admin.DeviceAdminReceiver
import android.content.Context
import android.content.Intent

/**
 * Device Admin Receiver untuk Dosman Exam.
 * Diperlukan agar app bisa di-set sebagai Device Owner via ADB,
 * sehingga startLockTask() berjalan tanpa dialog konfirmasi "App is pinned".
 *
 * Setup sekali per perangkat (dengan USB debugging aktif):
 *   adb shell dpm set-device-owner com.dosman.dosman_ujian/.ExamDeviceAdmin
 *
 * Syarat: hapus semua akun Google dari perangkat sebelum menjalankan perintah di atas.
 */
class ExamDeviceAdmin : DeviceAdminReceiver() {
    override fun onEnabled(context: Context, intent: Intent) {}
    override fun onDisabled(context: Context, intent: Intent) {}
}
