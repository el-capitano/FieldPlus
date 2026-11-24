package com.fieldplus.app.ui.submissions

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.MenuItem
import android.view.View
import android.widget.Toast
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.fieldplus.app.R
import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.fieldplus.app.data.repository.SubmissionRepository
import com.fieldplus.app.databinding.ActivitySubmissionsBinding
import com.fieldplus.app.ui.formfill.DynamicFormActivity
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.launch
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import android.graphics.Color
import com.fieldplus.app.ui.map.JourneyMapActivity
import com.fieldplus.app.data.model.JourneyGeoUtils
import com.fieldplus.app.data.model.JourneyData
import java.text.SimpleDateFormat
import java.util.*

class SubmissionsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySubmissionsBinding
    private lateinit var submissionRepository: SubmissionRepository
    private lateinit var adapter: SubmissionsAdapter
    private var allSubmissionsList = listOf<SubmissionEntity>()
    private var currentFilter: String = "ALL"

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        binding = ActivitySubmissionsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Android 15 / API 35+ Edge-to-Edge window insets handling
        ViewCompat.setOnApplyWindowInsetsListener(binding.rootLayout) { _, insets ->
            val statusBars = insets.getInsets(WindowInsetsCompat.Type.statusBars())
            val navBars = insets.getInsets(WindowInsetsCompat.Type.navigationBars())
            binding.appBarLayout.updatePadding(top = statusBars.top)
            binding.bottomContainer.updatePadding(bottom = navBars.bottom + 12)
            insets
        }

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)

        submissionRepository = SubmissionRepository(this)

        setupRecyclerView()
        setupFilterChips()
        setupObservers()

        binding.btnSyncPendingNow.setOnClickListener {
            performSync()
        }

        binding.btnCancelSync.setOnClickListener {
            com.fieldplus.app.worker.SyncWorker.cancelImmediateSync(this)
            Toast.makeText(this, "Sync cancelled", Toast.LENGTH_SHORT).show()
            binding.layoutOverallSyncProgress.visibility = View.GONE
            binding.btnSyncPendingNow.isEnabled = true
            binding.btnSyncPendingNow.text = "Sync Pending Submissions Now"
        }
    }

    private fun setupRecyclerView() {
        adapter = SubmissionsAdapter(
            onEditClick = { sub ->
                if (sub.status == SubmissionEntity.STATUS_SYNCED) {
                    showSyncedDetailsDialog(sub)
                } else {
                    val intent = Intent(this, DynamicFormActivity::class.java).apply {
                        putExtra(DynamicFormActivity.EXTRA_FORM_ID, sub.formId)
                        putExtra(DynamicFormActivity.EXTRA_SUBMISSION_UUID, sub.submissionUuid)
                    }
                    startActivity(intent)
                }
            },
            onDeleteClick = { sub ->
                AlertDialog.Builder(this)
                    .setTitle("Delete Submission?")
                    .setMessage("Are you sure you want to delete this submission (${sub.submissionUuid})? Local attachments will be removed.")
                    .setPositiveButton("Delete") { _, _ ->
                        lifecycleScope.launch {
                            submissionRepository.deleteSubmission(sub.submissionUuid)
                            Toast.makeText(this@SubmissionsActivity, "Deleted", Toast.LENGTH_SHORT).show()
                        }
                    }
                    .setNegativeButton("Cancel", null)
                    .show()
            }
        )

        binding.rvSubmissions.layoutManager = LinearLayoutManager(this)
        binding.rvSubmissions.adapter = adapter
    }

    private fun setupFilterChips() {
        binding.chipGroupFilter.setOnCheckedStateChangeListener { _, checkedIds ->
            currentFilter = when {
                checkedIds.contains(R.id.chipPending) -> "PENDING_OR_ERROR"
                checkedIds.contains(R.id.chipDrafts) -> SubmissionEntity.STATUS_DRAFT
                checkedIds.contains(R.id.chipSynced) -> SubmissionEntity.STATUS_SYNCED
                else -> "ALL"
            }
            applyFilter()
        }
    }



    private fun applyFilter() {
        val filtered = when (currentFilter) {
            "ALL" -> allSubmissionsList
            "PENDING_OR_ERROR" -> allSubmissionsList.filter {
                it.status == SubmissionEntity.STATUS_PENDING_SYNC || it.status == SubmissionEntity.STATUS_ERROR
            }
            else -> allSubmissionsList.filter { it.status == currentFilter }
        }

        adapter.submitList(filtered)
        if (filtered.isEmpty()) {
            binding.tvEmptySubmissions.visibility = View.VISIBLE
        } else {
            binding.tvEmptySubmissions.visibility = View.GONE
        }
    }

    private fun setupObservers() {
        submissionRepository.getAllSubmissions().observe(this) { list ->
            allSubmissionsList = list ?: emptyList()
            applyFilter()
        }

        // Collect real-time progress broadcast from SyncWorker
        lifecycleScope.launch {
            com.fieldplus.app.worker.SyncWorker.progressFlow.collect { progress ->
                handleSyncProgressUpdate(progress)
            }
        }

        // Observe WorkManager OneTimeWork state
        androidx.work.WorkManager.getInstance(this)
            .getWorkInfosForUniqueWorkLiveData(com.fieldplus.app.worker.SyncWorker.ONE_TIME_WORK_NAME)
            .observe(this) { workInfoList ->
                val workInfo = workInfoList?.firstOrNull()
                if (workInfo != null) {
                    val isRunning = workInfo.state == androidx.work.WorkInfo.State.RUNNING
                    if (isRunning) {
                        val progressData = workInfo.progress
                        val statusText = progressData.getString(com.fieldplus.app.worker.SyncWorker.KEY_STATUS_TEXT)
                        val percent = progressData.getInt(com.fieldplus.app.worker.SyncWorker.KEY_PERCENT, 0)
                        val uuid = progressData.getString(com.fieldplus.app.worker.SyncWorker.KEY_SUBMISSION_UUID) ?: ""
                        val title = progressData.getString(com.fieldplus.app.worker.SyncWorker.KEY_FORM_TITLE) ?: ""
                        val itemIdx = progressData.getInt(com.fieldplus.app.worker.SyncWorker.KEY_CURRENT_INDEX, 0)
                        val total = progressData.getInt(com.fieldplus.app.worker.SyncWorker.KEY_TOTAL_ITEMS, 0)

                        if (total > 0 && statusText != null) {
                            handleSyncProgressUpdate(
                                com.fieldplus.app.data.model.SyncProgressUpdate(
                                    currentSubmissionUuid = uuid,
                                    currentFormTitle = title,
                                    currentItemIndex = itemIdx,
                                    totalItems = total,
                                    percent = percent,
                                    statusText = statusText,
                                    isComplete = false
                                )
                            )
                        }
                    } else {
                        handleSyncProgressUpdate(
                            com.fieldplus.app.data.model.SyncProgressUpdate(
                                currentSubmissionUuid = "",
                                currentFormTitle = "",
                                currentItemIndex = 0,
                                totalItems = 0,
                                percent = 100,
                                statusText = "Sync finished",
                                isComplete = true
                            )
                        )
                    }
                }
            }
    }

    private fun handleSyncProgressUpdate(progress: com.fieldplus.app.data.model.SyncProgressUpdate) {
        if (progress.isComplete || progress.statusText.contains("finished", ignoreCase = true) || progress.statusText.contains("cancelled", ignoreCase = true)) {
            binding.layoutOverallSyncProgress.visibility = View.GONE
            binding.btnSyncPendingNow.isEnabled = true
            binding.btnSyncPendingNow.text = "Sync Pending Submissions Now"
            adapter.updateProgress(progress)
        } else {
            binding.layoutOverallSyncProgress.visibility = View.VISIBLE
            binding.progressOverallSync.setProgress(progress.percent, true)
            binding.tvOverallSyncPercent.text = "${progress.percent}%"
            binding.tvOverallSyncStatus.text = "(${progress.currentItemIndex}/${progress.totalItems}) ${progress.statusText}"
            binding.btnSyncPendingNow.isEnabled = false
            binding.btnSyncPendingNow.text = "Syncing in Background (${progress.percent}%)..."
            adapter.updateProgress(progress)
        }
    }

    private fun performSync() {
        lifecycleScope.launch {
            val pendingCount = submissionRepository.getPendingCountDirect()
            if (pendingCount <= 0) {
                Toast.makeText(this@SubmissionsActivity, "Nothing to sync — all submissions are already synced", Toast.LENGTH_SHORT).show()
                binding.layoutOverallSyncProgress.visibility = View.GONE
                binding.btnSyncPendingNow.isEnabled = true
                binding.btnSyncPendingNow.text = "Sync All Pending"
                return@launch
            }

            Toast.makeText(this@SubmissionsActivity, "Syncing $pendingCount pending submission(s)...", Toast.LENGTH_SHORT).show()
            binding.layoutOverallSyncProgress.visibility = View.VISIBLE
            binding.btnSyncPendingNow.isEnabled = false
            binding.btnSyncPendingNow.text = "Uploading to Server..."
            com.fieldplus.app.worker.SyncWorker.enqueueImmediateSync(this@SubmissionsActivity)
        }
    }

    override fun onCreateOptionsMenu(menu: android.view.Menu?): Boolean {
        menu?.add(0, 101, 0, "Clear Synced Submissions")
        menu?.add(0, 102, 1, "Clear All Submissions")
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        when (item.itemId) {
            android.R.id.home -> {
                onBackPressedDispatcher.onBackPressed()
                return true
            }
            101 -> {
                AlertDialog.Builder(this)
                    .setTitle("Clear Synced Submissions?")
                    .setMessage("Remove all successfully uploaded submissions from local storage? Submissions already uploaded remain safe on the server.")
                    .setPositiveButton("Clear Synced") { _, _ ->
                        lifecycleScope.launch {
                            val cleared = submissionRepository.clearSyncedSubmissions()
                            Toast.makeText(this@SubmissionsActivity, "Cleared $cleared synced submission(s)", Toast.LENGTH_SHORT).show()
                        }
                    }
                    .setNegativeButton("Cancel", null)
                    .show()
                return true
            }
            102 -> {
                AlertDialog.Builder(this)
                    .setTitle("Clear All Local Submissions?")
                    .setMessage("Are you sure you want to remove ALL local submissions (including any un-synced drafts and pending records)? This cannot be undone.")
                    .setPositiveButton("Clear All") { _, _ ->
                        lifecycleScope.launch {
                            val cleared = submissionRepository.clearAllSubmissions()
                            Toast.makeText(this@SubmissionsActivity, "Cleared $cleared submission(s)", Toast.LENGTH_SHORT).show()
                        }
                    }
                    .setNegativeButton("Cancel", null)
                    .show()
                return true
            }
        }
        return super.onOptionsItemSelected(item)
    }

    private fun showSyncedDetailsDialog(sub: SubmissionEntity) {
        lifecycleScope.launch {
            val attachments = submissionRepository.getAttachments(sub.submissionUuid)
            showRichSubmissionDetailsDialog(sub, attachments)
        }
    }

    private fun showRichSubmissionDetailsDialog(sub: SubmissionEntity, attachments: List<com.fieldplus.app.data.local.entity.AttachmentEntity>) {
        val dateStr = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date(sub.updatedAt))

        val scrollView = android.widget.ScrollView(this)
        val mainLayout = android.widget.LinearLayout(this).apply {
            orientation = android.widget.LinearLayout.VERTICAL
            val pad = (16 * resources.displayMetrics.density).toInt()
            setPadding(pad, pad, pad, pad)
        }
        scrollView.addView(mainLayout)

        // Subtitle info
        val tvHeader = android.widget.TextView(this).apply {
            text = "Surveyor: ${sub.submitterName ?: "Field Staff"}\nDate: $dateStr\nStatus: ${sub.status}"
            setTextColor(getColor(R.color.text_primary))
            textSize = 13f
            setLineSpacing(4f, 1f)
        }
        mainLayout.addView(tvHeader)

        if (!sub.errorMessage.isNullOrBlank()) {
            val tvError = android.widget.TextView(this).apply {
                text = "⚠️ Sync Error: ${sub.errorMessage}"
                setTextColor(Color.parseColor("#DC2626"))
                textSize = 12f
                setTypeface(null, android.graphics.Typeface.BOLD)
                setPadding(0, (6 * resources.displayMetrics.density).toInt(), 0, (6 * resources.displayMetrics.density).toInt())
            }
            mainLayout.addView(tvError)
        }

        // GPS Coordinates
        if (sub.latitude != null && sub.longitude != null) {
            val tvGps = android.widget.TextView(this).apply {
                text = String.format(Locale.US, "📍 Coordinates: %.6f, %.6f (±%.1fm)", sub.latitude, sub.longitude, sub.accuracy ?: 0f)
                setTextColor(Color.parseColor("#15803D"))
                textSize = 13f
                setPadding(0, (6 * resources.displayMetrics.density).toInt(), 0, (6 * resources.displayMetrics.density).toInt())
            }
            mainLayout.addView(tvGps)
        }

        // Export PDF Report Action Button
        val btnGeneratePdf = com.google.android.material.button.MaterialButton(this).apply {
            text = "📄 Generate Official PDF Report"
            setBackgroundColor(getColor(R.color.primary))
            setTextColor(Color.WHITE)
            cornerRadius = (8 * resources.displayMetrics.density).toInt()
            val lp = android.widget.LinearLayout.LayoutParams(
                android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply {
                setMargins(0, (10 * resources.displayMetrics.density).toInt(), 0, (6 * resources.displayMetrics.density).toInt())
            }
            layoutParams = lp
            setOnClickListener {
                lifecycleScope.launch {
                    try {
                        val pdfFile = com.fieldplus.app.util.PdfReportGenerator.generateInspectionReport(
                            this@SubmissionsActivity,
                            sub,
                            attachments
                        )
                        com.fieldplus.app.util.PdfReportGenerator.showPdfOptionsDialog(
                            this@SubmissionsActivity,
                            pdfFile,
                            sub.formTitle
                        )
                    } catch (e: Exception) {
                        Toast.makeText(this@SubmissionsActivity, "PDF error: ${e.message}", Toast.LENGTH_LONG).show()
                    }
                }
            }
        }
        mainLayout.addView(btnGeneratePdf)

        // Media Gallery Section
        if (attachments.isNotEmpty()) {
            val tvMediaTitle = android.widget.TextView(this).apply {
                text = "📸 Captured Media & Evidence (${attachments.size}):"
                textSize = 14f
                setTypeface(null, android.graphics.Typeface.BOLD)
                setTextColor(getColor(R.color.primary))
                setPadding(0, (12 * resources.displayMetrics.density).toInt(), 0, (6 * resources.displayMetrics.density).toInt())
            }
            mainLayout.addView(tvMediaTitle)

            val mediaScroll = android.widget.HorizontalScrollView(this).apply {
                layoutParams = android.widget.LinearLayout.LayoutParams(
                    android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                    android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
                )
            }
            val mediaRow = android.widget.LinearLayout(this).apply {
                orientation = android.widget.LinearLayout.HORIZONTAL
            }
            mediaScroll.addView(mediaRow)

            val cardMargin = (6 * resources.displayMetrics.density).toInt()
            val cardW = (140 * resources.displayMetrics.density).toInt()
            val cardH = (150 * resources.displayMetrics.density).toInt()

            attachments.forEach { att ->
                val card = com.google.android.material.card.MaterialCardView(this).apply {
                    val lp = android.widget.LinearLayout.LayoutParams(cardW, cardH).apply {
                        setMargins(0, 0, cardMargin, 0)
                    }
                    layoutParams = lp
                    radius = 8 * resources.displayMetrics.density
                    strokeColor = getColor(R.color.divider)
                    strokeWidth = (1 * resources.displayMetrics.density).toInt()
                    cardElevation = 2 * resources.displayMetrics.density
                }

                val cardContent = android.widget.LinearLayout(this).apply {
                    orientation = android.widget.LinearLayout.VERTICAL
                    val pad = (4 * resources.displayMetrics.density).toInt()
                    setPadding(pad, pad, pad, pad)
                }

                val file = java.io.File(att.localPath)
                val ivThumb = android.widget.ImageView(this).apply {
                    layoutParams = android.widget.LinearLayout.LayoutParams(
                        android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                        (100 * resources.displayMetrics.density).toInt()
                    )
                    scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
                    setBackgroundColor(Color.parseColor("#0F172A"))
                }

                val tvInfo = android.widget.TextView(this).apply {
                    val sizeKb = if (file.exists()) file.length() / 1024 else 0
                    val typeLabel = when (att.fileType) {
                        "video" -> "🎥 Video (${sizeKb / 1024}MB)"
                        "signature" -> "✍️ Signature"
                        "audio" -> "🎙️ Voice Memo (${sizeKb}KB)"
                        else -> "📷 Photo (${sizeKb}KB)"
                    }
                    text = typeLabel
                    textSize = 10f
                    setTextColor(getColor(R.color.text_primary))
                    gravity = android.view.Gravity.CENTER
                    setPadding(0, (4 * resources.displayMetrics.density).toInt(), 0, 0)
                }

                if (file.exists()) {
                    if (att.fileType == "video") {
                        try {
                            val retriever = android.media.MediaMetadataRetriever()
                            retriever.setDataSource(file.absolutePath)
                            val thumb = retriever.frameAtTime
                            if (thumb != null) ivThumb.setImageBitmap(thumb)
                            retriever.release()
                        } catch (_: Exception) {}
                    } else if (att.fileType == "audio") {
                        ivThumb.setImageResource(android.R.drawable.ic_btn_speak_now)
                        ivThumb.scaleType = android.widget.ImageView.ScaleType.CENTER_INSIDE
                        ivThumb.setBackgroundColor(Color.parseColor("#1E293B"))
                    } else {
                        try {
                            val bmp = android.graphics.BitmapFactory.decodeFile(file.absolutePath)
                            if (bmp != null) ivThumb.setImageBitmap(bmp)
                        } catch (_: Exception) {}
                    }

                    card.setOnClickListener {
                        try {
                            val uri = androidx.core.content.FileProvider.getUriForFile(
                                this,
                                "${packageName}.fileprovider",
                                file
                            )
                            val mime = when (att.fileType) {
                                "video" -> "video/*"
                                "audio" -> "audio/*"
                                else -> "image/*"
                            }
                            val intent = Intent(Intent.ACTION_VIEW).apply {
                                setDataAndType(uri, mime)
                                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                            }
                            startActivity(intent)
                        } catch (e: Exception) {
                            Toast.makeText(this, "Cannot open media: ${e.message}", Toast.LENGTH_SHORT).show()
                        }
                    }
                }

                cardContent.addView(ivThumb)
                cardContent.addView(tvInfo)
                card.addView(cardContent)
                mediaRow.addView(card)
            }

            mainLayout.addView(mediaScroll)
        }

        // Answers breakdown
        val tvAnswersTitle = android.widget.TextView(this).apply {
            text = "📋 Survey Audit Answers:"
            textSize = 14f
            setTypeface(null, android.graphics.Typeface.BOLD)
            setTextColor(getColor(R.color.text_primary))
            setPadding(0, (14 * resources.displayMetrics.density).toInt(), 0, (6 * resources.displayMetrics.density).toInt())
        }
        mainLayout.addView(tvAnswersTitle)

        val answersBuilder = StringBuilder()
        try {
            val type = object : TypeToken<Map<String, Any>>() {}.type
            val answers: Map<String, Any>? = Gson().fromJson(sub.answersJson, type)
            answers?.forEach { (k, v) ->
                val displayVal = when (v) {
                    is Map<*, *> -> {
                        val pts = (v["journey_path"] as? List<*>) ?: (v["points"] as? List<*>)
                        if (!pts.isNullOrEmpty()) {
                            val totalMeters = (v["distance_meters"] as? Number)?.toDouble() ?: (v["distanceMeters"] as? Number)?.toDouble() ?: 0.0
                            val distStr = if (totalMeters >= 1000.0) String.format(Locale.US, "%.2f km", totalMeters / 1000.0) else String.format(Locale.US, "%.0f m", totalMeters)
                            val sb = StringBuilder("🗺️ GPS Journey Track (${pts.size} waypoints • $distStr)\n")
                            pts.take(10).forEachIndexed { idx, pRaw ->
                                if (pRaw is Map<*, *>) {
                                    val pLat = (pRaw["lat"] as? Number)?.toDouble() ?: 0.0
                                    val pLng = (pRaw["lng"] as? Number)?.toDouble() ?: 0.0
                                    val pTime = pRaw["timestamp"]?.toString()?.substringAfter("T")?.substringBefore(".") ?: pRaw["timestamp"]?.toString() ?: ""
                                    val pArrow = pRaw["arrow"]?.toString() ?: "📍"
                                    val pDir = pRaw["direction"]?.toString() ?: ""
                                    val pDist = (pRaw["distance_from_prev"] as? Number)?.toDouble()
                                    val distSuffix = if (pDist != null && pDist > 0) " (+" + String.format(Locale.US, "%.0fm", pDist) + ")" else ""
                                    if (idx == 0) {
                                        sb.append("   🏁 Start: $pTime • Coords: $pLat, $pLng\n")
                                    } else {
                                        sb.append("   $pArrow Pt #${idx + 1}: $pTime • $pDir$distSuffix\n")
                                    }
                                }
                            }
                            if (pts.size > 10) {
                                sb.append("   ... and +${pts.size - 10} more waypoints (tap Map button below to view all)\n")
                            }
                            sb.toString().trim()
                        } else if (v.containsKey("path")) {
                            "Attachment: ${java.io.File(v["path"].toString()).name}"
                        } else {
                            v.entries.joinToString(", ") { "${it.key}: ${it.value}" }
                        }
                    }
                    is List<*> -> {
                        if (v.isNotEmpty() && v.first() is Map<*, *> && (v.first() as Map<*, *>).containsKey("path")) {
                            "${v.size} file(s) attached"
                        } else {
                            v.joinToString(", ")
                        }
                    }
                    else -> v.toString()
                }
                answersBuilder.append("• ").append(k).append(": ").append(displayVal).append("\n\n")
            }
        } catch (e: Exception) {
            answersBuilder.append(sub.answersJson)
        }

        val tvAnswersBody = android.widget.TextView(this).apply {
            text = answersBuilder.toString().trim()
            textSize = 12f
            setTextColor(getColor(R.color.text_secondary))
        }
        mainLayout.addView(tvAnswersBody)

        val builder = AlertDialog.Builder(this)
            .setTitle(sub.formTitle)
            .setView(scrollView)
            .setPositiveButton("Close", null)

        var embeddedJourneyData: JourneyData? = null
        try {
            val mapType = object : TypeToken<Map<String, Any>>() {}.type
            val answersMap: Map<String, Any>? = Gson().fromJson(sub.answersJson, mapType)
            answersMap?.values?.forEach { v ->
                if (v is Map<*, *>) {
                    val pts = (v["journey_path"] as? List<*>) ?: (v["points"] as? List<*>)
                    if (!pts.isNullOrEmpty()) {
                        val parsedPoints = mutableListOf<com.fieldplus.app.data.model.JourneyPoint>()
                        pts.forEach { pRaw ->
                            if (pRaw is Map<*, *>) {
                                val pLat = (pRaw["lat"] as? Number)?.toDouble() ?: 0.0
                                val pLng = (pRaw["lng"] as? Number)?.toDouble() ?: 0.0
                                val pAlt = (pRaw["alt"] as? Number)?.toDouble()
                                val pAcc = (pRaw["accuracy"] as? Number)?.toFloat()
                                val pTime = pRaw["timestamp"]?.toString()
                                val pBearing = (pRaw["bearing"] as? Number)?.toFloat()
                                val pSpeed = (pRaw["speed"] as? Number)?.toFloat()
                                val pDir = pRaw["direction"]?.toString()
                                val pArrow = pRaw["arrow"]?.toString()
                                val pDist = (pRaw["distance_from_prev"] as? Number)?.toDouble()
                                parsedPoints.add(
                                    com.fieldplus.app.data.model.JourneyPoint(
                                        lat = pLat, lng = pLng, alt = pAlt, accuracy = pAcc,
                                        timestamp = pTime, bearing = pBearing, speed = pSpeed,
                                        direction = pDir, arrow = pArrow, distanceFromPrev = pDist
                                    )
                                )
                            }
                        }
                        if (parsedPoints.isNotEmpty()) {
                            val totalDist = (v["distance_meters"] as? Number)?.toDouble()
                                ?: (v["distanceMeters"] as? Number)?.toDouble() ?: 0.0
                            val durSec = (v["duration_seconds"] as? Number)?.toLong()
                                ?: (v["durationSeconds"] as? Number)?.toLong() ?: 0L
                            val startT = v["start_time"]?.toString() ?: v["startTime"]?.toString()
                            val endT = v["end_time"]?.toString() ?: v["endTime"]?.toString()
                            embeddedJourneyData = JourneyData(
                                points = parsedPoints,
                                startTime = startT,
                                endTime = endT,
                                durationSeconds = durSec,
                                distanceMeters = totalDist
                            )
                        }
                    }
                }
            }
        } catch (_: Exception) {}

        if (embeddedJourneyData != null && embeddedJourneyData!!.points.isNotEmpty()) {
            builder.setNeutralButton("🗺️ Interactive Track (${embeddedJourneyData!!.points.size} pts)") { _, _ ->
                val intent = Intent(this, JourneyMapActivity::class.java).apply {
                    putExtra(JourneyMapActivity.EXTRA_JOURNEY_JSON, Gson().toJson(embeddedJourneyData))
                    putExtra(JourneyMapActivity.EXTRA_FORM_TITLE, sub.formTitle)
                    putExtra(JourneyMapActivity.EXTRA_FIELD_LABEL, "GPS Journey Track")
                }
                startActivity(intent)
            }
        } else if (sub.latitude != null && sub.longitude != null) {
            builder.setNeutralButton("Open in Maps") { _, _ ->
                com.fieldplus.app.util.MapHelper.showMapProviderChooser(
                    this,
                    sub.latitude,
                    sub.longitude,
                    sub.formTitle
                )
            }
        }

        builder.setNegativeButton("Edit Copy") { _, _ ->
            val intent = Intent(this, DynamicFormActivity::class.java).apply {
                putExtra(DynamicFormActivity.EXTRA_FORM_ID, sub.formId)
                putExtra(DynamicFormActivity.EXTRA_SUBMISSION_UUID, sub.submissionUuid)
            }
            startActivity(intent)
        }

        builder.show()
    }
}
