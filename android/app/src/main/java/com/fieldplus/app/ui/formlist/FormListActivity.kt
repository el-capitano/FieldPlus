package com.fieldplus.app.ui.formlist

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import android.graphics.Color
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.fieldplus.app.data.repository.FormRepository
import com.fieldplus.app.data.repository.SubmissionRepository
import com.fieldplus.app.databinding.ActivityFormListBinding
import com.fieldplus.app.ui.formfill.DynamicFormActivity
import com.fieldplus.app.ui.settings.SettingsActivity
import com.fieldplus.app.ui.submissions.SubmissionsActivity
import kotlinx.coroutines.launch

import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding

class FormListActivity : AppCompatActivity() {

    private lateinit var binding: ActivityFormListBinding
    private lateinit var formRepository: FormRepository
    private lateinit var submissionRepository: SubmissionRepository
    private lateinit var adapter: FormAdapter
    private var allFormsList: List<com.fieldplus.app.data.local.entity.FormEntity> = emptyList()

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineLocation = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
        val camera = permissions[Manifest.permission.CAMERA] ?: false
        if (fineLocation && camera) {
            Toast.makeText(this, "Permissions granted for GPS & Camera", Toast.LENGTH_SHORT).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        binding = ActivityFormListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Android 15 / API 35+ Edge-to-Edge window insets handling
        ViewCompat.setOnApplyWindowInsetsListener(binding.rootLayout) { _, insets ->
            val statusBars = insets.getInsets(WindowInsetsCompat.Type.statusBars())
            val navBars = insets.getInsets(WindowInsetsCompat.Type.navigationBars())
            binding.appBarLayout.updatePadding(top = statusBars.top)
            binding.bottomContainer.updatePadding(bottom = navBars.bottom + 12)
            insets
        }

        formRepository = FormRepository(this)
        submissionRepository = SubmissionRepository(this)

        binding.toolbar.subtitle = "v${com.fieldplus.app.BuildConfig.VERSION_NAME} • Forms Catalog"

        setupRecyclerView()
        setupObservers()
        setupListeners()
        requestAppPermissions()

        // Initial refresh if empty
        refreshForms(showToast = false)
    }

    private fun setupRecyclerView() {
        adapter = FormAdapter(
            onFormSelected = { form ->
                val intent = Intent(this, DynamicFormActivity::class.java).apply {
                    putExtra(DynamicFormActivity.EXTRA_FORM_ID, form.id)
                }
                startActivity(intent)
            },
            onDeleteForm = { form ->
                val options = arrayOf(
                    "Remove Form from Device",
                    "Clear Submissions for this Form"
                )
                androidx.appcompat.app.AlertDialog.Builder(this)
                    .setTitle(form.title)
                    .setItems(options) { _, which ->
                        when (which) {
                            0 -> {
                                androidx.appcompat.app.AlertDialog.Builder(this)
                                    .setTitle("Remove Local Form")
                                    .setMessage("Remove '${form.title}' from your device? You can re-download it anytime if it is still available on the server.")
                                    .setPositiveButton("Remove") { _, _ ->
                                        lifecycleScope.launch {
                                            formRepository.deleteForm(form.id)
                                            Toast.makeText(this@FormListActivity, "Removed '${form.title}'", Toast.LENGTH_SHORT).show()
                                        }
                                    }
                                    .setNegativeButton("Cancel", null)
                                    .show()
                            }
                            1 -> {
                                androidx.appcompat.app.AlertDialog.Builder(this)
                                    .setTitle("Clear Submissions?")
                                    .setMessage("Clear all local submissions and drafts for '${form.title}'?")
                                    .setPositiveButton("Clear Submissions") { _, _ ->
                                        lifecycleScope.launch {
                                            val count = submissionRepository.clearSubmissionsForForm(form.id)
                                            Toast.makeText(this@FormListActivity, "Cleared $count submission(s) for '${form.title}'", Toast.LENGTH_SHORT).show()
                                        }
                                    }
                                    .setNegativeButton("Cancel", null)
                                    .show()
                            }
                        }
                    }
                    .setNegativeButton("Cancel", null)
                    .show()
            }
        )
        binding.rvForms.layoutManager = LinearLayoutManager(this)
        binding.rvForms.adapter = adapter
    }

    private fun setupObservers() {
        formRepository.getAllForms().observe(this) { forms ->
            allFormsList = forms ?: emptyList()
            applyFilter()
        }

        submissionRepository.getPendingCount().observe(this) { count ->
            val pendingCount = count ?: 0
            if (pendingCount > 0) {
                binding.tvServerStatus.text = "⚠️ $pendingCount submission(s) pending sync in Outbox"
                binding.btnSyncNow.text = "Sync All ($pendingCount)"
            } else {
                binding.tvServerStatus.text = "All surveys synced • Offline ready"
                binding.btnSyncNow.text = "Sync All"
            }
        }
        // Observe background sync progress
        lifecycleScope.launch {
            com.fieldplus.app.worker.SyncWorker.progressFlow.collect { progress ->
                handleSyncProgress(progress)
            }
        }

        androidx.work.WorkManager.getInstance(this)
            .getWorkInfosForUniqueWorkLiveData(com.fieldplus.app.worker.SyncWorker.ONE_TIME_WORK_NAME)
            .observe(this) { workInfoList ->
                val workInfo = workInfoList?.firstOrNull()
                if (workInfo != null) {
                    if (workInfo.state == androidx.work.WorkInfo.State.RUNNING) {
                        val total = workInfo.progress.getInt(com.fieldplus.app.worker.SyncWorker.KEY_TOTAL_ITEMS, 0)
                        val pct = workInfo.progress.getInt(com.fieldplus.app.worker.SyncWorker.KEY_PERCENT, 0)
                        val text = workInfo.progress.getString(com.fieldplus.app.worker.SyncWorker.KEY_STATUS_TEXT)
                        
                        if (total > 0 && text != null) {
                            binding.progressQuickSync.visibility = View.VISIBLE
                            binding.btnCancelQuickSync.visibility = View.VISIBLE
                            binding.progressQuickSync.setProgress(pct, true)
                            binding.tvServerStatus.text = "Syncing ($pct%): $text"
                            binding.btnQuickSync.isEnabled = false
                            binding.btnSyncNow.isEnabled = false
                        }
                    } else {
                        binding.progressQuickSync.visibility = View.GONE
                        binding.btnCancelQuickSync.visibility = View.GONE
                        binding.btnQuickSync.isEnabled = true
                        binding.btnSyncNow.isEnabled = true
                    }
                }
            }
    }

    private fun applyFilter() {
        val query = binding.etSearchForms.text?.toString()?.trim()?.lowercase().orEmpty()
        val filtered = if (query.isEmpty()) {
            allFormsList
        } else {
            allFormsList.filter {
                it.title.lowercase().contains(query) ||
                (it.category?.lowercase()?.contains(query) == true) ||
                (it.description?.lowercase()?.contains(query) == true) ||
                it.id.lowercase().contains(query)
            }
        }
        adapter.submitList(filtered)
        binding.tvEmptyState.visibility = if (filtered.isEmpty()) View.VISIBLE else View.GONE
    }

    private fun setupListeners() {
        binding.etSearchForms.addTextChangedListener(object : android.text.TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {
                applyFilter()
            }
            override fun afterTextChanged(s: android.text.Editable?) {}
        })

        binding.swipeRefresh.setOnRefreshListener {
            refreshForms(showToast = true)
        }

        binding.btnRefresh.setOnClickListener {
            binding.swipeRefresh.isRefreshing = true
            refreshForms(showToast = true)
        }

        binding.btnSettings.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }

        binding.btnViewSubmissions.setOnClickListener {
            startActivity(Intent(this, SubmissionsActivity::class.java))
        }

        binding.btnQuickSync.setOnClickListener {
            performSync()
        }

        binding.btnSyncNow.setOnClickListener {
            performSync()
        }

        binding.btnCancelQuickSync.setOnClickListener {
            com.fieldplus.app.worker.SyncWorker.cancelImmediateSync(this)
            Toast.makeText(this, "Sync cancelled", Toast.LENGTH_SHORT).show()
            binding.progressQuickSync.visibility = View.GONE
            binding.btnCancelQuickSync.visibility = View.GONE
            binding.btnQuickSync.isEnabled = true
            binding.btnSyncNow.isEnabled = true
            binding.tvServerStatus.text = "Sync cancelled by user"
        }
    }

    private fun refreshForms(showToast: Boolean) {
        lifecycleScope.launch {
            val result = formRepository.refreshForms()
            binding.swipeRefresh.isRefreshing = false

            if (result.isSuccess) {
                val count = result.getOrDefault(0)
                binding.tvServerStatus.text = "Synced $count form(s) from server • Online"
                if (showToast) {
                    Toast.makeText(this@FormListActivity, "Synced $count form template(s) from server", Toast.LENGTH_SHORT).show()
                }
            } else {
                val err = result.exceptionOrNull()?.message ?: "Unknown error"
                binding.tvServerStatus.text = "⚠️ Offline: $err"
                if (showToast) {
                    Toast.makeText(this@FormListActivity, "Offline mode: ($err)", Toast.LENGTH_LONG).show()
                }
            }
        }
    }

    private fun handleSyncProgress(progress: com.fieldplus.app.data.model.SyncProgressUpdate) {
        if (progress.isComplete || progress.statusText.contains("finished", ignoreCase = true) || progress.statusText.contains("cancelled", ignoreCase = true)) {
            binding.progressQuickSync.visibility = View.GONE
            binding.btnCancelQuickSync.visibility = View.GONE
            binding.btnQuickSync.isEnabled = true
            binding.btnSyncNow.isEnabled = true
        } else {
            binding.progressQuickSync.visibility = View.VISIBLE
            binding.btnCancelQuickSync.visibility = View.VISIBLE
            binding.progressQuickSync.setProgress(progress.percent, true)
            binding.tvServerStatus.text = "Syncing (${progress.percent}%): ${progress.statusText}"
            binding.btnQuickSync.isEnabled = false
            binding.btnSyncNow.isEnabled = false
        }
    }

    private fun performSync() {
        lifecycleScope.launch {
            val pendingCount = submissionRepository.getPendingCountDirect()
            if (pendingCount <= 0) {
                Toast.makeText(this@FormListActivity, "Nothing to sync — all forms and surveys are up to date", Toast.LENGTH_SHORT).show()
                binding.progressQuickSync.visibility = View.GONE
                binding.tvServerStatus.text = "All surveys synced • Offline ready"
                binding.btnQuickSync.isEnabled = true
                binding.btnSyncNow.isEnabled = true
                return@launch
            }

            Toast.makeText(this@FormListActivity, "Syncing $pendingCount pending submission(s)...", Toast.LENGTH_SHORT).show()
            binding.progressQuickSync.visibility = View.VISIBLE
            binding.tvServerStatus.text = "Starting sync ($pendingCount item(s))..."
            com.fieldplus.app.worker.SyncWorker.enqueueImmediateSync(this@FormListActivity)
        }
    }

    private fun requestAppPermissions() {
        val permissions = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
            Manifest.permission.CAMERA,
            Manifest.permission.RECORD_AUDIO
        )

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            permissions.add(Manifest.permission.POST_NOTIFICATIONS)
        }

        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.S_V2) {
            permissions.add(Manifest.permission.READ_EXTERNAL_STORAGE)
        }

        val needed = permissions.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }

        if (needed.isNotEmpty()) {
            permissionLauncher.launch(needed.toTypedArray())
        }
    }

    override fun onCreateOptionsMenu(menu: android.view.Menu?): Boolean {
        menu?.add(0, 201, 0, "Download Blank Forms (Sync)")?.setIcon(android.R.drawable.ic_menu_rotate)
        menu?.add(0, 202, 1, "Clear Local Forms Cache")?.setIcon(android.R.drawable.ic_menu_delete)
        return true
    }

    override fun onOptionsItemSelected(item: android.view.MenuItem): Boolean {
        when (item.itemId) {
            201 -> {
                binding.swipeRefresh.isRefreshing = true
                refreshForms(showToast = true)
                return true
            }
            202 -> {
                androidx.appcompat.app.AlertDialog.Builder(this)
                    .setTitle("Clear All Local Forms?")
                    .setMessage("Remove all downloaded blank form templates from this device? You can re-download active forms from the server at any time.")
                    .setPositiveButton("Clear Forms") { _, _ ->
                        lifecycleScope.launch {
                            allFormsList.forEach { formRepository.deleteForm(it.id) }
                            Toast.makeText(this@FormListActivity, "Cleared local forms cache", Toast.LENGTH_SHORT).show()
                        }
                    }
                    .setNegativeButton("Cancel", null)
                    .show()
                return true
            }
        }
        return super.onOptionsItemSelected(item)
    }
}
