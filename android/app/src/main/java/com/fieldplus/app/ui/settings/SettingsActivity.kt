package com.fieldplus.app.ui.settings

import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.view.MenuItem
import android.view.View
import android.widget.Toast
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.fieldplus.app.data.remote.ApiClient
import com.fieldplus.app.databinding.ActivitySettingsBinding
import com.fieldplus.app.util.PreferenceManager
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import kotlinx.coroutines.launch

class SettingsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySettingsBinding
    private lateinit var prefs: PreferenceManager

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Android 15 / API 35+ Edge-to-Edge window insets handling
        ViewCompat.setOnApplyWindowInsetsListener(binding.rootLayout) { _, insets ->
            val statusBars = insets.getInsets(WindowInsetsCompat.Type.statusBars())
            val navBars = insets.getInsets(WindowInsetsCompat.Type.navigationBars())
            binding.appBarLayout.updatePadding(top = statusBars.top)
            binding.root.updatePadding(bottom = navBars.bottom)
            insets
        }

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)

        prefs = PreferenceManager(this)

        loadCurrentSettings()
        setupListeners()
    }

    private fun loadCurrentSettings() {
        binding.etServerUrl.setText(prefs.serverUrl)
        binding.etApiToken.setText(prefs.apiToken)
        binding.etSurveyorName.setText(prefs.surveyorName)
        binding.tvDeviceId.text = "Device Identifier: ${prefs.deviceId}"
        binding.switchBackgroundSync.isChecked = prefs.isBackgroundSyncEnabled
        binding.switchWifiOnly.isChecked = prefs.isWifiOnlySync
        if (prefs.mapProvider == PreferenceManager.MAP_PROVIDER_OSM) {
            binding.rbMapOsm.isChecked = true
        } else {
            binding.rbMapGoogle.isChecked = true
        }
        binding.tvAppVersion.text = "FieldPlus v${com.fieldplus.app.BuildConfig.VERSION_NAME} (Build ${com.fieldplus.app.BuildConfig.VERSION_CODE}) • Native Android"
    }

    private fun setupListeners() {
        binding.btnPresetLan.setOnClickListener {
            binding.etServerUrl.setText("http://192.168.68.110:8080/api/")
        }

        binding.btnPresetEmulator.setOnClickListener {
            binding.etServerUrl.setText("http://10.0.2.2:8080/api/")
        }

        binding.btnPresetLocalhost.setOnClickListener {
            binding.etServerUrl.setText("http://127.0.0.1:8080/api/")
        }

        binding.btnScanQrCode.setOnClickListener {
            startQrCodeScanner()
        }

        binding.btnTestConnection.setOnClickListener {
            testConnection()
        }

        binding.btnSaveSettings.setOnClickListener {
            saveSettings()
        }

        binding.btnPrivacyPolicy.setOnClickListener {
            val serverUrl = prefs.serverUrl
            // Attempt to resolve privacy policy from server if valid URL, or open browser
            val baseWeb = if (serverUrl.contains("/api/")) {
                serverUrl.replace("/api/", "/privacy.html")
            } else if (serverUrl.contains("/api")) {
                serverUrl.replace("/api", "/privacy.html")
            } else {
                "https://sites.google.com/view/fieldplus-privacy-policy"
            }

            try {
                val intent = Intent(Intent.ACTION_VIEW, android.net.Uri.parse(baseWeb))
                startActivity(intent)
            } catch (e: Exception) {
                Toast.makeText(this, "Could not open browser: ${e.message}", Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun startQrCodeScanner() {
        val options = com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(com.google.mlkit.vision.barcode.common.Barcode.FORMAT_QR_CODE)
            .enableAutoZoom()
            .build()

        val scanner = com.google.mlkit.vision.codescanner.GmsBarcodeScanning.getClient(this, options)

        scanner.startScan()
            .addOnSuccessListener { barcode ->
                val rawValue = barcode.rawValue
                if (!rawValue.isNullOrBlank()) {
                    handleScannedQrPayload(rawValue)
                } else {
                    Toast.makeText(this, "Empty QR code detected", Toast.LENGTH_SHORT).show()
                }
            }
            .addOnCanceledListener {
                // User cancelled scanner
            }
            .addOnFailureListener { e ->
                Toast.makeText(this, "Scan failed: ${e.message}", Toast.LENGTH_LONG).show()
            }
    }

    private fun handleScannedQrPayload(payload: String) {
        try {
            val json = org.json.JSONObject(payload)
            val serverUrl = json.optString("server_url", "")
            val token = json.optString("api_token", "")
            val surveyor = json.optString("surveyor_name", "")

            if (serverUrl.isNotBlank()) {
                binding.etServerUrl.setText(serverUrl)
                prefs.serverUrl = serverUrl
            }
            if (token.isNotBlank()) {
                binding.etApiToken.setText(token)
                prefs.apiToken = token
            }
            if (surveyor.isNotBlank()) {
                binding.etSurveyorName.setText(surveyor)
                prefs.surveyorName = surveyor
            }

            Toast.makeText(this, "✅ QR Code Authenticated Successfully!", Toast.LENGTH_SHORT).show()
            // Automatically test connection to confirm
            testConnection()
        } catch (e: Exception) {
            // If plain text token or URL was scanned
            if (payload.startsWith("http://") || payload.startsWith("https://")) {
                binding.etServerUrl.setText(payload)
                prefs.serverUrl = payload
                Toast.makeText(this, "Server URL updated from QR code", Toast.LENGTH_SHORT).show()
            } else if (payload.length >= 32) {
                binding.etApiToken.setText(payload)
                prefs.apiToken = payload
                Toast.makeText(this, "API Token updated from QR code", Toast.LENGTH_SHORT).show()
            } else {
                Toast.makeText(this, "Unrecognized QR format: $payload", Toast.LENGTH_LONG).show()
            }
        }
    }

    private fun testConnection() {
        val testUrl = binding.etServerUrl.text?.toString()?.trim() ?: ""
        val testToken = binding.etApiToken.text?.toString()?.trim() ?: ""

        if (testUrl.isBlank()) {
            Toast.makeText(this, "Please enter a Server URL", Toast.LENGTH_SHORT).show()
            return
        }

        // Temporarily persist URL & Token to test
        val previousUrl = prefs.serverUrl
        val previousToken = prefs.apiToken
        prefs.serverUrl = testUrl
        prefs.apiToken = testToken
        val apiClient = ApiClient(this)

        binding.btnTestConnection.isEnabled = false
        binding.btnTestConnection.text = "Testing Connection & Bearer Auth..."
        binding.tvConnectionResult.visibility = View.VISIBLE
        binding.tvConnectionResult.text = "Connecting to $testUrl with Bearer Auth..."
        binding.tvConnectionResult.setTextColor(Color.parseColor("#475569"))

        lifecycleScope.launch {
            val startTime = System.currentTimeMillis()
            try {
                // Test 1: Check health
                val healthResponse = apiClient.getService().checkHealth()
                // Test 2: Check forms with Bearer Auth
                val formsResponse = apiClient.getService().getForms()

                val duration = System.currentTimeMillis() - startTime

                if (formsResponse.isSuccessful) {
                    val count = formsResponse.body()?.data?.size ?: 0
                    binding.tvConnectionResult.text = "✅ Authenticated & Connected Successfully (${duration}ms)\n" +
                            "Bearer Token: Verified Valid\n" +
                            "Accessible Forms: $count active template(s)"
                    binding.tvConnectionResult.setTextColor(Color.parseColor("#15803D"))
                } else if (formsResponse.code() == 401) {
                    binding.tvConnectionResult.text = "❌ Authentication Failed: 401 Unauthorized\n" +
                            "Invalid or missing API Bearer token. Scan the QR code from the Web Admin."
                    binding.tvConnectionResult.setTextColor(Color.parseColor("#B91C1C"))
                } else {
                    binding.tvConnectionResult.text = "❌ Server error: HTTP ${formsResponse.code()}"
                    binding.tvConnectionResult.setTextColor(Color.parseColor("#B91C1C"))
                }
            } catch (e: Exception) {
                binding.tvConnectionResult.text = "❌ Connection Failed: ${e.message}\nMake sure PHP server is running on target IP & port."
                binding.tvConnectionResult.setTextColor(Color.parseColor("#B91C1C"))
                prefs.serverUrl = previousUrl
                prefs.apiToken = previousToken
            } finally {
                binding.btnTestConnection.isEnabled = true
                binding.btnTestConnection.text = "Test Server Connection"
            }
        }
    }

    private fun saveSettings() {
        val url = binding.etServerUrl.text?.toString()?.trim() ?: ""
        val token = binding.etApiToken.text?.toString()?.trim() ?: ""
        val name = binding.etSurveyorName.text?.toString()?.trim() ?: ""

        if (url.isBlank()) {
            Toast.makeText(this, "Server URL cannot be empty", Toast.LENGTH_SHORT).show()
            return
        }

        prefs.serverUrl = url
        prefs.apiToken = token
        if (name.isNotBlank()) {
            prefs.surveyorName = name
        }
        prefs.isBackgroundSyncEnabled = binding.switchBackgroundSync.isChecked
        prefs.isWifiOnlySync = binding.switchWifiOnly.isChecked

        val selectedMap = if (binding.rbMapOsm.isChecked) {
            PreferenceManager.MAP_PROVIDER_OSM
        } else {
            PreferenceManager.MAP_PROVIDER_GOOGLE
        }
        prefs.mapProvider = selectedMap

        // Reschedule or update background worker based on settings
        com.fieldplus.app.worker.SyncWorker.schedulePeriodicSync(this)

        Toast.makeText(this, "Settings & authentication saved successfully", Toast.LENGTH_SHORT).show()
        finish()
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        if (item.itemId == android.R.id.home) {
            onBackPressedDispatcher.onBackPressed()
            return true
        }
        return super.onOptionsItemSelected(item)
    }
}
