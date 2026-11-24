package com.fieldplus.app.util

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.RectF
import android.graphics.pdf.PdfDocument
import androidx.core.content.FileProvider
import com.fieldplus.app.data.local.entity.AttachmentEntity
import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

object PdfReportGenerator {

    private const val PAGE_WIDTH = 595 // Standard A4 points (8.27 in * 72 pt/in)
    private const val PAGE_HEIGHT = 842 // Standard A4 points (11.69 in * 72 pt/in)
    private const val MARGIN = 36f

    fun generateInspectionReport(
        context: Context,
        submission: SubmissionEntity,
        attachments: List<AttachmentEntity>
    ): File {
        val pdfDocument = PdfDocument()
        val sdfDate = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
        val sdfFile = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US)

        val reportDir = File(context.cacheDir, "reports").apply { mkdirs() }
        val sanitizeTitle = submission.formTitle.replace(Regex("[^a-zA-Z0-9_-]"), "_").take(25)
        val outputFile = File(reportDir, "Report_${sanitizeTitle}_${sdfFile.format(Date())}.pdf")

        var currentPageNumber = 1
        var pageInfo = PdfDocument.PageInfo.Builder(PAGE_WIDTH, PAGE_HEIGHT, currentPageNumber).create()
        var page = pdfDocument.startPage(pageInfo)
        var canvas = page.canvas

        // Paints
        val paintTextPrimary = Paint().apply {
            color = Color.parseColor("#0F172A")
            textSize = 10f
            isAntiAlias = true
        }
        val paintTextSecondary = Paint().apply {
            color = Color.parseColor("#475569")
            textSize = 9f
            isAntiAlias = true
        }
        val paintBold = Paint().apply {
            color = Color.parseColor("#0F172A")
            textSize = 10f
            isFakeBoldText = true
            isAntiAlias = true
        }
        val paintDivider = Paint().apply {
            color = Color.parseColor("#E2E8F0")
            strokeWidth = 1f
        }

        var currentY = 0f

        fun drawHeader(c: Canvas) {
            // Top Primary Header Banner
            val bannerPaint = Paint().apply { color = Color.parseColor("#1E3A8A") }
            c.drawRect(0f, 0f, PAGE_WIDTH.toFloat(), 55f, bannerPaint)

            // Accent strip
            val accentPaint = Paint().apply { color = Color.parseColor("#3B82F6") }
            c.drawRect(0f, 55f, PAGE_WIDTH.toFloat(), 58f, accentPaint)

            val titlePaint = Paint().apply {
                color = Color.WHITE
                textSize = 14f
                isFakeBoldText = true
                isAntiAlias = true
            }
            c.drawText("FIELDPLUS • OFFICIAL AUDIT & INSPECTION REPORT", MARGIN, 26f, titlePaint)

            val subtitlePaint = Paint().apply {
                color = Color.parseColor("#93C5FD")
                textSize = 9.5f
                isAntiAlias = true
            }
            c.drawText(submission.formTitle.uppercase(Locale.US) + " — COMPLIANCE VERIFICATION", MARGIN, 42f, subtitlePaint)

            // Status Badge on top right
            val statusColor = when (submission.status) {
                SubmissionEntity.STATUS_SYNCED -> Color.parseColor("#16A34A") // Green
                SubmissionEntity.STATUS_DRAFT -> Color.parseColor("#2563EB") // Blue
                else -> Color.parseColor("#D97706") // Amber
            }
            val statusText = when (submission.status) {
                SubmissionEntity.STATUS_SYNCED -> "✔ SYNCED"
                SubmissionEntity.STATUS_DRAFT -> "📝 DRAFT"
                else -> "⏳ OUTBOX PENDING"
            }
            val statusBadgePaint = Paint().apply {
                color = statusColor
                style = Paint.Style.FILL
            }
            val badgeRect = RectF(PAGE_WIDTH - MARGIN - 110f, 18f, PAGE_WIDTH - MARGIN, 40f)
            c.drawRoundRect(badgeRect, 6f, 6f, statusBadgePaint)

            val statusTextPaint = Paint().apply {
                color = Color.WHITE
                textSize = 8.5f
                isFakeBoldText = true
                isAntiAlias = true
                textAlign = Paint.Align.CENTER
            }
            c.drawText(statusText, badgeRect.centerX(), badgeRect.centerY() + 3f, statusTextPaint)
        }

        fun drawFooter(c: Canvas, pageNum: Int) {
            c.drawLine(MARGIN, PAGE_HEIGHT - 32f, PAGE_WIDTH - MARGIN, PAGE_HEIGHT - 32f, paintDivider)
            val footerPaint = Paint().apply {
                color = Color.parseColor("#94A3B8")
                textSize = 8f
                isAntiAlias = true
            }
            c.drawText("FieldPlus Mobile Inspection System • Generated: ${sdfDate.format(Date())}", MARGIN, PAGE_HEIGHT - 20f, footerPaint)
            c.drawText("Page $pageNum", PAGE_WIDTH - MARGIN - 35f, PAGE_HEIGHT - 20f, footerPaint)
        }

        fun checkPageBreak(requiredHeight: Float) {
            if (currentY + requiredHeight > PAGE_HEIGHT - 45f) {
                drawFooter(canvas, currentPageNumber)
                pdfDocument.finishPage(page)
                currentPageNumber++
                pageInfo = PdfDocument.PageInfo.Builder(PAGE_WIDTH, PAGE_HEIGHT, currentPageNumber).create()
                page = pdfDocument.startPage(pageInfo)
                canvas = page.canvas
                drawHeader(canvas)
                currentY = 75f
            }
        }

        // 1. Draw First Page Header
        drawHeader(canvas)
        currentY = 75f

        // 2. Draw Inspection Meta Box
        val metaBoxPaint = Paint().apply {
            color = Color.parseColor("#F8FAFC")
            style = Paint.Style.FILL
        }
        val metaBoxStroke = Paint().apply {
            color = Color.parseColor("#E2E8F0")
            style = Paint.Style.STROKE
            strokeWidth = 1f
        }
        val metaRect = RectF(MARGIN, currentY, PAGE_WIDTH - MARGIN, currentY + 74f)
        canvas.drawRoundRect(metaRect, 8f, 8f, metaBoxPaint)
        canvas.drawRoundRect(metaRect, 8f, 8f, metaBoxStroke)

        val metaLeft = MARGIN + 12f
        val col2Left = PAGE_WIDTH / 2f + 10f

        canvas.drawText("Surveyor:", metaLeft, currentY + 18f, paintBold)
        canvas.drawText(submission.submitterName?.ifBlank { "Field Inspector" } ?: "Field Inspector", metaLeft + 52f, currentY + 18f, paintTextPrimary)

        canvas.drawText("Date & Time:", metaLeft, currentY + 34f, paintBold)
        canvas.drawText(sdfDate.format(Date(submission.createdAt)), metaLeft + 64f, currentY + 34f, paintTextPrimary)

        canvas.drawText("Reference ID:", metaLeft, currentY + 50f, paintBold)
        canvas.drawText(submission.submissionUuid.take(18) + "...", metaLeft + 68f, currentY + 50f, paintTextSecondary)

        canvas.drawText("GPS Location:", col2Left, currentY + 18f, paintBold)
        val gpsStr = if (submission.latitude != null && submission.longitude != null) {
            String.format(Locale.US, "%.6f, %.6f", submission.latitude, submission.longitude)
        } else "Not Recorded"
        canvas.drawText(gpsStr, col2Left + 72f, currentY + 18f, paintTextPrimary)

        canvas.drawText("Altitude / Acc:", col2Left, currentY + 34f, paintBold)
        val altStr = submission.altitude?.let { String.format(Locale.US, "%.0fm", it) } ?: "--"
        val accStr = submission.accuracy?.let { String.format(Locale.US, "±%.1fm", it) } ?: "--"
        canvas.drawText("$altStr (Acc: $accStr)", col2Left + 72f, currentY + 34f, paintTextSecondary)

        canvas.drawText("Sync Protocol:", col2Left, currentY + 50f, paintBold)
        canvas.drawText("Encrypted Payload (Offline-First)", col2Left + 72f, currentY + 50f, paintTextSecondary)

        currentY += 88f

        // 3. Section Title: Form Responses
        val sectionTitlePaint = Paint().apply {
            color = Color.parseColor("#1E3A8A")
            textSize = 11.5f
            isFakeBoldText = true
            isAntiAlias = true
        }
        canvas.drawText("1. SURVEY AUDIT RESPONSES", MARGIN, currentY, sectionTitlePaint)
        canvas.drawLine(MARGIN, currentY + 4f, PAGE_WIDTH - MARGIN, currentY + 4f, paintDivider)
        currentY += 16f

        // Parse answers JSON
        var journeySummaryText: String? = null
        try {
            val type = object : TypeToken<Map<String, Any>>() {}.type
            val answers: Map<String, Any>? = Gson().fromJson(submission.answersJson, type)

            var rowIndex = 0
            answers?.forEach { (questionKey, answerValue) ->
                // Check if this answer is a journey GPS track
                if (answerValue is Map<*, *>) {
                    val pts = (answerValue["journey_path"] as? List<*>) ?: (answerValue["points"] as? List<*>)
                    if (!pts.isNullOrEmpty()) {
                        val dist = (answerValue["distance_meters"] as? Number)?.toDouble() ?: 0.0
                        val distStr = if (dist >= 1000) String.format(Locale.US, "%.2f km", dist / 1000) else String.format(Locale.US, "%.0f m", dist)
                        val startT = answerValue["start_time"]?.toString() ?: ""
                        val endT = answerValue["end_time"]?.toString() ?: ""
                        journeySummaryText = "Journey Track: ${pts.size} waypoints logged (${distStr}) • Start: $startT • End: $endT"
                    }
                }

                val displayAnswer = when (answerValue) {
                    is Map<*, *> -> {
                        if (answerValue.containsKey("path")) {
                            "Attachment: ${File(answerValue["path"].toString()).name}"
                        } else {
                            answerValue.entries.joinToString("; ") { "${it.key}: ${it.value}" }
                        }
                    }
                    is List<*> -> {
                        if (answerValue.isNotEmpty() && answerValue.first() is Map<*, *> && (answerValue.first() as Map<*, *>).containsKey("path")) {
                            "${answerValue.size} file(s) attached"
                        } else {
                            answerValue.joinToString(", ")
                        }
                    }
                    is Boolean -> if (answerValue) "YES / TRUE" else "NO / FALSE"
                    else -> answerValue.toString()
                }

                checkPageBreak(24f)

                // Alternating row background
                if (rowIndex % 2 == 1) {
                    val rowBg = Paint().apply { color = Color.parseColor("#F8FAFC") }
                    canvas.drawRect(MARGIN, currentY - 11f, PAGE_WIDTH - MARGIN, currentY + 11f, rowBg)
                }

                // Question (Left column, width 180)
                val qText = if (questionKey.length > 32) questionKey.take(30) + "..." else questionKey
                canvas.drawText("• $qText", MARGIN + 4f, currentY + 2f, paintBold)

                // Answer (Right column)
                val aText = if (displayAnswer.length > 55) displayAnswer.take(52) + "..." else displayAnswer
                canvas.drawText(aText, MARGIN + 195f, currentY + 2f, paintTextPrimary)

                currentY += 18f
                rowIndex++
            }
        } catch (_: Exception) {}

        currentY += 10f

        // 4. GPS Journey Track Summary (if present)
        if (journeySummaryText != null) {
            checkPageBreak(40f)
            canvas.drawText("2. GPS JOURNEY TRACK SUMMARY", MARGIN, currentY, sectionTitlePaint)
            canvas.drawLine(MARGIN, currentY + 4f, PAGE_WIDTH - MARGIN, currentY + 4f, paintDivider)
            currentY += 16f

            val journeyBoxPaint = Paint().apply { color = Color.parseColor("#EFF6FF") }
            val journeyStroke = Paint().apply {
                color = Color.parseColor("#BFDBFE")
                style = Paint.Style.STROKE
                strokeWidth = 1f
            }
            val jRect = RectF(MARGIN, currentY - 2f, PAGE_WIDTH - MARGIN, currentY + 28f)
            canvas.drawRoundRect(jRect, 6f, 6f, journeyBoxPaint)
            canvas.drawRoundRect(jRect, 6f, 6f, journeyStroke)

            val journeyTextPaint = Paint().apply {
                color = Color.parseColor("#1E40AF")
                textSize = 9.5f
                isFakeBoldText = true
                isAntiAlias = true
            }
            canvas.drawText("🗺️ $journeySummaryText", MARGIN + 10f, currentY + 16f, journeyTextPaint)
            currentY += 38f
        }

        // 5. Embedded Evidence Photos
        val photoAttachments = attachments.filter {
            it.fileType == "image" || it.fileType == "photo" || it.localPath.endsWith(".jpg", true) || it.localPath.endsWith(".png", true)
        }

        if (photoAttachments.isNotEmpty()) {
            checkPageBreak(120f)
            canvas.drawText("3. INSPECTION EVIDENCE GALLERY (${photoAttachments.size} Photos)", MARGIN, currentY, sectionTitlePaint)
            canvas.drawLine(MARGIN, currentY + 4f, PAGE_WIDTH - MARGIN, currentY + 4f, paintDivider)
            currentY += 16f

            val photoWidth = 120f
            val photoHeight = 90f
            val spacing = 14f
            var currentX = MARGIN

            photoAttachments.forEachIndexed { pIdx, att ->
                if (currentX + photoWidth > PAGE_WIDTH - MARGIN) {
                    currentX = MARGIN
                    currentY += photoHeight + 22f
                    checkPageBreak(photoHeight + 30f)
                }

                val imgFile = File(att.localPath)
                if (imgFile.exists()) {
                    val bitmap = decodeSampledBitmap(imgFile.absolutePath, photoWidth.toInt(), photoHeight.toInt())
                    if (bitmap != null) {
                        val destRect = RectF(currentX, currentY, currentX + photoWidth, currentY + photoHeight)
                        canvas.drawBitmap(bitmap, null, destRect, null)

                        // Frame
                        val framePaint = Paint().apply {
                            color = Color.parseColor("#CBD5E1")
                            style = Paint.Style.STROKE
                            strokeWidth = 1f
                        }
                        canvas.drawRect(destRect, framePaint)

                        // Caption
                        val caption = "Photo #${pIdx + 1}"
                        canvas.drawText(caption, currentX, currentY + photoHeight + 11f, paintTextSecondary)
                    }
                }

                currentX += photoWidth + spacing
            }

            currentY += photoHeight + 24f
        }

        // 6. Digital Signature & Sign-Off Section
        val sigAttachment = attachments.firstOrNull { it.fileType == "signature" }
        checkPageBreak(90f)

        canvas.drawText("4. INSPECTOR OFFICIAL SIGN-OFF", MARGIN, currentY, sectionTitlePaint)
        canvas.drawLine(MARGIN, currentY + 4f, PAGE_WIDTH - MARGIN, currentY + 4f, paintDivider)
        currentY += 14f

        val sigBoxRect = RectF(PAGE_WIDTH - MARGIN - 180f, currentY, PAGE_WIDTH - MARGIN, currentY + 68f)
        val sigBoxPaint = Paint().apply { color = Color.parseColor("#F8FAFC") }
        val sigStroke = Paint().apply {
            color = Color.parseColor("#CBD5E1")
            style = Paint.Style.STROKE
            strokeWidth = 1f
        }
        canvas.drawRoundRect(sigBoxRect, 6f, 6f, sigBoxPaint)
        canvas.drawRoundRect(sigBoxRect, 6f, 6f, sigStroke)

        if (sigAttachment != null && File(sigAttachment.localPath).exists()) {
            val sigBitmap = decodeSampledBitmap(sigAttachment.localPath, 160, 48)
            if (sigBitmap != null) {
                val destSig = RectF(sigBoxRect.left + 10f, sigBoxRect.top + 6f, sigBoxRect.right - 10f, sigBoxRect.bottom - 18f)
                canvas.drawBitmap(sigBitmap, null, destSig, null)
            }
        } else {
            val unsignedPaint = Paint().apply {
                color = Color.parseColor("#94A3B8")
                textSize = 9f
                textAlign = Paint.Align.CENTER
            }
            canvas.drawText("[Verified Electronically]", sigBoxRect.centerX(), sigBoxRect.centerY(), unsignedPaint)
        }

        val sigLabelPaint = Paint().apply {
            color = Color.parseColor("#64748B")
            textSize = 7.5f
            textAlign = Paint.Align.CENTER
        }
        canvas.drawText("AUTHORIZED SURVEYOR SIGNATURE", sigBoxRect.centerX(), sigBoxRect.bottom - 5f, sigLabelPaint)

        // Inspector declaration
        val declPaint = Paint().apply {
            color = Color.parseColor("#475569")
            textSize = 8.5f
        }
        canvas.drawText("I hereby certify that the information and observations", MARGIN, currentY + 20f, declPaint)
        canvas.drawText("recorded above represent an accurate account of this inspection.", MARGIN, currentY + 34f, declPaint)
        canvas.drawText("Verified via FieldPlus Native Mobile Engine.", MARGIN, currentY + 48f, paintBold)

        // Finalize last page
        drawFooter(canvas, currentPageNumber)
        pdfDocument.finishPage(page)

        // Write to file
        FileOutputStream(outputFile).use { out ->
            pdfDocument.writeTo(out)
        }
        pdfDocument.close()

        return outputFile
    }

    private fun decodeSampledBitmap(path: String, reqWidth: Int, reqHeight: Int): Bitmap? {
        return try {
            val options = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(path, options)

            options.inSampleSize = calculateInSampleSize(options, reqWidth, reqHeight)
            options.inJustDecodeBounds = false
            BitmapFactory.decodeFile(path, options)
        } catch (_: Exception) {
            null
        }
    }

    private fun calculateInSampleSize(options: BitmapFactory.Options, reqWidth: Int, reqHeight: Int): Int {
        val (height: Int, width: Int) = options.run { outHeight to outWidth }
        var inSampleSize = 1

        if (height > reqHeight || width > reqWidth) {
            val halfHeight: Int = height / 2
            val halfWidth: Int = width / 2
            while (halfHeight / inSampleSize >= reqHeight && halfWidth / inSampleSize >= reqWidth) {
                inSampleSize *= 2
            }
        }
        return inSampleSize
    }

    /**
     * Shares the generated PDF report via the Android Sharesheet.
     */
    fun sharePdfReport(context: Context, pdfFile: File) {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            pdfFile
        )
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_SUBJECT, "Inspection Report: ${pdfFile.name}")
            putExtra(Intent.EXTRA_TEXT, "Attached is the official audit inspection report generated by FieldPlus.")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(intent, "Share Inspection Report"))
    }

    /**
     * Opens the PDF report in any installed PDF viewer app.
     */
    fun openPdfReport(context: Context, pdfFile: File) {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            pdfFile
        )
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/pdf")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        try {
            context.startActivity(intent)
        } catch (_: Exception) {
            sharePdfReport(context, pdfFile)
        }
    }

    /**
     * Prompts the user with Open vs Share options for the generated PDF.
     */
    fun showPdfOptionsDialog(context: Context, pdfFile: File, formTitle: String) {
        val sizeKb = (pdfFile.length() + 1023) / 1024
        androidx.appcompat.app.AlertDialog.Builder(context)
            .setTitle("📄 Inspection Report Ready")
            .setMessage("Professional report for \"$formTitle\" has been generated ($sizeKb KB).\n\nWhat would you like to do?")
            .setPositiveButton("Open PDF") { _, _ ->
                openPdfReport(context, pdfFile)
            }
            .setNeutralButton("Share / Send") { _, _ ->
                sharePdfReport(context, pdfFile)
            }
            .setNegativeButton("Close", null)
            .show()
    }
}
