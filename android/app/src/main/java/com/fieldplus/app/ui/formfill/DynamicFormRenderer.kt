package com.fieldplus.app.ui.formfill

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Context
import android.graphics.BitmapFactory
import android.text.Editable
import android.text.InputType
import android.text.TextWatcher
import android.view.LayoutInflater
import android.view.View
import android.widget.*
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.setPadding
import com.fieldplus.app.R
import com.fieldplus.app.data.local.entity.AttachmentEntity
import com.fieldplus.app.data.model.*
import com.fieldplus.app.databinding.WidgetFileFieldBinding
import com.fieldplus.app.databinding.WidgetGpsFieldBinding
import com.fieldplus.app.databinding.WidgetSignatureFieldBinding
import com.fieldplus.app.databinding.WidgetMultiplePhotosFieldBinding
import com.fieldplus.app.databinding.WidgetVideoFieldBinding
import com.fieldplus.app.databinding.WidgetAudioFieldBinding
import com.fieldplus.app.databinding.WidgetJourneyGpsFieldBinding
import com.fieldplus.app.databinding.ItemMultiPhotoThumbnailBinding
import android.media.MediaRecorder
import android.media.MediaPlayer
import com.fieldplus.app.ui.widget.GpsCaptureHelper
import com.fieldplus.app.util.FileUtils
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.materialswitch.MaterialSwitch
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
import android.content.res.ColorStateList
import android.graphics.Color
import android.content.Intent
import com.fieldplus.app.service.JourneyTrackingService
import com.fieldplus.app.ui.map.JourneyMapActivity
import java.io.File
import java.text.SimpleDateFormat
import java.util.*

data class FormProgressStats(
    val totalVisible: Int,
    val answeredVisible: Int,
    val totalRequired: Int,
    val answeredRequired: Int
) {
    val percentage: Int get() = if (totalVisible > 0) (answeredVisible * 100) / totalVisible else 100
}

class DynamicFormRenderer(
    private val activity: AppCompatActivity,
    private val onTakePhotoRequested: (fieldId: String) -> Unit,
    private val onPickGalleryRequested: (fieldId: String) -> Unit,
    private val onAddMultiPhotoRequested: ((fieldId: String, fromCamera: Boolean) -> Unit)? = null,
    private val onRecordVideoRequested: ((fieldId: String) -> Unit)? = null,
    private val onPickVideoRequested: ((fieldId: String) -> Unit)? = null
) {

    var onFormValueChanged: (() -> Unit)? = null

    private val context: Context = activity
    private val gpsHelper = GpsCaptureHelper(context)

    // Store field state accessors
    private val fieldGetters = mutableMapOf<String, () -> Any?>()
    private val fieldValidators = mutableMapOf<String, () -> String?>()
    private val pendingAttachments = mutableMapOf<String, AttachmentEntity>()
    private val multiAttachments = mutableMapOf<String, MutableList<AttachmentEntity>>()

    // For reactive skip logic
    private val fieldViews = mutableMapOf<String, View>()
    private val fieldDefinitions = mutableMapOf<String, FormField>()

    // View bindings
    private val fileFieldBindings = mutableMapOf<String, WidgetFileFieldBinding>()
    private val gpsFieldBindings = mutableMapOf<String, WidgetGpsFieldBinding>()
    private val sigFieldBindings = mutableMapOf<String, WidgetSignatureFieldBinding>()
    private val multiPhotoBindings = mutableMapOf<String, WidgetMultiplePhotosFieldBinding>()
    private val videoBindings = mutableMapOf<String, WidgetVideoFieldBinding>()
    private val audioBindings = mutableMapOf<String, WidgetAudioFieldBinding>()
    private val journeyBindings = mutableMapOf<String, WidgetJourneyGpsFieldBinding>()
    private var currentSchema: FormSchema? = null

    private var activeMediaRecorder: MediaRecorder? = null
    private var activeRecordingFieldId: String? = null
    private var recordingStartTime = 0L
    private val recordingHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private var recordingTimerRunnable: Runnable? = null

    private var activeMediaPlayer: MediaPlayer? = null
    private var activePlayingFieldId: String? = null
    private val playbackHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private var playbackProgressRunnable: Runnable? = null

    fun render(
        container: LinearLayout,
        schema: FormSchema,
        initialAnswers: Map<String, Any>? = null,
        existingAttachments: List<AttachmentEntity>? = null
    ) {
        currentSchema = schema
        container.removeAllViews()
        fieldGetters.clear()
        fieldValidators.clear()
        fieldViews.clear()
        fieldDefinitions.clear()
        pendingAttachments.clear()
        multiAttachments.clear()
        fileFieldBindings.clear()
        videoBindings.clear()
        multiPhotoBindings.clear()
        gpsFieldBindings.clear()
        sigFieldBindings.clear()
        journeyBindings.clear()

        val schemaFields = schema.sections.flatMap { it.fields }.associateBy { it.id }

        // 1. Index existing attachments from Room database
        existingAttachments?.forEach { att ->
            val fieldDef = schemaFields[att.fieldId]
            if (fieldDef != null) {
                if (fieldDef.type == FieldType.MULTIPLE_PHOTOS) {
                    multiAttachments.getOrPut(att.fieldId) { mutableListOf() }.add(att)
                } else {
                    pendingAttachments[att.fieldId] = att
                }
            } else {
                // If not an exact match, check for multi-photo pattern: fieldId_timestamp
                val match = Regex("""^(.+)_(\d{10,})$""").matchEntire(att.fieldId)
                if (match != null) {
                    val baseId = match.groupValues[1]
                    multiAttachments.getOrPut(baseId) { mutableListOf() }.add(att)
                } else {
                    pendingAttachments[att.fieldId] = att
                }
            }
        }

        // 2. Also recover from initialAnswers if DB attachments had not populated them yet
        initialAnswers?.forEach { (fId, v) ->
            val fieldDef = schemaFields[fId] ?: return@forEach
            when (fieldDef.type) {
                FieldType.FILE -> {
                    if (pendingAttachments[fId] == null && v is Map<*, *>) {
                        val path = (v["path"] as? String) ?: (v["localPath"] as? String)
                        if (!path.isNullOrBlank() && File(path).exists()) {
                            pendingAttachments[fId] = AttachmentEntity(
                                submissionUuid = "",
                                fieldId = fId,
                                fileType = "image",
                                localPath = File(path).absolutePath,
                                mimeType = "image/jpeg"
                            )
                        }
                    }
                }
                FieldType.VIDEO -> {
                    if (pendingAttachments[fId] == null && v is Map<*, *>) {
                        val path = (v["path"] as? String) ?: (v["localPath"] as? String)
                        if (!path.isNullOrBlank() && File(path).exists()) {
                            pendingAttachments[fId] = AttachmentEntity(
                                submissionUuid = "",
                                fieldId = fId,
                                fileType = "video",
                                localPath = File(path).absolutePath,
                                mimeType = "video/mp4"
                            )
                        }
                    }
                }
                FieldType.AUDIO -> {
                    if (pendingAttachments[fId] == null && v is Map<*, *>) {
                        val path = (v["path"] as? String) ?: (v["localPath"] as? String)
                        if (!path.isNullOrBlank() && File(path).exists()) {
                            pendingAttachments[fId] = AttachmentEntity(
                                submissionUuid = "",
                                fieldId = fId,
                                fileType = "audio",
                                localPath = File(path).absolutePath,
                                mimeType = "audio/mp4"
                            )
                        }
                    }
                }
                FieldType.SIGNATURE -> {
                    if (pendingAttachments[fId] == null && v is Map<*, *>) {
                        val path = (v["path"] as? String) ?: (v["localPath"] as? String)
                        if (!path.isNullOrBlank() && File(path).exists()) {
                            pendingAttachments[fId] = AttachmentEntity(
                                submissionUuid = "",
                                fieldId = fId,
                                fileType = "signature",
                                localPath = File(path).absolutePath,
                                mimeType = "image/png"
                            )
                        }
                    }
                }
                FieldType.MULTIPLE_PHOTOS -> {
                    if (multiAttachments[fId].isNullOrEmpty() && v is List<*>) {
                        val list = multiAttachments.getOrPut(fId) { mutableListOf() }
                        v.forEachIndexed { idx, item ->
                            val path = (item as? Map<*, *>)?.get("path") as? String
                                ?: (item as? Map<*, *>)?.get("localPath") as? String
                            if (!path.isNullOrBlank() && File(path).exists()) {
                                list.add(
                                    AttachmentEntity(
                                        submissionUuid = "",
                                        fieldId = "${fId}_${System.currentTimeMillis() + idx}",
                                        fileType = "image",
                                        localPath = File(path).absolutePath,
                                        mimeType = "image/jpeg"
                                    )
                                )
                            }
                        }
                    }
                }
            }
        }

        schema.sections.forEachIndexed { sIdx, section ->
            // Section Card
            val sectionCard = MaterialCardView(context).apply {
                radius = dpToPx(12f)
                strokeColor = context.getColor(R.color.divider)
                strokeWidth = dpToPx(1f).toInt()
                cardElevation = dpToPx(1f)
                setCardBackgroundColor(context.getColor(R.color.surface))
                layoutParams = LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT
                ).apply {
                    bottomMargin = dpToPx(16f).toInt()
                }
            }

            val sectionLayout = LinearLayout(context).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(dpToPx(16f).toInt())
            }

            // Section Header
            val tvSectionTitle = TextView(context).apply {
                text = "${sIdx + 1}. ${section.title}"
                textSize = 16f
                setTextColor(context.getColor(R.color.primary))
                setTypeface(null, android.graphics.Typeface.BOLD)
            }
            sectionLayout.addView(tvSectionTitle)

            if (!section.description.isNullOrBlank()) {
                val tvSectionDesc = TextView(context).apply {
                    text = section.description
                    textSize = 12f
                    setTextColor(context.getColor(R.color.text_secondary))
                    setPadding(0, dpToPx(2f).toInt(), 0, dpToPx(8f).toInt())
                }
                sectionLayout.addView(tvSectionDesc)
            }

            // Render each field inside section
            section.fields.forEach { field ->
                fieldDefinitions[field.id] = field
                val fieldView = renderField(field, initialAnswers?.get(field.id))
                fieldViews[field.id] = fieldView
                sectionLayout.addView(fieldView)
            }

            sectionCard.addView(sectionLayout)
            container.addView(sectionCard)
        }

        // Initial pass for skip logic
        evaluateDependencies()
    }

    private fun renderField(field: FormField, initialValue: Any?): View {
        return when (field.type) {
            FieldType.TEXT -> renderTextInput(field, initialValue, false)
            FieldType.TEXTAREA -> renderTextInput(field, initialValue, true)
            FieldType.NUMBER -> renderNumberInput(field, initialValue)
            FieldType.DATE -> renderDatePicker(field, initialValue)
            FieldType.TIME -> renderTimePicker(field, initialValue)
            FieldType.DROPDOWN -> renderDropdown(field, initialValue)
            FieldType.RADIO -> renderRadioGroup(field, initialValue)
            FieldType.CHECKBOX -> renderCheckboxes(field, initialValue)
            FieldType.SWITCH -> renderSwitch(field, initialValue)
            FieldType.GPS -> renderGpsWidget(field, initialValue)
            FieldType.JOURNEY_GPS -> renderJourneyGpsWidget(field, initialValue)
            FieldType.FILE -> renderFileWidget(field, initialValue)
            FieldType.MULTIPLE_PHOTOS -> renderMultiplePhotosWidget(field, initialValue)
            FieldType.VIDEO -> renderVideoWidget(field, initialValue)
            FieldType.AUDIO -> renderAudioWidget(field, initialValue)
            FieldType.SIGNATURE -> renderSignatureWidget(field, initialValue)
            FieldType.BARCODE -> renderBarcodeInput(field, initialValue)
            else -> renderTextInput(field, initialValue, false)
        }
    }

    // 1. TEXT / TEXTAREA
    private fun renderTextInput(field: FormField, initialValue: Any?, isMultiline: Boolean): View {
        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
            if (!field.help.isNullOrBlank()) {
                helperText = field.help
            }
        }

        val et = TextInputEditText(til.context).apply {
            if (isMultiline) {
                minLines = 3
                maxLines = 6
                inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_MULTI_LINE
            } else {
                inputType = InputType.TYPE_CLASS_TEXT
            }
            initialValue?.let { setText(it.toString()) }
            addTextChangedListener(object : TextWatcher {
                override fun afterTextChanged(s: Editable?) {
                    evaluateDependencies()
                }
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            })
        }
        til.addView(et)

        fieldGetters[field.id] = { et.text?.toString()?.trim() }
        fieldValidators[field.id] = {
            val text = et.text?.toString()?.trim()
            if (field.required && text.isNullOrBlank()) {
                til.error = "This field is required"
                "${field.label} is required"
            } else if (!field.pattern.isNullOrBlank() && !text.isNullOrBlank()) {
                if (!Regex(field.pattern).matches(text)) {
                    til.error = "Invalid format"
                    "${field.label} does not match required pattern"
                } else {
                    til.error = null
                    null
                }
            } else {
                til.error = null
                null
            }
        }

        return til
    }

    // 2. NUMBER with Min/Max Constraints
    private fun renderNumberInput(field: FormField, initialValue: Any?): View {
        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
            val constraintHelp = when {
                field.min != null && field.max != null -> "Range: [${field.min} to ${field.max}]"
                field.min != null -> "Minimum: ${field.min}"
                field.max != null -> "Maximum: ${field.max}"
                else -> field.help
            }
            if (!constraintHelp.isNullOrBlank()) {
                helperText = constraintHelp
            }
        }

        val et = TextInputEditText(til.context).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_FLAG_DECIMAL or InputType.TYPE_NUMBER_FLAG_SIGNED
            initialValue?.let { setText(it.toString()) }
            addTextChangedListener(object : TextWatcher {
                override fun afterTextChanged(s: Editable?) {
                    evaluateDependencies()
                }
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            })
        }
        til.addView(et)

        fieldGetters[field.id] = {
            val text = et.text?.toString()?.trim()
            if (text.isNullOrBlank()) null else text.toDoubleOrNull() ?: text
        }
        fieldValidators[field.id] = {
            val text = et.text?.toString()?.trim()
            if (field.required && text.isNullOrBlank()) {
                til.error = "This number is required"
                "${field.label} is required"
            } else if (!text.isNullOrBlank()) {
                val num = text.toDoubleOrNull()
                if (num == null) {
                    til.error = "Invalid number"
                    "${field.label} must be a valid number"
                } else if (field.min != null && num < field.min) {
                    til.error = "Min value is ${field.min}"
                    "${field.label} must be at least ${field.min}"
                } else if (field.max != null && num > field.max) {
                    til.error = "Max value is ${field.max}"
                    "${field.label} cannot exceed ${field.max}"
                } else {
                    til.error = null
                    null
                }
            } else {
                til.error = null
                null
            }
        }

        return til
    }

    // 3. BARCODE / QR SCANNER FIELD
    private fun renderBarcodeInput(field: FormField, initialValue: Any?): View {
        val layout = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            if (!field.help.isNullOrBlank()) {
                helperText = field.help
            }
        }

        val et = TextInputEditText(til.context).apply {
            inputType = InputType.TYPE_CLASS_TEXT
            initialValue?.let { setText(it.toString()) }
            addTextChangedListener(object : TextWatcher {
                override fun afterTextChanged(s: Editable?) { evaluateDependencies() }
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            })
        }
        til.addView(et)
        layout.addView(til)

        val btnScan = MaterialButton(context, null, com.google.android.material.R.attr.borderlessButtonStyle).apply {
            text = "📷 Scan Barcode / Asset QR Code"
            setOnClickListener {
                val options = arrayOf(
                    "📷 Scan with Camera (Google Code Scanner)",
                    "🏷️ Generate Sample Asset Tag (AST-99214)",
                    "🔢 Generate Sample Serial (SN-774029)"
                )
                AlertDialog.Builder(context)
                    .setTitle("Barcode / QR Scanner")
                    .setItems(options) { _, which ->
                        when (which) {
                            0 -> {
                                try {
                                    val scanner = com.google.mlkit.vision.codescanner.GmsBarcodeScanning.getClient(activity)
                                    scanner.startScan()
                                        .addOnSuccessListener { barcode ->
                                            val scannedVal = barcode.rawValue ?: barcode.displayValue
                                            if (!scannedVal.isNullOrBlank()) {
                                                et.setText(scannedVal)
                                                Toast.makeText(context, "Scanned: $scannedVal", Toast.LENGTH_SHORT).show()
                                            }
                                        }
                                        .addOnCanceledListener {
                                            // User dismissed scanner
                                        }
                                        .addOnFailureListener { e ->
                                            Toast.makeText(context, "Barcode scan error: ${e.message}", Toast.LENGTH_SHORT).show()
                                        }
                                } catch (e: Exception) {
                                    Toast.makeText(context, "Barcode scanner unavailable: ${e.message}", Toast.LENGTH_LONG).show()
                                }
                            }
                            1 -> et.setText("AST-99214")
                            2 -> et.setText("SN-774029")
                        }
                    }
                    .show()
            }
        }
        layout.addView(btnScan)

        fieldGetters[field.id] = { et.text?.toString()?.trim() }
        fieldValidators[field.id] = {
            if (field.required && et.text.isNullOrBlank()) {
                til.error = "Barcode is required"
                "${field.label} is required"
            } else {
                til.error = null
                null
            }
        }
        return layout
    }

    // 4. DATE PICKER
    private fun renderDatePicker(field: FormField, initialValue: Any?): View {
        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
            if (!field.help.isNullOrBlank()) {
                helperText = field.help
            }
        }

        val et = TextInputEditText(til.context).apply {
            isFocusable = false
            isClickable = true
            val defaultDate = initialValue?.toString() ?: SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
            setText(defaultDate)
        }

        et.setOnClickListener {
            val cal = Calendar.getInstance()
            DatePickerDialog(
                context,
                { _, year, month, day ->
                    val selected = String.format(Locale.US, "%04d-%02d-%02d", year, month + 1, day)
                    et.setText(selected)
                    evaluateDependencies()
                },
                cal.get(Calendar.YEAR),
                cal.get(Calendar.MONTH),
                cal.get(Calendar.DAY_OF_MONTH)
            ).show()
        }

        til.addView(et)
        fieldGetters[field.id] = { et.text?.toString()?.trim() }
        fieldValidators[field.id] = {
            if (field.required && et.text.isNullOrBlank()) "${field.label} date is required" else null
        }
        return til
    }

    // 5. TIME PICKER
    private fun renderTimePicker(field: FormField, initialValue: Any?): View {
        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val et = TextInputEditText(til.context).apply {
            isFocusable = false
            isClickable = true
            val defaultTime = initialValue?.toString() ?: SimpleDateFormat("HH:mm", Locale.US).format(Date())
            setText(defaultTime)
        }

        et.setOnClickListener {
            val cal = Calendar.getInstance()
            TimePickerDialog(
                context,
                { _, hourOfDay, minute ->
                    val selected = String.format(Locale.US, "%02d:%02d", hourOfDay, minute)
                    et.setText(selected)
                    evaluateDependencies()
                },
                cal.get(Calendar.HOUR_OF_DAY),
                cal.get(Calendar.MINUTE),
                true
            ).show()
        }

        til.addView(et)
        fieldGetters[field.id] = { et.text?.toString()?.trim() }
        fieldValidators[field.id] = {
            if (field.required && et.text.isNullOrBlank()) "${field.label} time is required" else null
        }
        return til
    }

    // 6. DROPDOWN
    private fun renderDropdown(field: FormField, initialValue: Any?): View {
        val til = TextInputLayout(context).apply {
            hint = field.label + if (field.required) " *" else ""
            boxBackgroundMode = TextInputLayout.BOX_BACKGROUND_OUTLINE
            endIconMode = TextInputLayout.END_ICON_DROPDOWN_MENU
            setBoxCornerRadii(dpToPx(8f), dpToPx(8f), dpToPx(8f), dpToPx(8f))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val options = field.options ?: listOf("Option 1", "Option 2")
        val adapter = ArrayAdapter(context, android.R.layout.simple_dropdown_item_1line, options)

        val actv = AutoCompleteTextView(til.context).apply {
            setAdapter(adapter)
            inputType = InputType.TYPE_NULL
            initialValue?.let { setText(it.toString(), false) }
            setOnItemClickListener { _, _, _, _ ->
                evaluateDependencies()
            }
        }
        til.addView(actv)

        fieldGetters[field.id] = { actv.text?.toString()?.trim() }
        fieldValidators[field.id] = {
            if (field.required && actv.text.isNullOrBlank()) "${field.label} selection is required" else null
        }
        return til
    }

    // 7. RADIO GROUP
    private fun renderRadioGroup(field: FormField, initialValue: Any?): View {
        val layout = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val tvLabel = TextView(context).apply {
            text = field.label + if (field.required) " *" else ""
            textSize = 14f
            setTypeface(null, android.graphics.Typeface.BOLD)
            setTextColor(context.getColor(R.color.text_primary))
        }
        layout.addView(tvLabel)

        val radioGroup = RadioGroup(context)
        val options = field.options ?: listOf("Yes", "No")
        options.forEachIndexed { idx, opt ->
            val rb = com.google.android.material.radiobutton.MaterialRadioButton(context).apply {
                id = View.generateViewId()
                text = opt
                isChecked = (initialValue?.toString() == opt) || (initialValue == null && idx == 0 && field.required)
            }
            radioGroup.addView(rb)
        }
        radioGroup.setOnCheckedChangeListener { _, _ ->
            evaluateDependencies()
        }
        layout.addView(radioGroup)

        fieldGetters[field.id] = {
            val selectedId = radioGroup.checkedRadioButtonId
            if (selectedId != -1) {
                radioGroup.findViewById<RadioButton>(selectedId)?.text?.toString()
            } else null
        }
        fieldValidators[field.id] = {
            if (field.required && radioGroup.checkedRadioButtonId == -1) "${field.label} is required" else null
        }
        return layout
    }

    // 8. CHECKBOXES
    private fun renderCheckboxes(field: FormField, initialValue: Any?): View {
        val layout = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val tvLabel = TextView(context).apply {
            text = field.label + if (field.required) " *" else ""
            textSize = 14f
            setTypeface(null, android.graphics.Typeface.BOLD)
            setTextColor(context.getColor(R.color.text_primary))
        }
        layout.addView(tvLabel)

        val selectedList = mutableListOf<String>()
        if (initialValue is List<*>) {
            selectedList.addAll(initialValue.map { it.toString() })
        } else if (initialValue is String) {
            selectedList.add(initialValue)
        }

        val checkBoxes = mutableListOf<com.google.android.material.checkbox.MaterialCheckBox>()
        val options = field.options ?: listOf("Option A", "Option B")
        options.forEach { opt ->
            val cb = com.google.android.material.checkbox.MaterialCheckBox(context).apply {
                text = opt
                isChecked = selectedList.contains(opt)
                setOnCheckedChangeListener { _, _ -> evaluateDependencies() }
            }
            checkBoxes.add(cb)
            layout.addView(cb)
        }

        fieldGetters[field.id] = {
            checkBoxes.filter { it.isChecked }.map { it.text.toString() }
        }
        fieldValidators[field.id] = {
            if (field.required && checkBoxes.none { it.isChecked }) "${field.label} requires at least one selection" else null
        }
        return layout
    }

    // 9. SWITCH
    private fun renderSwitch(field: FormField, initialValue: Any?): View {
        val layout = LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = android.view.Gravity.CENTER_VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = dpToPx(12f).toInt() }
        }

        val tvLabel = TextView(context).apply {
            layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            text = field.label + if (field.required) " *" else ""
            textSize = 14f
            setTypeface(null, android.graphics.Typeface.BOLD)
            setTextColor(context.getColor(R.color.text_primary))
        }

        val sw = MaterialSwitch(context).apply {
            isChecked = when (initialValue) {
                is Boolean -> initialValue
                is Number -> initialValue.toInt() == 1
                is String -> initialValue.equals("true", true) || initialValue == "1"
                else -> false
            }
            setOnCheckedChangeListener { _, _ ->
                evaluateDependencies()
            }
        }

        layout.addView(tvLabel)
        layout.addView(sw)

        fieldGetters[field.id] = { sw.isChecked }
        fieldValidators[field.id] = { null }
        return layout
    }

    // 10. GPS WIDGET
    private fun renderGpsWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetGpsFieldBinding.inflate(LayoutInflater.from(context))
        gpsFieldBindings[field.id] = binding

        binding.tvGpsLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvGpsHelp.text = field.help
            binding.tvGpsHelp.visibility = View.VISIBLE
        }

        var currentGps: GpsCoordinate? = null

        // Parse initial value if present
        if (initialValue is Map<*, *>) {
            val lat = (initialValue["latitude"] as? Number)?.toDouble()
            val lng = (initialValue["longitude"] as? Number)?.toDouble()
            val acc = (initialValue["accuracy"] as? Number)?.toFloat()
            val alt = (initialValue["altitude"] as? Number)?.toDouble()
            if (lat != null && lng != null) {
                currentGps = GpsCoordinate(lat, lng, acc, alt)
                updateGpsDisplay(binding, currentGps)
            }
        }

        binding.btnCaptureGps.setOnClickListener {
            binding.progressGps.visibility = View.VISIBLE
            binding.btnCaptureGps.isEnabled = false
            binding.btnAcceptFix.visibility = View.GONE
            binding.tvGpsAcquiringProgress.visibility = View.VISIBLE
            binding.tvGpsAcquiringProgress.text = "Acquiring real GPS satellites..."

            if (activity is DynamicFormActivity) {
                activity.requestRealGpsCapture(
                    onProgress = { statusMsg, candidate ->
                        activity.runOnUiThread {
                            binding.tvGpsAcquiringProgress.text = statusMsg
                            if (candidate != null) {
                                updateGpsDisplay(binding, candidate)
                                val accLabel = candidate.accuracy?.let { " (±${it.toInt()}m)" } ?: ""
                                binding.btnAcceptFix.text = "Lock Fix$accLabel"
                                binding.btnAcceptFix.visibility = View.VISIBLE
                            }
                        }
                    },
                    onSuccess = { coord ->
                        activity.runOnUiThread {
                            binding.progressGps.visibility = View.GONE
                            binding.btnCaptureGps.isEnabled = true
                            binding.btnAcceptFix.visibility = View.GONE
                            binding.tvGpsAcquiringProgress.visibility = View.GONE
                            binding.btnClearGps.visibility = View.VISIBLE
                            currentGps = coord
                            updateGpsDisplay(binding, coord)
                            evaluateDependencies()
                            Toast.makeText(context, "Real GPS Location Locked (±${coord.accuracy ?: 5f}m)", Toast.LENGTH_SHORT).show()
                        }
                    },
                    onError = { err ->
                        activity.runOnUiThread {
                            binding.progressGps.visibility = View.GONE
                            binding.btnCaptureGps.isEnabled = true
                            binding.btnAcceptFix.visibility = View.GONE
                            binding.tvGpsAcquiringProgress.visibility = View.GONE
                            AlertDialog.Builder(context)
                                .setTitle("GPS Acquisition Failed")
                                .setMessage(err)
                                .setPositiveButton("OK", null)
                                .show()
                        }
                    }
                )
            } else {
                gpsHelper.captureRealLocation(
                    onProgress = { statusMsg, candidate ->
                        activity.runOnUiThread {
                            binding.tvGpsAcquiringProgress.text = statusMsg
                            if (candidate != null) {
                                updateGpsDisplay(binding, candidate)
                                val accLabel = candidate.accuracy?.let { " (±${it.toInt()}m)" } ?: ""
                                binding.btnAcceptFix.text = "Lock Fix$accLabel"
                                binding.btnAcceptFix.visibility = View.VISIBLE
                            }
                        }
                    },
                    onSuccess = { coord ->
                        activity.runOnUiThread {
                            binding.progressGps.visibility = View.GONE
                            binding.btnCaptureGps.isEnabled = true
                            binding.btnAcceptFix.visibility = View.GONE
                            binding.tvGpsAcquiringProgress.visibility = View.GONE
                            binding.btnClearGps.visibility = View.VISIBLE
                            currentGps = coord
                            updateGpsDisplay(binding, coord)
                            evaluateDependencies()
                        }
                    },
                    onError = { err ->
                        activity.runOnUiThread {
                            binding.progressGps.visibility = View.GONE
                            binding.btnCaptureGps.isEnabled = true
                            binding.btnAcceptFix.visibility = View.GONE
                            binding.tvGpsAcquiringProgress.visibility = View.GONE
                            Toast.makeText(context, err, Toast.LENGTH_LONG).show()
                        }
                    }
                )
            }
        }

        binding.btnAcceptFix.setOnClickListener {
            val lockedCoord = if (activity is DynamicFormActivity) {
                activity.lockCurrentGpsFix()
            } else {
                gpsHelper.lockCurrentFix()
            }

            if (lockedCoord != null) {
                currentGps = lockedCoord
                binding.progressGps.visibility = View.GONE
                binding.btnCaptureGps.isEnabled = true
                binding.btnAcceptFix.visibility = View.GONE
                binding.tvGpsAcquiringProgress.visibility = View.GONE
                binding.btnClearGps.visibility = View.VISIBLE
                updateGpsDisplay(binding, lockedCoord)
                evaluateDependencies()
                Toast.makeText(context, "GPS Fix Locked Manually (±${lockedCoord.accuracy?.toInt() ?: 10}m)", Toast.LENGTH_SHORT).show()
            }
        }

        binding.btnClearGps.setOnClickListener {
            currentGps = null
            binding.btnAcceptFix.visibility = View.GONE
            binding.tvGpsStatus.text = "No GPS Fix Yet"
            binding.tvGpsStatus.setTextColor(context.getColor(R.color.text_secondary))
            binding.tvGpsStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#E2E8F0"))
            binding.tvGpsCoords.text = "Lat: --, Lng: --"
            binding.tvGpsAccuracy.text = "Accuracy: -- | Altitude: --"
            binding.btnClearGps.visibility = View.GONE
            binding.btnViewOnMap.visibility = View.GONE
            evaluateDependencies()
        }

        fieldGetters[field.id] = { currentGps }
        fieldValidators[field.id] = {
            if (field.required && currentGps == null) "GPS Location fix is required for ${field.label}" else null
        }

        return binding.root
    }

    private fun updateGpsDisplay(binding: WidgetGpsFieldBinding, coord: GpsCoordinate) {
        val acc = coord.accuracy ?: 999f
        when {
            acc <= 5f -> {
                binding.tvGpsStatus.text = String.format(Locale.US, "GPS Fixed • High Accuracy (±%.1fm)", acc)
                binding.tvGpsStatus.setTextColor(Color.parseColor("#15803D"))
                binding.tvGpsStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#DCFCE7"))
            }
            acc <= 15f -> {
                binding.tvGpsStatus.text = String.format(Locale.US, "GPS Fixed • Moderate (±%.1fm)", acc)
                binding.tvGpsStatus.setTextColor(Color.parseColor("#B45309"))
                binding.tvGpsStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#FEF3C7"))
            }
            else -> {
                binding.tvGpsStatus.text = String.format(Locale.US, "GPS Fixed • Low Accuracy (±%.1fm)", acc)
                binding.tvGpsStatus.setTextColor(Color.parseColor("#B91C1C"))
                binding.tvGpsStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#FEE2E2"))
            }
        }
        binding.tvGpsCoords.text = String.format(Locale.US, "Lat: %.6f, Lng: %.6f", coord.latitude, coord.longitude)
        val altStr = if (coord.altitude != null) String.format(Locale.US, " | Altitude: %.1fm", coord.altitude) else ""
        binding.tvGpsAccuracy.text = String.format(Locale.US, "Precision: ±%.1fm%s", acc, altStr)
        binding.btnClearGps.visibility = View.VISIBLE
        binding.btnViewOnMap.visibility = View.VISIBLE
        binding.btnViewOnMap.setOnClickListener {
            com.fieldplus.app.util.MapHelper.showMapProviderChooser(
                context,
                coord.latitude,
                coord.longitude,
                "Survey Location"
            )
        }
    }

    // 11. FILE / PHOTO WIDGET
    private fun renderFileWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetFileFieldBinding.inflate(LayoutInflater.from(context))
        fileFieldBindings[field.id] = binding

        binding.tvFileLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvFileHelp.text = field.help
            binding.tvFileHelp.visibility = View.VISIBLE
        }

        val existingAtt = pendingAttachments[field.id]
        if (existingAtt != null) {
            val file = File(existingAtt.localPath)
            if (file.exists() && file.length() > 0) {
                displayFilePreview(binding, file)
            } else {
                displayMissingMediaWarning(binding, file.name)
            }
        }

        binding.btnTakePhoto.setOnClickListener { onTakePhotoRequested(field.id) }
        binding.btnPickGallery.setOnClickListener { onPickGalleryRequested(field.id) }
        binding.btnRemoveFile.setOnClickListener {
            pendingAttachments.remove(field.id)
            binding.ivFilePreview.visibility = View.GONE
            binding.tvFileInfo.text = "No file attached"
            binding.btnRemoveFile.visibility = View.GONE
            evaluateDependencies()
        }

        fieldGetters[field.id] = {
            pendingAttachments[field.id]?.let { att ->
                mapOf(
                    "path" to att.localPath,
                    "filename" to File(att.localPath).name,
                    "type" to att.fileType
                )
            }
        }
        fieldValidators[field.id] = {
            if (field.required && pendingAttachments[field.id] == null) "Photo attachment required for ${field.label}" else null
        }

        return binding.root
    }

    private fun displayMissingMediaWarning(binding: WidgetFileFieldBinding, fileName: String) {
        binding.ivFilePreview.visibility = View.GONE
        binding.tvFileInfo.text = "⚠️ File missing from device: $fileName (re-take or re-select)"
        binding.tvFileInfo.setTextColor(Color.parseColor("#DC2626"))
        binding.btnRemoveFile.visibility = View.VISIBLE
    }

    fun setFileForField(fieldId: String, file: File, fileType: String = "image") {
        val binding = fileFieldBindings[fieldId]
        if (binding != null) {
            displayFilePreview(binding, file)
        }
        pendingAttachments[fieldId] = AttachmentEntity(
            submissionUuid = "",
            fieldId = fieldId,
            fileType = fileType,
            localPath = file.absolutePath,
            mimeType = if (fileType == "image") "image/jpeg" else "application/octet-stream"
        )
        evaluateDependencies()
        onFormValueChanged?.invoke()
    }

    private fun displayFilePreview(binding: WidgetFileFieldBinding, file: File) {
        if (!file.exists() || file.length() == 0L) {
            displayMissingMediaWarning(binding, file.name)
            return
        }
        try {
            val bitmap = BitmapFactory.decodeFile(file.absolutePath)
            if (bitmap != null) {
                binding.ivFilePreview.setImageBitmap(bitmap)
                binding.ivFilePreview.visibility = View.VISIBLE
            }
            val sizeKb = file.length() / 1024
            binding.tvFileInfo.text = "${file.name} (${sizeKb} KB)"
            binding.tvFileInfo.setTextColor(context.getColor(R.color.text_secondary))
            binding.btnRemoveFile.visibility = View.VISIBLE

            binding.ivFilePreview.setOnClickListener {
                try {
                    val uri = androidx.core.content.FileProvider.getUriForFile(
                        context,
                        "${context.packageName}.fileprovider",
                        file
                    )
                    val intent = android.content.Intent(android.content.Intent.ACTION_VIEW).apply {
                        setDataAndType(uri, "image/*")
                        addFlags(android.content.Intent.FLAG_GRANT_READ_URI_PERMISSION)
                    }
                    context.startActivity(intent)
                } catch (e: Exception) {
                    Toast.makeText(context, "Opening photo: ${file.name}", Toast.LENGTH_SHORT).show()
                }
            }
        } catch (e: Exception) {
            e.printStackTrace()
        }
    }

    // 11b. MULTIPLE PHOTOS WIDGET
    private fun renderMultiplePhotosWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetMultiplePhotosFieldBinding.inflate(LayoutInflater.from(context))
        multiPhotoBindings[field.id] = binding

        binding.tvMultiPhotosLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvMultiPhotosHelp.text = field.help
            binding.tvMultiPhotosHelp.visibility = View.VISIBLE
        }

        val list = multiAttachments.getOrPut(field.id) { mutableListOf() }

        refreshMultiPhotoThumbnails(field.id)

        binding.btnAddMultiPhotoCamera.setOnClickListener {
            onAddMultiPhotoRequested?.invoke(field.id, true)
        }
        binding.btnAddMultiPhotoGallery.setOnClickListener {
            onAddMultiPhotoRequested?.invoke(field.id, false)
        }
        binding.btnClearAllPhotos.setOnClickListener {
            list.clear()
            refreshMultiPhotoThumbnails(field.id)
            evaluateDependencies()
        }

        fieldGetters[field.id] = {
            list.map { att ->
                mapOf(
                    "path" to att.localPath,
                    "filename" to File(att.localPath).name,
                    "type" to "image"
                )
            }
        }
        fieldValidators[field.id] = {
            if (field.required && list.isEmpty()) "${field.label} requires at least one photo" else null
        }

        return binding.root
    }

    private fun refreshMultiPhotoThumbnails(fieldId: String) {
        val binding = multiPhotoBindings[fieldId] ?: return
        val list = multiAttachments[fieldId] ?: mutableListOf()

        binding.llThumbnailsContainer.removeAllViews()
        if (list.isEmpty()) {
            binding.hsvThumbnails.visibility = View.GONE
            binding.tvMultiPhotosEmptyHint.visibility = View.VISIBLE
            binding.btnClearAllPhotos.visibility = View.GONE
            binding.tvPhotoCountBadge.text = "0 photos"
        } else {
            binding.hsvThumbnails.visibility = View.VISIBLE
            binding.tvMultiPhotosEmptyHint.visibility = View.GONE
            binding.btnClearAllPhotos.visibility = View.VISIBLE
            binding.tvPhotoCountBadge.text = "${list.size} photos"

            list.forEachIndexed { idx, att ->
                val thumbBinding = ItemMultiPhotoThumbnailBinding.inflate(LayoutInflater.from(context), binding.llThumbnailsContainer, false)
                val f = File(att.localPath)
                if (f.exists() && f.length() > 0) {
                    try {
                        val bmp = BitmapFactory.decodeFile(att.localPath)
                        if (bmp != null) {
                            thumbBinding.ivThumbnail.setImageBitmap(bmp)
                        }
                    } catch (_: Exception) {}
                } else {
                    thumbBinding.ivThumbnail.setImageResource(android.R.drawable.ic_dialog_alert)
                }

                thumbBinding.btnRemoveThumbnail.setOnClickListener {
                    if (idx < list.size) {
                        list.removeAt(idx)
                        refreshMultiPhotoThumbnails(fieldId)
                        evaluateDependencies()
                    }
                }
                binding.llThumbnailsContainer.addView(thumbBinding.root)
            }
        }
    }

    fun addMultiPhotoForField(fieldId: String, file: File) {
        val list = multiAttachments.getOrPut(fieldId) { mutableListOf() }
        list.add(
            AttachmentEntity(
                submissionUuid = "",
                fieldId = "${fieldId}_${System.currentTimeMillis()}",
                fileType = "image",
                localPath = file.absolutePath,
                mimeType = "image/jpeg"
            )
        )
        refreshMultiPhotoThumbnails(fieldId)
        evaluateDependencies()
    }

    // 11c. VIDEO WIDGET
    private fun renderVideoWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetVideoFieldBinding.inflate(LayoutInflater.from(context))
        videoBindings[field.id] = binding

        binding.tvVideoLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvVideoHelp.text = field.help
            binding.tvVideoHelp.visibility = View.VISIBLE
        }

        val existingAtt = pendingAttachments[field.id]
        if (existingAtt != null) {
            val file = File(existingAtt.localPath)
            if (file.exists() && file.length() > 0) {
                displayVideoPreview(binding, file)
            } else {
                binding.flVideoPreviewContainer.visibility = View.GONE
                binding.tvVideoInfo.text = "⚠️ Video missing from device: ${file.name} (re-record or re-select)"
                binding.tvVideoInfo.setTextColor(Color.parseColor("#DC2626"))
                binding.btnRemoveVideo.visibility = View.VISIBLE
            }
        }

        binding.btnRecordVideo.setOnClickListener {
            onRecordVideoRequested?.invoke(field.id)
        }
        binding.btnPickVideo.setOnClickListener {
            onPickVideoRequested?.invoke(field.id)
        }
        binding.btnRemoveVideo.setOnClickListener {
            pendingAttachments.remove(field.id)
            binding.flVideoPreviewContainer.visibility = View.GONE
            binding.tvVideoInfo.text = "No video recorded yet"
            binding.tvVideoInfo.setTextColor(context.getColor(R.color.text_secondary))
            binding.btnRemoveVideo.visibility = View.GONE
            evaluateDependencies()
        }

        fieldGetters[field.id] = {
            pendingAttachments[field.id]?.let { att ->
                mapOf(
                    "path" to att.localPath,
                    "filename" to File(att.localPath).name,
                    "type" to "video"
                )
            }
        }
        fieldValidators[field.id] = {
            val att = pendingAttachments[field.id]
            if (field.required && att == null) {
                "Video recording required for ${field.label}"
            } else if (att != null) {
                val f = File(att.localPath)
                if (f.exists() && f.length() > 28 * 1024 * 1024L) {
                    val sizeMb = f.length() / (1024.0 * 1024.0)
                    String.format(Locale.US, "Video '%s' is too large (%.1f MB). Maximum allowed size is 25 MB to avoid server upload rejection.", field.label, sizeMb)
                } else null
            } else null
        }

        return binding.root
    }

    fun setVideoForField(fieldId: String, file: File) {
        val binding = videoBindings[fieldId]
        if (binding != null) {
            displayVideoPreview(binding, file)
        }
        pendingAttachments[fieldId] = AttachmentEntity(
            submissionUuid = "",
            fieldId = fieldId,
            fileType = "video",
            localPath = file.absolutePath,
            mimeType = "video/mp4"
        )
        evaluateDependencies()
        onFormValueChanged?.invoke()
    }

    private fun displayVideoPreview(binding: WidgetVideoFieldBinding, file: File) {
        if (!file.exists() || file.length() == 0L) {
            binding.flVideoPreviewContainer.visibility = View.GONE
            binding.tvVideoInfo.text = "⚠️ Video missing from device: ${file.name}"
            binding.tvVideoInfo.setTextColor(Color.parseColor("#DC2626"))
            binding.btnRemoveVideo.visibility = View.VISIBLE
            return
        }
        try {
            // Extract frame thumbnail
            val retriever = android.media.MediaMetadataRetriever()
            retriever.setDataSource(file.absolutePath)
            val thumb = retriever.frameAtTime
            if (thumb != null) {
                binding.ivVideoThumbnail.setImageBitmap(thumb)
            }
            retriever.release()

            val sizeMb = file.length() / (1024.0 * 1024.0)
            if (sizeMb > 25.0) {
                binding.tvVideoInfo.text = String.format(Locale.US, "%s (%.2f MB) ⚠️ Exceeds 25 MB limit", file.name, sizeMb)
                binding.tvVideoInfo.setTextColor(Color.parseColor("#DC2626"))
            } else {
                binding.tvVideoInfo.text = String.format(Locale.US, "%s (%.2f MB)", file.name, sizeMb)
                binding.tvVideoInfo.setTextColor(context.getColor(R.color.text_secondary))
            }
            binding.flVideoPreviewContainer.visibility = View.VISIBLE
            binding.btnRemoveVideo.visibility = View.VISIBLE

            binding.flVideoPreviewContainer.setOnClickListener {
                try {
                    val uri = androidx.core.content.FileProvider.getUriForFile(
                        context,
                        "${context.packageName}.fileprovider",
                        file
                    )
                    val playIntent = android.content.Intent(android.content.Intent.ACTION_VIEW).apply {
                        setDataAndType(uri, "video/*")
                        addFlags(android.content.Intent.FLAG_GRANT_READ_URI_PERMISSION)
                    }
                    context.startActivity(playIntent)
                } catch (e: Exception) {
                    Toast.makeText(context, "Cannot play video: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        } catch (_: Exception) {
            val sizeKb = file.length() / 1024
            binding.tvVideoInfo.text = "${file.name} (${sizeKb} KB)"
            binding.tvVideoInfo.setTextColor(context.getColor(R.color.text_secondary))
            binding.flVideoPreviewContainer.visibility = View.VISIBLE
            binding.btnRemoveVideo.visibility = View.VISIBLE
        }
    }

    // 11c-2. AUDIO MEMO WIDGET
    private fun renderAudioWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetAudioFieldBinding.inflate(LayoutInflater.from(context))
        audioBindings[field.id] = binding

        binding.tvAudioLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvAudioHelp.text = field.help
            binding.tvAudioHelp.visibility = View.VISIBLE
        }

        fun formatMs(ms: Long): String {
            val totalSeconds = (ms / 1000).coerceAtLeast(0)
            val minutes = totalSeconds / 60
            val seconds = totalSeconds % 60
            return String.format(Locale.US, "%02d:%02d", minutes, seconds)
        }

        fun updatePlayerUi(file: File) {
            binding.layoutRecordActions.visibility = View.GONE
            binding.layoutRecordingIndicator.visibility = View.GONE
            binding.layoutAudioPlayer.visibility = View.VISIBLE
            binding.tvAudioFileName.text = file.name
            val sizeKb = (file.length() + 1023) / 1024

            var durationMs = 0L
            try {
                val mmr = android.media.MediaMetadataRetriever()
                mmr.setDataSource(file.absolutePath)
                durationMs = mmr.extractMetadata(android.media.MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: 0L
                mmr.release()
            } catch (_: Exception) {}

            binding.tvAudioDuration.text = "00:00 / ${formatMs(durationMs)} • $sizeKb KB"
            binding.sbAudioProgress.progress = 0
            binding.btnPlayPauseAudio.text = "▶"
        }

        val existingAtt = pendingAttachments[field.id]
        if (existingAtt != null) {
            val file = File(existingAtt.localPath)
            if (file.exists() && file.length() > 0) {
                updatePlayerUi(file)
            }
        }

        // 1. RECORD AUDIO
        binding.btnRecordAudio.setOnClickListener {
            if (androidx.core.content.ContextCompat.checkSelfPermission(context, android.Manifest.permission.RECORD_AUDIO)
                != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                androidx.core.app.ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(android.Manifest.permission.RECORD_AUDIO),
                    2001
                )
                Toast.makeText(context, "Please grant Microphone permission to record audio memos", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            stopActiveRecording()
            stopActivePlayback()

            try {
                val audioFile = FileUtils.createMediaFile(context, "audio_${field.id}", "m4a")
                val recorder = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
                    MediaRecorder(context)
                } else {
                    @Suppress("DEPRECATION")
                    MediaRecorder()
                }

                recorder.setAudioSource(MediaRecorder.AudioSource.MIC)
                recorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
                recorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
                recorder.setAudioEncodingBitRate(96000)
                recorder.setAudioSamplingRate(44100)
                recorder.setOutputFile(audioFile.absolutePath)
                recorder.prepare()
                recorder.start()

                activeMediaRecorder = recorder
                activeRecordingFieldId = field.id
                recordingStartTime = System.currentTimeMillis()

                binding.layoutRecordActions.visibility = View.GONE
                binding.layoutAudioPlayer.visibility = View.GONE
                binding.layoutRecordingIndicator.visibility = View.VISIBLE
                binding.tvRecordingTimer.text = "Recording Voice Memo: 00:00"

                recordingTimerRunnable = object : Runnable {
                    override fun run() {
                        val elapsed = System.currentTimeMillis() - recordingStartTime
                        binding.tvRecordingTimer.text = "Recording Voice Memo: ${formatMs(elapsed)}"
                        binding.viewPulsingDot.alpha = if (binding.viewPulsingDot.alpha == 1f) 0.3f else 1f
                        if (elapsed >= 300_000) {
                            binding.btnStopRecording.performClick()
                        } else {
                            recordingHandler.postDelayed(this, 500L)
                        }
                    }
                }
                recordingHandler.postDelayed(recordingTimerRunnable!!, 500L)

                binding.btnStopRecording.setOnClickListener {
                    try {
                        recordingTimerRunnable?.let { recordingHandler.removeCallbacks(it) }
                        recorder.stop()
                        recorder.release()
                    } catch (e: Exception) {
                        e.printStackTrace()
                    } finally {
                        activeMediaRecorder = null
                        activeRecordingFieldId = null
                    }

                    if (audioFile.exists() && audioFile.length() > 0) {
                        pendingAttachments[field.id] = AttachmentEntity(
                            submissionUuid = "",
                            fieldId = field.id,
                            fileType = "audio",
                            localPath = audioFile.absolutePath,
                            mimeType = "audio/mp4"
                        )
                        updatePlayerUi(audioFile)
                        evaluateDependencies()
                        onFormValueChanged?.invoke()
                        Toast.makeText(context, "Voice note recorded (${audioFile.length() / 1024} KB)", Toast.LENGTH_SHORT).show()
                    } else {
                        binding.layoutRecordingIndicator.visibility = View.GONE
                        binding.layoutRecordActions.visibility = View.VISIBLE
                        Toast.makeText(context, "Recording failed or empty", Toast.LENGTH_SHORT).show()
                    }
                }
            } catch (e: Exception) {
                Toast.makeText(context, "Microphone error: ${e.message}", Toast.LENGTH_SHORT).show()
                binding.layoutRecordingIndicator.visibility = View.GONE
                binding.layoutRecordActions.visibility = View.VISIBLE
            }
        }

        // 2. PLAY AUDIO
        binding.btnPlayPauseAudio.setOnClickListener {
            val att = pendingAttachments[field.id] ?: return@setOnClickListener
            val file = File(att.localPath)
            if (!file.exists()) return@setOnClickListener

            if (activePlayingFieldId == field.id && activeMediaPlayer?.isPlaying == true) {
                activeMediaPlayer?.pause()
                binding.btnPlayPauseAudio.text = "▶"
                playbackProgressRunnable?.let { playbackHandler.removeCallbacks(it) }
                return@setOnClickListener
            }

            if (activePlayingFieldId == field.id && activeMediaPlayer != null) {
                activeMediaPlayer?.start()
                binding.btnPlayPauseAudio.text = "⏸"
                startPlaybackProgressUpdates(binding, field.id)
                return@setOnClickListener
            }

            stopActivePlayback()
            try {
                val mp = MediaPlayer().apply {
                    setDataSource(file.absolutePath)
                    prepare()
                    start()
                }
                activeMediaPlayer = mp
                activePlayingFieldId = field.id
                binding.btnPlayPauseAudio.text = "⏸"

                startPlaybackProgressUpdates(binding, field.id)

                mp.setOnCompletionListener {
                    binding.btnPlayPauseAudio.text = "▶"
                    binding.sbAudioProgress.progress = 0
                    stopActivePlayback()
                }
            } catch (e: Exception) {
                Toast.makeText(context, "Cannot play audio: ${e.message}", Toast.LENGTH_SHORT).show()
            }
        }

        binding.sbAudioProgress.setOnSeekBarChangeListener(object : SeekBar.OnSeekBarChangeListener {
            override fun onProgressChanged(seekBar: SeekBar?, progress: Int, fromUser: Boolean) {
                if (fromUser && activePlayingFieldId == field.id && activeMediaPlayer != null) {
                    val duration = activeMediaPlayer!!.duration
                    val newPos = (duration * (progress / 100f)).toInt()
                    activeMediaPlayer?.seekTo(newPos)
                }
            }
            override fun onStartTrackingTouch(seekBar: SeekBar?) {}
            override fun onStopTrackingTouch(seekBar: SeekBar?) {}
        })

        // 3. DELETE / CLEAR AUDIO
        binding.btnDeleteAudio.setOnClickListener {
            stopActivePlayback()
            pendingAttachments.remove(field.id)
            binding.layoutAudioPlayer.visibility = View.GONE
            binding.layoutRecordActions.visibility = View.VISIBLE
            evaluateDependencies()
            onFormValueChanged?.invoke()
        }

        fieldGetters[field.id] = {
            pendingAttachments[field.id]?.let { att ->
                mapOf(
                    "path" to att.localPath,
                    "filename" to File(att.localPath).name,
                    "type" to "audio"
                )
            }
        }

        fieldValidators[field.id] = {
            val att = pendingAttachments[field.id]
            if (field.required && att == null) {
                "Voice memo recording is required for ${field.label}"
            } else null
        }

        return binding.root
    }

    private fun startPlaybackProgressUpdates(binding: WidgetAudioFieldBinding, fieldId: String) {
        playbackProgressRunnable?.let { playbackHandler.removeCallbacks(it) }
        playbackProgressRunnable = object : Runnable {
            override fun run() {
                val mp = activeMediaPlayer
                if (mp != null && mp.isPlaying && activePlayingFieldId == fieldId) {
                    val currentPos = mp.currentPosition
                    val duration = mp.duration.coerceAtLeast(1)
                    val percent = ((currentPos.toDouble() / duration.toDouble()) * 100).toInt()
                    binding.sbAudioProgress.progress = percent

                    val curStr = String.format(Locale.US, "%02d:%02d", currentPos / 60000, (currentPos % 60000) / 1000)
                    val durStr = String.format(Locale.US, "%02d:%02d", duration / 60000, (duration % 60000) / 1000)
                    val att = pendingAttachments[fieldId]
                    val sizeKb = if (att != null) (File(att.localPath).length() + 1023) / 1024 else 0
                    binding.tvAudioDuration.text = "$curStr / $durStr • $sizeKb KB"

                    playbackHandler.postDelayed(this, 250L)
                }
            }
        }
        playbackHandler.post(playbackProgressRunnable!!)
    }

    fun stopActiveRecording() {
        try {
            recordingTimerRunnable?.let { recordingHandler.removeCallbacks(it) }
            activeMediaRecorder?.stop()
            activeMediaRecorder?.release()
        } catch (_: Exception) {}
        activeMediaRecorder = null
        activeRecordingFieldId = null
    }

    fun stopActivePlayback() {
        playbackProgressRunnable?.let { playbackHandler.removeCallbacks(it) }
        try {
            activeMediaPlayer?.stop()
            activeMediaPlayer?.release()
        } catch (_: Exception) {}
        activeMediaPlayer = null
        activePlayingFieldId = null
    }

    fun stopAllActiveMedia() {
        stopActiveRecording()
        stopActivePlayback()
    }

    // 11d. JOURNEY GPS WIDGET (CONTINUOUS TRACKING)
    private val activeJourneyData = mutableMapOf<String, JourneyData>()
    private val journeyTrackingState = mutableMapOf<String, Boolean>() // fieldId -> isRecording
    private val journeyTimers = mutableMapOf<String, android.os.Handler>()
    private val journeyStartTimestamps = mutableMapOf<String, Long>()
    private val journeyAccumulatedSeconds = mutableMapOf<String, Long>()

    private fun renderJourneyGpsWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetJourneyGpsFieldBinding.inflate(LayoutInflater.from(context))
        journeyBindings[field.id] = binding

        binding.tvJourneyLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvJourneyHelp.text = field.help
            binding.tvJourneyHelp.visibility = View.VISIBLE
        }

        val pointsList = mutableListOf<JourneyPoint>()
        var totalDistMeters = 0.0
        val sdfIso = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", Locale.US)
        var journeyStartStr: String? = null
        var isTimelineExpanded = false

        fun updateTimerDisplay(sec: Long) {
            val hours = sec / 3600
            val minutes = (sec % 3600) / 60
            val seconds = sec % 60
            binding.tvJourneyTimer.text = String.format(Locale.US, "%02d:%02d:%02d", hours, minutes, seconds)
        }

        fun buildTimelineLogText(): String {
            if (pointsList.isEmpty()) return "No waypoints registered yet. Tap 'Start Record' to begin."
            val sb = StringBuilder()
            pointsList.forEachIndexed { idx, pt ->
                val timeStr = pt.timestamp?.substringAfter("T")?.substringBefore(".") ?: pt.timestamp ?: "--:--:--"
                if (idx == 0) {
                    sb.append("🏁 START POINT (").append(timeStr).append(")\n")
                    sb.append("   • Origin: ").append(String.format(Locale.US, "%.6f, %.6f", pt.lat, pt.lng))
                    pt.accuracy?.let { sb.append(" (±").append(String.format(Locale.US, "%.1fm", it)).append(")") }
                    pt.alt?.let { sb.append(" • Alt: ").append(String.format(Locale.US, "%.0fm", it)) }
                    sb.append("\n\n")
                } else {
                    val dir = pt.direction ?: pt.bearing?.let { JourneyGeoUtils.getDirectionLabel(it) } ?: "Forward"
                    val arrow = pt.arrow ?: pt.bearing?.let { JourneyGeoUtils.getDirectionArrow(it) } ?: "↗"
                    val deg = pt.bearing?.let { " (${Math.round(it)}°)" } ?: ""
                    val dist = pt.distanceFromPrev?.let { String.format(Locale.US, "+%.0fm", it) } ?: "+0m"
                    val speed = pt.speed?.let { " • " + String.format(Locale.US, "%.0f km/h", it * 3.6f) } ?: ""

                    sb.append(arrow).append(" Point #").append(idx + 1).append(" (").append(timeStr).append(")\n")
                    sb.append("   • Heading: ").append(dir).append(deg).append(" • ").append(dist).append(speed).append("\n")
                    sb.append("   • Coords: ").append(String.format(Locale.US, "%.6f, %.6f", pt.lat, pt.lng)).append("\n\n")
                }
            }

            if (activeJourneyData[field.id]?.status == "COMPLETED" && pointsList.size > 1) {
                val endPt = pointsList.last()
                val endTimeStr = endPt.timestamp?.substringAfter("T")?.substringBefore(".") ?: endPt.timestamp ?: "--:--:--"
                val distStr = if (totalDistMeters >= 1000.0) String.format(Locale.US, "%.2f km", totalDistMeters / 1000.0) else String.format(Locale.US, "%.0f m", totalDistMeters)
                sb.append("🛑 END POINT (").append(endTimeStr).append(")\n")
                sb.append("   • Total Journey: ").append(distStr).append(" • ").append(pointsList.size).append(" waypoints")
            }

            return sb.toString().trim()
        }

        fun updateMetrics() {
            binding.tvJourneyPointsCount.text = pointsList.size.toString()
            if (pointsList.isNotEmpty()) {
                binding.btnViewJourneyMap.visibility = View.VISIBLE
                binding.btnOpenDirectionMap.visibility = View.VISIBLE
                binding.layoutTimelineSection.visibility = View.VISIBLE
                binding.tvTimelineHeader.text = "📍 Track Points & Directions Log (${pointsList.size})"
                binding.tvTimelineContent.text = buildTimelineLogText()
            }
            if (totalDistMeters >= 1000.0) {
                binding.tvJourneyDistance.text = String.format(Locale.US, "%.2f km", totalDistMeters / 1000.0)
            } else {
                binding.tvJourneyDistance.text = String.format(Locale.US, "%.1f m", totalDistMeters)
            }
        }

        fun openInteractiveJourneyMap() {
            if (pointsList.isEmpty()) {
                Toast.makeText(context, "No journey GPS points captured yet.", Toast.LENGTH_SHORT).show()
                return
            }
            val currentJourney = activeJourneyData[field.id] ?: JourneyData(
                points = pointsList.toList(),
                startTime = journeyStartStr ?: sdfIso.format(Date()),
                endTime = sdfIso.format(Date()),
                durationSeconds = journeyAccumulatedSeconds[field.id] ?: 0L,
                distanceMeters = totalDistMeters,
                status = if (journeyTrackingState[field.id] == true) "RECORDING" else "SAVED"
            )
            val intent = Intent(context, JourneyMapActivity::class.java).apply {
                putExtra(JourneyMapActivity.EXTRA_JOURNEY_JSON, com.google.gson.Gson().toJson(currentJourney))
                putExtra(JourneyMapActivity.EXTRA_FORM_TITLE, currentSchema?.title ?: "Field Inspection")
                putExtra(JourneyMapActivity.EXTRA_FIELD_LABEL, field.label)
            }
            context.startActivity(intent)
        }

        binding.btnViewJourneyMap.setOnClickListener {
            openInteractiveJourneyMap()
        }

        binding.btnOpenDirectionMap.setOnClickListener {
            openInteractiveJourneyMap()
        }

        binding.btnToggleTimelineExpand.setOnClickListener {
            isTimelineExpanded = !isTimelineExpanded
            binding.layoutTimelineItemsContainer.visibility = if (isTimelineExpanded) View.VISIBLE else View.GONE
            binding.tvTimelineToggleLabel.text = if (isTimelineExpanded) "Hide Log ▲" else "Show Log ▼"
        }

        // Restore saved journey track if editing a saved/synced form
        if (initialValue != null) {
            try {
                if (initialValue is JourneyData) {
                    pointsList.clear()
                    pointsList.addAll(initialValue.points)
                    totalDistMeters = initialValue.distanceMeters
                    journeyStartStr = initialValue.startTime
                    val durSec = initialValue.durationSeconds
                    journeyAccumulatedSeconds[field.id] = durSec
                    activeJourneyData[field.id] = initialValue
                } else {
                    val journeyMap: Map<*, *>? = when (initialValue) {
                        is Map<*, *> -> initialValue
                        is String -> {
                            try {
                                com.google.gson.Gson().fromJson(initialValue, Map::class.java)
                            } catch (_: Exception) { null }
                        }
                        is com.google.gson.JsonObject -> {
                            try {
                                com.google.gson.Gson().fromJson(initialValue, Map::class.java)
                            } catch (_: Exception) { null }
                        }
                        else -> null
                    }

                    if (journeyMap != null) {
                        val pts = (journeyMap["journey_path"] as? List<*>)
                            ?: (journeyMap["points"] as? List<*>)
                        pointsList.clear()
                        var runningDist = 0.0

                        pts?.forEachIndexed { idx, ptRaw ->
                            if (ptRaw is Map<*, *>) {
                                val pLat = (ptRaw["lat"] as? Number)?.toDouble() ?: 0.0
                                val pLng = (ptRaw["lng"] as? Number)?.toDouble() ?: 0.0
                                val pAlt = (ptRaw["alt"] as? Number)?.toDouble()
                                val pAcc = (ptRaw["accuracy"] as? Number)?.toFloat()
                                val pTime = ptRaw["timestamp"]?.toString() ?: ""
                                var pBearing = (ptRaw["bearing"] as? Number)?.toFloat()
                                val pSpeed = (ptRaw["speed"] as? Number)?.toFloat()
                                var pDist = (ptRaw["distance_from_prev"] as? Number)?.toDouble()

                                if (idx > 0 && pointsList.isNotEmpty()) {
                                    val prev = pointsList.last()
                                    if (pDist == null) {
                                        val distRes = FloatArray(1)
                                        android.location.Location.distanceBetween(prev.lat, prev.lng, pLat, pLng, distRes)
                                        pDist = distRes[0].toDouble()
                                    }
                                    if (pBearing == null && pDist >= 1.0) {
                                        pBearing = JourneyGeoUtils.calculateBearing(prev.lat, prev.lng, pLat, pLng)
                                    }
                                }

                                val pDir = ptRaw["direction"]?.toString() ?: pBearing?.let { JourneyGeoUtils.getDirectionLabel(it) }
                                val pArrow = ptRaw["arrow"]?.toString() ?: pBearing?.let { JourneyGeoUtils.getDirectionArrow(it) }

                                runningDist += (pDist ?: 0.0)

                                pointsList.add(
                                    JourneyPoint(
                                        lat = pLat,
                                        lng = pLng,
                                        alt = pAlt,
                                        accuracy = pAcc,
                                        timestamp = pTime,
                                        bearing = pBearing,
                                        speed = pSpeed,
                                        direction = pDir,
                                        arrow = pArrow,
                                        distanceFromPrev = pDist
                                    )
                                )
                            }
                        }

                        val parsedDist = (journeyMap["distance_meters"] as? Number)?.toDouble()
                            ?: (journeyMap["distanceMeters"] as? Number)?.toDouble()
                        totalDistMeters = if (parsedDist != null && parsedDist > 0.0) parsedDist else runningDist
                        journeyStartStr = journeyMap["start_time"]?.toString() ?: journeyMap["startTime"]?.toString()
                        val durSec = (journeyMap["duration_seconds"] as? Number)?.toLong()
                            ?: (journeyMap["durationSeconds"] as? Number)?.toLong() ?: 0L
                        val endStr = journeyMap["end_time"]?.toString() ?: journeyMap["endTime"]?.toString() ?: sdfIso.format(Date())
                        val st = journeyMap["status"]?.toString() ?: "COMPLETED"

                        journeyAccumulatedSeconds[field.id] = durSec
                        if (pointsList.isNotEmpty()) {
                            activeJourneyData[field.id] = JourneyData(
                                points = pointsList.toList(),
                                startTime = journeyStartStr ?: sdfIso.format(Date()),
                                endTime = endStr,
                                durationSeconds = durSec,
                                distanceMeters = totalDistMeters,
                                status = st
                            )
                        }
                    }
                }

                if (pointsList.isNotEmpty()) {
                    updateMetrics()
                    updateTimerDisplay(journeyAccumulatedSeconds[field.id] ?: 0L)
                    val statusText = if (activeJourneyData[field.id]?.status == "COMPLETED") "Completed Track" else "Saved Track"
                    binding.tvJourneyStatus.text = "$statusText (${pointsList.size} pts logged)"
                    binding.viewStatusDot.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#2563EB"))
                    binding.btnToggleRecord.text = "Resume Track"
                    binding.btnStopJourney.visibility = View.VISIBLE
                    pointsList.lastOrNull()?.accuracy?.let {
                        binding.tvJourneyAccuracy.text = String.format(Locale.US, "±%.1fm", it)
                    }

                    val lastWithDir = pointsList.lastOrNull { it.direction != null }
                    if (lastWithDir != null) {
                        binding.layoutHeadingRow.visibility = View.VISIBLE
                        binding.tvCurrentHeadingArrow.text = lastWithDir.arrow ?: "🧭"
                        binding.tvCurrentHeadingLabel.text = "Heading: ${lastWithDir.direction}" + (lastWithDir.bearing?.let { " (${Math.round(it)}°)" } ?: "")
                        lastWithDir.speed?.let {
                            binding.tvCurrentSpeed.text = String.format(Locale.US, "%.0f km/h", it * 3.6f)
                        }
                    }
                }
            } catch (e: Exception) {
                e.printStackTrace()
            }
        }

        binding.btnToggleRecord.setOnClickListener {
            val isCurrentlyRecording = journeyTrackingState[field.id] == true

            if (!isCurrentlyRecording) {
                // START RECORDING
                if (activity is DynamicFormActivity && !gpsHelper.hasLocationPermission()) {
                    activity.requestRealGpsCapture(
                        onProgress = { _, _ -> },
                        onSuccess = { /* permission now granted */ },
                        onError = { Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }
                    )
                    return@setOnClickListener
                }

                journeyTrackingState[field.id] = true
                binding.tvJourneyStatus.text = "Recording Journey Track..."
                binding.viewStatusDot.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#16A34A"))
                binding.btnToggleRecord.text = "Pause"
                binding.btnToggleRecord.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#EAB308"))
                binding.btnStopJourney.visibility = View.VISIBLE
                binding.progressJourney.visibility = View.VISIBLE

                if (journeyStartStr == null) {
                    journeyStartStr = sdfIso.format(Date())
                    journeyStartTimestamps[field.id] = System.currentTimeMillis()
                    journeyAccumulatedSeconds[field.id] = 0L
                }

                val startTime = System.currentTimeMillis() - ((journeyAccumulatedSeconds[field.id] ?: 0L) * 1000L)
                val handler = android.os.Handler(android.os.Looper.getMainLooper())
                journeyTimers[field.id] = handler

                val timerRunnable = object : Runnable {
                    override fun run() {
                        if (journeyTrackingState[field.id] == true) {
                            val elapsedSec = (System.currentTimeMillis() - startTime) / 1000L
                            journeyAccumulatedSeconds[field.id] = elapsedSec
                            updateTimerDisplay(elapsedSec)
                            handler.postDelayed(this, 1000L)
                        }
                    }
                }
                handler.post(timerRunnable)

                // Start Foreground Service for continuous trip capture even when screen is locked
                JourneyTrackingService.startTracking(
                    context,
                    field.id,
                    currentSchema?.title ?: field.label
                )

                // Process point helper
                fun onNewPointReceived(pt: JourneyPoint, totalDist: Double) {
                    activity.runOnUiThread {
                        pointsList.add(pt)
                        totalDistMeters = totalDist
                        pt.accuracy?.let {
                            binding.tvJourneyAccuracy.text = String.format(Locale.US, "±%.1fm", it)
                        }
                        if (pt.direction != null && pt.arrow != null) {
                            binding.layoutHeadingRow.visibility = View.VISIBLE
                            binding.tvCurrentHeadingArrow.text = pt.arrow
                            val degStr = pt.bearing?.let { " (${Math.round(it)}°)" } ?: ""
                            binding.tvCurrentHeadingLabel.text = "Heading: ${pt.direction}$degStr"
                            pt.speed?.let {
                                binding.tvCurrentSpeed.text = String.format(Locale.US, "%.0f km/h", it * 3.6f)
                            }
                        }
                        updateMetrics()

                        activeJourneyData[field.id] = JourneyData(
                            points = pointsList.toList(),
                            startTime = journeyStartStr,
                            endTime = sdfIso.format(Date()),
                            durationSeconds = journeyAccumulatedSeconds[field.id] ?: 0L,
                            distanceMeters = totalDistMeters,
                            status = "RECORDING"
                        )
                        evaluateDependencies()
                    }
                }

                // Register service listener
                JourneyTrackingService.onPointRecorded = { fId, pt, totalDist ->
                    if (fId == field.id) {
                        onNewPointReceived(pt, totalDist)
                    }
                }

                // Also listen directly via in-app GpsCaptureHelper (1500ms high-precision sampling)
                gpsHelper.startContinuousTracking(intervalMs = 1500L) { coord ->
                    var distFromPrev = 0.0
                    if (pointsList.isNotEmpty()) {
                        val last = pointsList.last()
                        val results = FloatArray(1)
                        android.location.Location.distanceBetween(
                            last.lat, last.lng,
                            coord.latitude, coord.longitude,
                            results
                        )
                        distFromPrev = results[0].toDouble()

                        // Ignore stationary jitter (< 2.5m and stationary speed)
                        val isStationary = distFromPrev < 2.5 && (coord.speed == null || coord.speed < 0.5f)
                        if (isStationary) {
                            return@startContinuousTracking
                        }
                    }

                    val bearing = coord.bearing ?: if (pointsList.isNotEmpty() && distFromPrev >= 2.0) {
                        JourneyGeoUtils.calculateBearing(pointsList.last().lat, pointsList.last().lng, coord.latitude, coord.longitude)
                    } else null

                    val dirLabel = bearing?.let { JourneyGeoUtils.getDirectionLabel(it) }
                    val arrow = bearing?.let { JourneyGeoUtils.getDirectionArrow(it) }

                    val newPt = JourneyPoint(
                        lat = coord.latitude,
                        lng = coord.longitude,
                        alt = coord.altitude,
                        accuracy = coord.accuracy,
                        timestamp = sdfIso.format(Date(coord.timestamp)),
                        bearing = bearing,
                        speed = coord.speed,
                        direction = dirLabel,
                        arrow = arrow,
                        distanceFromPrev = Math.round(distFromPrev * 10.0) / 10.0
                    )

                    onNewPointReceived(newPt, totalDistMeters + distFromPrev)
                }
            } else {
                // PAUSE RECORDING
                journeyTrackingState[field.id] = false
                JourneyTrackingService.pauseTracking(context)
                gpsHelper.stopContinuousTracking()
                journeyTimers[field.id]?.removeCallbacksAndMessages(null)
                binding.tvJourneyStatus.text = "Tracking Paused"
                binding.viewStatusDot.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#CA8A04"))
                binding.btnToggleRecord.text = "Resume Record"
                binding.btnToggleRecord.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#16A34A"))
                binding.progressJourney.visibility = View.GONE

                activeJourneyData[field.id] = JourneyData(
                    points = pointsList.toList(),
                    startTime = journeyStartStr,
                    endTime = sdfIso.format(Date()),
                    durationSeconds = journeyAccumulatedSeconds[field.id] ?: 0L,
                    distanceMeters = totalDistMeters,
                    status = "PAUSED"
                )
                evaluateDependencies()
                onFormValueChanged?.invoke()
            }
        }

        binding.btnStopJourney.setOnClickListener {
            journeyTrackingState[field.id] = false
            JourneyTrackingService.stopTracking(context)
            gpsHelper.stopContinuousTracking()
            journeyTimers[field.id]?.removeCallbacksAndMessages(null)
            binding.tvJourneyStatus.text = "Journey Completed (${pointsList.size} pts)"
            binding.viewStatusDot.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#2563EB"))
            binding.btnToggleRecord.isEnabled = false
            binding.btnToggleRecord.text = "Completed"
            binding.btnToggleRecord.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#94A3B8"))
            binding.btnStopJourney.visibility = View.GONE
            binding.progressJourney.visibility = View.GONE

            activeJourneyData[field.id] = JourneyData(
                points = pointsList.toList(),
                startTime = journeyStartStr ?: sdfIso.format(Date()),
                endTime = sdfIso.format(Date()),
                durationSeconds = journeyAccumulatedSeconds[field.id] ?: 0L,
                distanceMeters = totalDistMeters,
                status = "COMPLETED"
            )
            updateMetrics()
            Toast.makeText(context, "Journey track finished: ${pointsList.size} waypoints logged.", Toast.LENGTH_SHORT).show()
            evaluateDependencies()
            onFormValueChanged?.invoke()
        }

        fieldGetters[field.id] = {
            if (pointsList.isNotEmpty()) {
                val currentTrack = activeJourneyData[field.id]
                JourneyData(
                    points = pointsList.toList(),
                    startTime = journeyStartStr ?: currentTrack?.startTime ?: sdfIso.format(Date()),
                    endTime = currentTrack?.endTime ?: sdfIso.format(Date()),
                    durationSeconds = journeyAccumulatedSeconds[field.id] ?: currentTrack?.durationSeconds ?: 0L,
                    distanceMeters = totalDistMeters,
                    status = currentTrack?.status ?: "COMPLETED"
                )
            } else {
                activeJourneyData[field.id]
            }
        }

        fieldValidators[field.id] = {
            if (field.required && pointsList.isEmpty()) "Journey recording path is required for ${field.label}" else null
        }

        return binding.root
    }
    private fun renderSignatureWidget(field: FormField, initialValue: Any?): View {
        val binding = WidgetSignatureFieldBinding.inflate(LayoutInflater.from(context))
        sigFieldBindings[field.id] = binding

        binding.tvSigLabel.text = field.label + if (field.required) " *" else ""
        if (!field.help.isNullOrBlank()) {
            binding.tvSigHelp.text = field.help
            binding.tvSigHelp.visibility = View.VISIBLE
        }

        val existingAtt = pendingAttachments[field.id]
        if (existingAtt != null && File(existingAtt.localPath).exists()) {
            binding.signaturePad.loadFromFile(File(existingAtt.localPath))
            binding.tvSigHint.visibility = View.GONE
        }

        binding.signaturePad.onSignatureChanged = {
            if (binding.signaturePad.hasSignature()) {
                binding.tvSigHint.visibility = View.GONE
                evaluateDependencies()
            }
        }

        binding.btnClearSignature.setOnClickListener {
            binding.signaturePad.clear()
            binding.tvSigHint.visibility = View.VISIBLE
            pendingAttachments.remove(field.id)
            evaluateDependencies()
        }

        fieldGetters[field.id] = {
            if (binding.signaturePad.hasSignature()) {
                val sigFile = FileUtils.createMediaFile(context, "sig_${field.id}", "png")
                if (binding.signaturePad.saveToFile(sigFile)) {
                    pendingAttachments[field.id] = AttachmentEntity(
                        submissionUuid = "",
                        fieldId = field.id,
                        fileType = "signature",
                        localPath = sigFile.absolutePath,
                        mimeType = "image/png"
                    )
                    mapOf("path" to sigFile.absolutePath, "filename" to sigFile.name, "type" to "signature")
                } else null
            } else {
                pendingAttachments[field.id]?.let {
                    mapOf("path" to it.localPath, "filename" to File(it.localPath).name, "type" to "signature")
                }
            }
        }

        fieldValidators[field.id] = {
            val hasSig = binding.signaturePad.hasSignature() || pendingAttachments.containsKey(field.id)
            if (field.required && !hasSig) "Signature is required for ${field.label}" else null
        }

        return binding.root
    }

    // Reactive Skip Logic / Dependency Evaluation
    private fun evaluateDependencies() {
        fieldDefinitions.values.forEach { field ->
            val dep = field.dependsOn
            val view = fieldViews[field.id] ?: return@forEach

            if (dep == null) {
                view.visibility = View.VISIBLE
            } else {
                val parentVal = fieldGetters[dep.fieldId]?.invoke()
                val visible = matchesDependency(dep, parentVal)
                view.visibility = if (visible) View.VISIBLE else View.GONE
            }
        }
        onFormValueChanged?.invoke()
    }

    fun getProgressStats(): FormProgressStats {
        var totalVisible = 0
        var answeredVisible = 0
        var totalRequired = 0
        var answeredRequired = 0

        fieldDefinitions.forEach { (id, field) ->
            val view = fieldViews[id]
            if (view?.visibility != View.GONE) {
                totalVisible++
                if (field.required) totalRequired++
                val answer = fieldGetters[id]?.invoke()
                val isAnswered = when (answer) {
                    null -> false
                    is String -> answer.isNotBlank()
                    is Collection<*> -> answer.isNotEmpty()
                    is Map<*, *> -> answer.isNotEmpty()
                    is Boolean -> true
                    else -> true
                }
                if (isAnswered) {
                    answeredVisible++
                    if (field.required) answeredRequired++
                }
            }
        }
        return FormProgressStats(totalVisible, answeredVisible, totalRequired, answeredRequired)
    }

    private fun matchesDependency(dep: FieldDependency, parentVal: Any?): Boolean {
        if (parentVal == null) return false
        val expectedStr = dep.value?.toString()?.trim() ?: ""
        val actualStr = parentVal.toString().trim()

        return when (dep.operator) {
            "==", "equals" -> {
                if (dep.value is Boolean) {
                    val actualBool = when (parentVal) {
                        is Boolean -> parentVal
                        is Number -> parentVal.toInt() == 1
                        is String -> parentVal.equals("true", true) || parentVal == "1"
                        else -> false
                    }
                    actualBool == dep.value
                } else {
                    actualStr.equals(expectedStr, ignoreCase = true)
                }
            }
            "!=", "not_equals" -> !actualStr.equals(expectedStr, ignoreCase = true)
            "contains" -> actualStr.contains(expectedStr, ignoreCase = true)
            "not_empty" -> actualStr.isNotBlank()
            "in" -> {
                val allowed = expectedStr.split(",").map { it.trim().lowercase() }
                allowed.contains(actualStr.lowercase())
            }
            else -> actualStr.equals(expectedStr, ignoreCase = true)
        }
    }

    // Collection & Validation (Skips hidden fields)
    fun collectAnswers(): Map<String, Any> {
        val answers = mutableMapOf<String, Any>()
        fieldGetters.forEach { (id, getter) ->
            val view = fieldViews[id]
            if (view?.visibility != View.GONE) {
                getter()?.let { value ->
                    answers[id] = value
                }
            }
        }
        return answers
    }

    fun validate(): List<String> {
        val errors = mutableListOf<String>()
        fieldValidators.forEach { (id, validator) ->
            val view = fieldViews[id]
            // Skip validation for hidden/skipped fields
            if (view?.visibility != View.GONE) {
                validator()?.let { err ->
                    errors.add(err)
                }
            }
        }
        return errors
    }

    fun getCollectedAttachments(submissionUuid: String): List<AttachmentEntity> {
        val result = mutableListOf<AttachmentEntity>()
        pendingAttachments.filter { (fieldId, _) ->
            fieldViews[fieldId]?.visibility != View.GONE
        }.values.forEach {
            result.add(it.copy(submissionUuid = submissionUuid))
        }

        multiAttachments.forEach { (fieldId, list) ->
            if (fieldViews[fieldId]?.visibility != View.GONE) {
                list.forEach { att ->
                    result.add(att.copy(submissionUuid = submissionUuid))
                }
            }
        }
        return result
    }

    fun hasActiveJourneyTracking(): Boolean {
        return journeyTrackingState.values.any { it }
    }

    fun stopAllActiveTracking() {
        journeyTrackingState.keys.forEach { fId ->
            journeyTrackingState[fId] = false
        }
        JourneyTrackingService.stopTracking(context)
        gpsHelper.stopContinuousTracking()
        journeyTimers.values.forEach { it.removeCallbacksAndMessages(null) }
        stopAllActiveMedia()
    }

    fun hasAnyJourneyPoints(): Boolean {
        return activeJourneyData.values.any { it.points.isNotEmpty() }
    }

    private fun dpToPx(dp: Float): Float {
        return dp * context.resources.displayMetrics.density
    }
}
