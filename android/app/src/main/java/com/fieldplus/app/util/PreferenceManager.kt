package com.fieldplus.app.util

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import java.util.UUID

class PreferenceManager(context: Context) {

    private val prefs: SharedPreferences = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)

    companion object {
        private const val PREF_NAME = "fieldplus_preferences"
        private const val KEY_SERVER_URL = "key_server_url"
        private const val KEY_DEVICE_ID = "key_device_id"
        private const val KEY_SURVEYOR_NAME = "key_surveyor_name"
        private const val KEY_API_TOKEN = "key_api_token"
        private const val KEY_BACKGROUND_SYNC = "key_background_sync"
        private const val KEY_WIFI_ONLY_SYNC = "key_wifi_only_sync"
        private const val KEY_MAP_PROVIDER = "key_map_provider"

        const val MAP_PROVIDER_GOOGLE = "google"
        const val MAP_PROVIDER_OSM = "osm"
        
        // Auto-detect default target based on emulator vs real hardware
        val DEFAULT_SERVER_URL: String
            get() {
                val isEmulator = Build.FINGERPRINT.startsWith("generic") ||
                        Build.MODEL.contains("google_sdk") ||
                        Build.MODEL.contains("Emulator")
                return if (isEmulator) "http://10.0.2.2:8080/api/" else "http://192.168.68.110:8080/api/"
            }

        const val DEFAULT_API_TOKEN = "17626f1c0c93e313caa233d65e15b324d7b741b0acea7687d97b0e93a77791de"
    }

    var serverUrl: String
        get() {
            var url = prefs.getString(KEY_SERVER_URL, null)
            // Migrate obsolete IP or null
            if (url.isNullOrBlank() || url.contains("192.168.68.104") || (!Build.MODEL.contains("Emulator") && url.contains("10.0.2.2"))) {
                url = DEFAULT_SERVER_URL
                prefs.edit().putString(KEY_SERVER_URL, url).apply()
            }
            if (!url.endsWith("/")) {
                url += "/"
            }
            return url
        }
        set(value) {
            var url = value.trim()
            if (!url.endsWith("/")) {
                url += "/"
            }
            prefs.edit().putString(KEY_SERVER_URL, url).apply()
        }

    var apiToken: String
        get() {
            val token = prefs.getString(KEY_API_TOKEN, null)
            if (token.isNullOrBlank()) {
                // Use default system token for out-of-the-box pairing
                prefs.edit().putString(KEY_API_TOKEN, DEFAULT_API_TOKEN).apply()
                return DEFAULT_API_TOKEN
            }
            return token
        }
        set(value) = prefs.edit().putString(KEY_API_TOKEN, value.trim()).apply()

    val isAuthenticated: Boolean
        get() = apiToken.isNotBlank()

    val deviceId: String
        get() {
            var id = prefs.getString(KEY_DEVICE_ID, null)
            if (id == null) {
                id = "${Build.MANUFACTURER} ${Build.MODEL} (${UUID.randomUUID().toString().substring(0, 8)})"
                prefs.edit().putString(KEY_DEVICE_ID, id).apply()
            }
            return id
        }

    var surveyorName: String
        get() = prefs.getString(KEY_SURVEYOR_NAME, "Field Officer") ?: "Field Officer"
        set(value) = prefs.edit().putString(KEY_SURVEYOR_NAME, value.trim()).apply()

    var isBackgroundSyncEnabled: Boolean
        get() = prefs.getBoolean(KEY_BACKGROUND_SYNC, true)
        set(value) = prefs.edit().putBoolean(KEY_BACKGROUND_SYNC, value).apply()

    var isWifiOnlySync: Boolean
        get() = prefs.getBoolean(KEY_WIFI_ONLY_SYNC, false)
        set(value) = prefs.edit().putBoolean(KEY_WIFI_ONLY_SYNC, value).apply()

    var mapProvider: String
        get() = prefs.getString(KEY_MAP_PROVIDER, MAP_PROVIDER_GOOGLE) ?: MAP_PROVIDER_GOOGLE
        set(value) = prefs.edit().putString(KEY_MAP_PROVIDER, value).apply()
}
