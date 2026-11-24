package com.fieldplus.app.ui.formfill

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.MediaStore
import android.provider.Settings
import android.view.MenuItem
import android.widget.Toast
import android.graphics.Color
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import com.fieldplus.app.data.local.entity.AttachmentEntity
import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.fieldplus.app.data.model.FormSchema
import com.fieldplus.app.data.model.GpsCoordinate
import com.fieldplus.app.data.model.JourneyData
import com.fieldplus.app.data.model.JourneyPoint
import com.fieldplus.app.data.repository.FormRepository
import com.fieldplus.app.data.repository.SubmissionRepository
import com.fieldplus.app.databinding.ActivityDynamicFormBinding
import com.fieldplus.app.ui.widget.GpsCaptureHelper
import com.fieldplus.app.util.FileUtils
import com.fieldplus.app.util.PreferenceManager
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.launch
import java.io.File
import java.util.Locale
import java.util.UUID

class DynamicFormActivity : AppCompatActivity() {

    private lateinit var binding: ActivityDynamicFormBinding
    private lateinit var formRepository: FormRepository
    private lateinit var submissionRepository: SubmissionRepository
    private lateinit var prefs: PreferenceManager
    private val gson = Gson()

    private var currentFormId: String = ""
    private var currentSubmissionUuid: String? = null
    private var existingSubmissionEntity: SubmissionEntity? = null
    private var currentSchema: FormSchema? = null
    private var initialAnswers: Map<String, Any>? = null
    private var initialAttachments: List<AttachmentEntity>? = null

    private lateinit var formRenderer: DynamicFormRenderer
    private var gpsHelper: GpsCaptureHelper? = null

    // Location Permission Handling
    private var pendingGpsAction: (() -> Unit)? = null
    private val locationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] == true
        val coarseGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        if (fineGranted || coarseGranted) {
            val action = pendingGpsAction
            pendingGpsAction = null
            action?.invoke()
        } else {
            pendingGpsAction = null
            AlertDialog.Builder(this)
                .setTitle("Location Permission Required")
                .setMessage("GPS hardware permission is required to capture real field coordinates. Please enable Location in app settings.")
                .setPositiveButton("Open Settings") { _, _ ->
                    val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                        data = Uri.fromParts("package", packageName, null)
                    }
                    startActivity(intent)
                }
                .setNegativeButton("Cancel", null)
                .show()
        }
    }

    // Camera & Media handling
    private var activeFieldIdForPhoto: String? = null
    private var isMultiPhotoActive: Boolean = false
    private var currentPhotoFile: File? = null

    private var pendingCameraAction: (() -> Unit)? = null
    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { isGranted ->
        if (isGranted) {
            val action = pendingCameraAction
            pendingCameraAction = null
            action?.invoke()
        } else {
            pendingCameraAction = null
            AlertDialog.Builder(this)
                .setTitle("Camera Permission Required")
                .setMessage("Camera permission is required to capture site photos and videos. Please enable Camera in App Settings.")
                .setPositiveButton("Open Settings") { _, _ ->
                    val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                        data = Uri.fromParts("package", packageName, null)
                    }
                    startActivity(intent)
                }
                .setNegativeButton("Cancel", null)
                .show()
        }
    }

    private fun checkCameraPermissionAndRun(action: () -> Unit) {
        if (androidx.core.content.ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == android.content.pm.PackageManager.PERMISSION_GRANTED) {
            action()
        } else {
            pendingCameraAction = action
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    private val takePictureLauncher = registerForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        if (success && currentPhotoFile != null && currentPhotoFile!!.exists() && currentPhotoFile!!.length() > 0 && activeFieldIdForPhoto != null) {
            FileUtils.compressImageFile(currentPhotoFile!!)
            if (isMultiPhotoActive) {
                formRenderer.addMultiPhotoForField(activeFieldIdForPhoto!!, currentPhotoFile!!)
                Toast.makeText(this, "Photo added to gallery", Toast.LENGTH_SHORT).show()
            } else {
                formRenderer.setFileForField(activeFieldIdForPhoto!!, currentPhotoFile!!, "image")
                Toast.makeText(this, "Photo attached successfully", Toast.LENGTH_SHORT).show()
            }
        } else if (!success) {
            Toast.makeText(this, "Photo capture canceled", Toast.LENGTH_SHORT).show()
        }
    }

    private val pickImageLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null && activeFieldIdForPhoto != null) {
            val destFile = FileUtils.createMediaFile(this, "gallery_${activeFieldIdForPhoto}", "jpg")
            if (FileUtils.copyUriToFile(this, uri, destFile)) {
                FileUtils.compressImageFile(destFile)
                if (isMultiPhotoActive) {
                    formRenderer.addMultiPhotoForField(activeFieldIdForPhoto!!, destFile)
                    Toast.makeText(this, "Photo added to gallery", Toast.LENGTH_SHORT).show()
                } else {
                    formRenderer.setFileForField(activeFieldIdForPhoto!!, destFile, "image")
                    Toast.makeText(this, "Image selected from gallery", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    // Video handling
    private var activeFieldIdForVideo: String? = null
    private var currentVideoFile: File? = null

    private val recordVideoLauncher: androidx.activity.result.ActivityResultLauncher<Intent> =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK && currentVideoFile != null && currentVideoFile!!.exists() && currentVideoFile!!.length() > 0 && activeFieldIdForVideo != null) {
                val videoSize = currentVideoFile!!.length()
                val maxSizeBytes = 25 * 1024 * 1024L
                if (videoSize > maxSizeBytes) {
                    val sizeMb = videoSize / (1024.0 * 1024.0)
                    AlertDialog.Builder(this)
                        .setTitle(String.format(Locale.US, "Video Too Large (%.1f MB)", sizeMb))
                        .setMessage(String.format(Locale.US, "The recorded video is %.1f MB, which exceeds the server upload limit of 25 MB.\n\nUploading this video will be rejected by the server with error '403 Payload too big'. Would you like to re-record a shorter clip (under 60-90 seconds) or keep it anyway?", sizeMb))
                        .setPositiveButton("Re-record") { _, _ ->
                            val fId = activeFieldIdForVideo!!
                            currentVideoFile?.delete()
                            currentVideoFile = null
                            launchRecordVideo(fId)
                        }
                        .setNegativeButton("Discard") { _, _ ->
                            currentVideoFile?.delete()
                            currentVideoFile = null
                        }
                        .setNeutralButton("Keep Anyway") { _, _ ->
                            formRenderer.setVideoForField(activeFieldIdForVideo!!, currentVideoFile!!)
                        }
                        .show()
                } else {
                    formRenderer.setVideoForField(activeFieldIdForVideo!!, currentVideoFile!!)
                    Toast.makeText(this, "Video recorded successfully", Toast.LENGTH_SHORT).show()
                }
            } else if (result.resultCode != RESULT_OK) {
                Toast.makeText(this, "Video recording canceled", Toast.LENGTH_SHORT).show()
            }
        }

    private val pickVideoLauncher: androidx.activity.result.ActivityResultLauncher<String> =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
            if (uri != null && activeFieldIdForVideo != null) {
                val destFile = FileUtils.createMediaFile(this, "video_${activeFieldIdForVideo}", "mp4")
                if (FileUtils.copyUriToFile(this, uri, destFile)) {
                    val videoSize = destFile.length()
                    val maxSizeBytes = 25 * 1024 * 1024L
                    if (videoSize > maxSizeBytes) {
                        val sizeMb = videoSize / (1024.0 * 1024.0)
                        AlertDialog.Builder(this)
                            .setTitle(String.format(Locale.US, "Video Too Large (%.1f MB)", sizeMb))
                            .setMessage(String.format(Locale.US, "The selected video is %.1f MB, which exceeds the server upload limit of 25 MB.\n\nUploading this video will be rejected by the server with error '403 Payload too big'. Would you like to choose another smaller video file?", sizeMb))
                            .setPositiveButton("Choose Another") { _, _ ->
                                val fId = activeFieldIdForVideo
                                destFile.delete()
                                if (fId != null) {
                                    launchPickVideo(fId)
                                }
                            }
                            .setNegativeButton("Discard") { _, _ ->
                                destFile.delete()
                            }
                            .setNeutralButton("Keep Anyway") { _, _ ->
                                formRenderer.setVideoForField(activeFieldIdForVideo!!, destFile)
                            }
                            .show()
                    } else {
                        formRenderer.setVideoForField(activeFieldIdForVideo!!, destFile)
                        Toast.makeText(this, "Video selected from gallery", Toast.LENGTH_SHORT).show()
                    }
                }
            }
        }

    private fun launchPickVideo(fieldId: String) {
        try {
            activeFieldIdForVideo = fieldId
            pickVideoLauncher.launch("video/*")
        } catch (e: Exception) {
            Toast.makeText(this, "Could not open video picker: ${e.message}", Toast.LENGTH_SHORT).show()
        }
    }

    private fun launchRecordVideo(fieldId: String) {
        checkCameraPermissionAndRun {
            try {
                activeFieldIdForVideo = fieldId
                val videoFile = FileUtils.createMediaFile(this, "video_${fieldId}", "mp4")
                currentVideoFile = videoFile
                val videoUri = FileProvider.getUriForFile(
                    this,
                    "${applicationContext.packageName}.fileprovider",
                    videoFile
                )
                val intent = Intent(MediaStore.ACTION_VIDEO_CAPTURE).apply {
                    putExtra(MediaStore.EXTRA_OUTPUT, videoUri)
                    putExtra(MediaStore.EXTRA_VIDEO_QUALITY, 0) // 0 = standard/low quality to avoid massive payloads
                    putExtra(MediaStore.EXTRA_SIZE_LIMIT, 25 * 1024 * 1024L) // 25 MB limit for camera app
                    putExtra(MediaStore.EXTRA_DURATION_LIMIT, 90) // 90 seconds limit
                    addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
                }
                recordVideoLauncher.launch(intent)
            } catch (e: Exception) {
                showCameraErrorDialog("Unable to start video recording. The camera may be busy or another app is using it.")
            }
        }
    }

    fun lockCurrentGpsFix(): GpsCoordinate? {
        return gpsHelper?.lockCurrentFix()
    }

    fun requestRealGpsCapture(
        onProgress: (status: String, candidate: GpsCoordinate?) -> Unit,
        onSuccess: (GpsCoordinate) -> Unit,
        onError: (String) -> Unit
    ) {
        val helper = gpsHelper ?: GpsCaptureHelper(this).also { gpsHelper = it }

        if (!helper.hasLocationPermission()) {
            pendingGpsAction = {
                requestRealGpsCapture(onProgress, onSuccess, onError)
            }
            locationPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
                )
            )
            return
        }

        if (!helper.isGpsProviderEnabled()) {
            AlertDialog.Builder(this)
                .setTitle("GPS Turned Off")
                .setMessage("Device GPS / Location services are turned off. Please enable GPS in device settings to capture real coordinates.")
                .setPositiveButton("Open Location Settings") { _, _ ->
                    startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
                }
                .setNegativeButton("Cancel") { _, _ ->
                    onError("Location services disabled.")
                }
                .show()
            return
        }

        helper.captureRealLocation(
            targetAccuracyMeters = 20.0f,
            timeoutSeconds = 10,
            onProgress = onProgress,
            onSuccess = onSuccess,
            onError = onError
        )
    }

    private fun showCameraErrorDialog(message: String) {
        AlertDialog.Builder(this)
            .setTitle("Camera Unavailable")
            .setMessage(message)
            .setPositiveButton("OK", null)
            .show()
    }

    override fun onDestroy() {
        super.onDestroy()
        gpsHelper?.stopActiveCapture()
        if (::formRenderer.isInitialized) {
            formRenderer.stopAllActiveTracking()
            formRenderer.stopAllActiveMedia()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        binding = ActivityDynamicFormBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Intercept back navigation to protect unsaved form changes & active GPS tracking
        onBackPressedDispatcher.addCallback(this, object : androidx.activity.OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                handleUserExitRequest()
            }
        })

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
        binding.appBarLayout.setLiftOnScroll(false)

        formRepository = FormRepository(this)
        submissionRepository = SubmissionRepository(this)
        prefs = PreferenceManager(this)

        currentFormId = intent.getStringExtra(EXTRA_FORM_ID) ?: ""
        currentSubmissionUuid = intent.getStringExtra(EXTRA_SUBMISSION_UUID)

        if (currentFormId.isBlank()) {
            Toast.makeText(this, "Error: Missing Form ID", Toast.LENGTH_SHORT).show()
            finish()
            return
        }

        formRenderer = DynamicFormRenderer(
            activity = this,
            onTakePhotoRequested = { fieldId ->
                checkCameraPermissionAndRun {
                    try {
                        activeFieldIdForPhoto = fieldId
                        isMultiPhotoActive = false
                        val photoFile = FileUtils.createMediaFile(this, "photo_${fieldId}", "jpg")
                        currentPhotoFile = photoFile
                        val photoUri = FileProvider.getUriForFile(
                            this,
                            "${applicationContext.packageName}.fileprovider",
                            photoFile
                        )
                        takePictureLauncher.launch(photoUri)
                    } catch (e: Exception) {
                        showCameraErrorDialog("Unable to launch camera. The camera app might be busy with another process or unavailable.")
                    }
                }
            },
            onPickGalleryRequested = { fieldId ->
                try {
                    activeFieldIdForPhoto = fieldId
                    isMultiPhotoActive = false
                    pickImageLauncher.launch("image/*")
                } catch (e: Exception) {
                    Toast.makeText(this, "Could not open gallery: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            },
            onAddMultiPhotoRequested = { fieldId, fromCamera ->
                activeFieldIdForPhoto = fieldId
                isMultiPhotoActive = true
                if (fromCamera) {
                    checkCameraPermissionAndRun {
                        try {
                            val photoFile = FileUtils.createMediaFile(this, "multi_${fieldId}", "jpg")
                            currentPhotoFile = photoFile
                            val photoUri = FileProvider.getUriForFile(
                                this,
                                "${applicationContext.packageName}.fileprovider",
                                photoFile
                            )
                            takePictureLauncher.launch(photoUri)
                        } catch (e: Exception) {
                            showCameraErrorDialog("Unable to launch camera. The camera hardware may be busy or unavailable.")
                        }
                    }
                } else {
                    try {
                        pickImageLauncher.launch("image/*")
                    } catch (e: Exception) {
                        Toast.makeText(this, "Could not open gallery: ${e.message}", Toast.LENGTH_SHORT).show()
                    }
                }
            },
            onRecordVideoRequested = { fieldId ->
                launchRecordVideo(fieldId)
            },
            onPickVideoRequested = { fieldId ->
                launchPickVideo(fieldId)
            }
        ).apply {
            onFormValueChanged = {
                updateCompletionProgress()
            }
        }

        loadFormData()
        setupButtons()
    }

    private fun updateCompletionProgress() {
        val stats = formRenderer.getProgressStats()
        binding.formProgressBar.setProgress(stats.percentage, true)
        binding.tvProgressPercent.text = "${stats.percentage}%"
        val reqInfo = if (stats.totalRequired > 0) " (${stats.answeredRequired}/${stats.totalRequired} required)" else ""
        binding.tvProgressLabel.text = "${stats.answeredVisible} of ${stats.totalVisible} answered$reqInfo"
    }

    private fun loadFormData() {
        lifecycleScope.launch {
            val formEntity = formRepository.getFormById(currentFormId)
            if (formEntity == null) {
                Toast.makeText(this@DynamicFormActivity, "Form template not found locally", Toast.LENGTH_SHORT).show()
                finish()
                return@launch
            }

            currentSchema = gson.fromJson(formEntity.schemaJson, FormSchema::class.java)
            binding.tvFormTitle.text = currentSchema?.title ?: formEntity.title
            binding.tvFormDesc.text = currentSchema?.description ?: "Field Survey Form"

            // Check if resuming an existing submission/draft
            if (!currentSubmissionUuid.isNullOrBlank()) {
                val subEntity = submissionRepository.getSubmissionByUuid(currentSubmissionUuid!!)
                existingSubmissionEntity = subEntity
                if (subEntity != null) {
                    val type = object : TypeToken<Map<String, Any>>() {}.type
                    initialAnswers = gson.fromJson(subEntity.answersJson, type)
                    initialAttachments = submissionRepository.getAttachments(currentSubmissionUuid!!)
                }
            }

            currentSchema?.let { schema ->
                formRenderer.render(
                    container = binding.formContainer,
                    schema = schema,
                    initialAnswers = initialAnswers,
                    existingAttachments = initialAttachments
                )
                updateCompletionProgress()
            }
        }
    }

    private fun setupButtons() {
        binding.btnSaveDraft.setOnClickListener {
            saveSubmission(isDraft = true)
        }

        binding.btnFinalize.setOnClickListener {
            val errors = formRenderer.validate()
            if (errors.isNotEmpty()) {
                val message = errors.take(5).joinToString("\n• ", prefix = "• ")
                AlertDialog.Builder(this)
                    .setTitle("Incomplete Form")
                    .setMessage("Please complete all required fields:\n\n$message")
                    .setPositiveButton("OK", null)
                    .show()
            } else {
                saveSubmission(isDraft = false)
            }
        }
    }

    private fun saveSubmission(isDraft: Boolean) {
        if (::formRenderer.isInitialized && formRenderer.hasActiveJourneyTracking()) {
            formRenderer.stopAllActiveTracking()
        }
        val answers = formRenderer.collectAnswers()
        val uuid = currentSubmissionUuid ?: "sub_${UUID.randomUUID().toString().replace("-", "").substring(0, 16)}"
        currentSubmissionUuid = uuid
        val answersJson = gson.toJson(answers)
        val attachments = formRenderer.getCollectedAttachments(uuid)

        // Find GPS coordinates if present
        var lat: Double? = null
        var lng: Double? = null
        var acc: Float? = null
        var alt: Double? = null

        answers.values.forEach { v ->
            if (lat == null && v is GpsCoordinate) {
                lat = v.latitude
                lng = v.longitude
                acc = v.accuracy
                alt = v.altitude
            } else if (lat == null && v is JourneyData) {
                if (v.points.isNotEmpty()) {
                    val p = v.points.first()
                    lat = p.lat
                    lng = p.lng
                    acc = p.accuracy
                    alt = p.alt
                }
            } else if (lat == null && v is Map<*, *>) {
                val l = (v["latitude"] as? Number)?.toDouble()
                val ln = (v["longitude"] as? Number)?.toDouble()
                if (l != null && ln != null) {
                    lat = l
                    lng = ln
                    acc = (v["accuracy"] as? Number)?.toFloat()
                    alt = (v["altitude"] as? Number)?.toDouble()
                } else {
                    val pts = (v["journey_path"] as? List<*>) ?: (v["points"] as? List<*>)
                    if (!pts.isNullOrEmpty()) {
                        val firstPt = pts[0] as? Map<*, *>
                        val pLat = (firstPt?.get("lat") as? Number)?.toDouble()
                        val pLng = (firstPt?.get("lng") as? Number)?.toDouble()
                        if (pLat != null && pLng != null) {
                            lat = pLat
                            lng = pLng
                            acc = (firstPt?.get("accuracy") as? Number)?.toFloat()
                            alt = (firstPt?.get("alt") as? Number)?.toDouble()
                        }
                    }
                }
            }
        }

        if (lat == null && existingSubmissionEntity != null) {
            lat = existingSubmissionEntity?.latitude
            lng = existingSubmissionEntity?.longitude
            acc = existingSubmissionEntity?.accuracy
            alt = existingSubmissionEntity?.altitude
        }

        val status = if (isDraft) SubmissionEntity.STATUS_DRAFT else SubmissionEntity.STATUS_PENDING_SYNC

        lifecycleScope.launch {
            val submission = SubmissionEntity(
                submissionUuid = uuid,
                formId = currentFormId,
                formTitle = currentSchema?.title ?: "Field Visit",
                submitterName = prefs.surveyorName,
                status = status,
                answersJson = answersJson,
                latitude = lat,
                longitude = lng,
                accuracy = acc,
                altitude = alt
            )

            submissionRepository.saveSubmission(submission, attachments)

            if (isDraft) {
                Toast.makeText(this@DynamicFormActivity, "Draft saved locally!", Toast.LENGTH_SHORT).show()
                finish()
            } else {
                AlertDialog.Builder(this@DynamicFormActivity)
                    .setTitle("Survey Finalized")
                    .setMessage("Survey saved and queued in Outbox. You can upload now or sync later.")
                    .setPositiveButton("Sync Now") { _, _ ->
                        lifecycleScope.launch {
                            Toast.makeText(this@DynamicFormActivity, "Uploading...", Toast.LENGTH_SHORT).show()
                            val syncRes = submissionRepository.syncAllPending()
                            Toast.makeText(this@DynamicFormActivity, syncRes.message, Toast.LENGTH_LONG).show()
                            finish()
                        }
                    }
                    .setNegativeButton("Sync Later") { _, _ ->
                        Toast.makeText(this@DynamicFormActivity, "Saved to Outbox. You can sync anytime.", Toast.LENGTH_SHORT).show()
                        finish()
                    }
                    .setCancelable(false)
                    .show()
            }
        }
    }

    private fun handleUserExitRequest() {
        if (!::formRenderer.isInitialized) {
            finish()
            return
        }

        val isTrackingActive = formRenderer.hasActiveJourneyTracking()
        val hasTrackPoints = formRenderer.hasAnyJourneyPoints()
        val hasUnsavedAnswers = formRenderer.collectAnswers().isNotEmpty()

        if (isTrackingActive) {
            AlertDialog.Builder(this)
                .setTitle("GPS Journey Recording Active")
                .setMessage("A GPS journey track is currently being recorded for this form. What would you like to do?")
                .setPositiveButton("Save Draft & Exit") { _, _ ->
                    saveSubmission(isDraft = true)
                }
                .setNeutralButton("Keep Recording", null)
                .setNegativeButton("Discard Journey & Exit") { _, _ ->
                    formRenderer.stopAllActiveTracking()
                    finish()
                }
                .show()
            return
        }

        if (hasTrackPoints || hasUnsavedAnswers) {
            AlertDialog.Builder(this)
                .setTitle("Unsaved Form Data")
                .setMessage("You have unsaved changes in this form. Would you like to save a draft before leaving?")
                .setPositiveButton("Save Draft & Exit") { _, _ ->
                    saveSubmission(isDraft = true)
                }
                .setNeutralButton("Cancel", null)
                .setNegativeButton("Discard Changes") { _, _ ->
                    finish()
                }
                .show()
            return
        }

        finish()
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        if (item.itemId == android.R.id.home) {
            onBackPressedDispatcher.onBackPressed()
            return true
        }
        return super.onOptionsItemSelected(item)
    }

    companion object {
        const val EXTRA_FORM_ID = "extra_form_id"
        const val EXTRA_SUBMISSION_UUID = "extra_submission_uuid"
    }
}
