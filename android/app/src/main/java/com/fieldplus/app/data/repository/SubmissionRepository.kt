package com.fieldplus.app.data.repository

import android.content.Context
import androidx.lifecycle.LiveData
import com.fieldplus.app.data.local.AppDatabase
import com.fieldplus.app.data.local.entity.AttachmentEntity
import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.fieldplus.app.data.remote.ApiClient
import com.fieldplus.app.util.PreferenceManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.text.SimpleDateFormat
import java.util.*

class SubmissionRepository(private val context: Context) {

    private val db = AppDatabase.getInstance(context)
    private val subDao = db.submissionDao()
    private val attDao = db.attachmentDao()
    private val apiClient = ApiClient(context)
    private val prefs = PreferenceManager(context)

    fun getAllSubmissions(): LiveData<List<SubmissionEntity>> = subDao.getAllSubmissions()

    fun getPendingCount(): LiveData<Int> = subDao.getPendingCount()

    fun getDraftsCount(): LiveData<Int> = subDao.getDraftsCount()

    suspend fun getSubmissionByUuid(uuid: String): SubmissionEntity? = withContext(Dispatchers.IO) {
        subDao.getSubmissionByUuid(uuid)
    }

    suspend fun getAttachments(uuid: String): List<AttachmentEntity> = withContext(Dispatchers.IO) {
        attDao.getAttachmentsForSubmission(uuid)
    }

    suspend fun saveSubmission(
        submission: SubmissionEntity,
        attachments: List<AttachmentEntity> = emptyList()
    ): Long = withContext(Dispatchers.IO) {
        val id = subDao.insertSubmission(submission)
        attDao.deleteAttachmentsForSubmission(submission.submissionUuid)
        attachments.forEach { attDao.insertAttachment(it) }
        id
    }

    suspend fun deleteSubmission(uuid: String) = withContext(Dispatchers.IO) {
        attDao.deleteAttachmentsForSubmission(uuid)
        subDao.deleteSubmission(uuid)
    }

    suspend fun clearSyncedSubmissions(): Int = withContext(Dispatchers.IO) {
        subDao.clearSyncedSubmissions()
    }

    suspend fun clearAllSubmissions(): Int = withContext(Dispatchers.IO) {
        subDao.clearAllSubmissions()
    }

    suspend fun clearSubmissionsForForm(formId: String): Int = withContext(Dispatchers.IO) {
        subDao.clearSubmissionsForForm(formId)
    }

    suspend fun getPendingCountDirect(): Int = withContext(Dispatchers.IO) {
        subDao.getPendingCountDirect()
    }


    suspend fun syncAllPending(
        shouldCancel: (() -> Boolean)? = null,
        onProgress: ((com.fieldplus.app.data.model.SyncProgressUpdate) -> Unit)? = null
    ): SyncResult = withContext(Dispatchers.IO) {
        val pendingList = subDao.getPendingSyncSubmissions()
        if (pendingList.isEmpty()) {
            return@withContext SyncResult(0, 0, "No pending submissions to sync.")
        }

        var successCount = 0
        var failCount = 0
        val errors = mutableListOf<String>()
        val api = apiClient.getService()
        val textPlain = "text/plain".toMediaTypeOrNull()
        val totalCount = pendingList.size

        for ((index, sub) in pendingList.withIndex()) {
            if (shouldCancel?.invoke() == true) {
                return@withContext SyncResult(successCount, failCount, "Sync paused / cancelled by user.")
            }
            val itemNum = index + 1
            try {
                val attachments = attDao.getAttachmentsForSubmission(sub.submissionUuid)
                val totalAttachmentBytes = attachments.sumOf { File(it.localPath).length() }
                var accumulatedBytesWritten = 0L

                onProgress?.invoke(
                    com.fieldplus.app.data.model.SyncProgressUpdate(
                        currentSubmissionUuid = sub.submissionUuid,
                        currentFormTitle = sub.formTitle,
                        currentItemIndex = itemNum,
                        totalItems = totalCount,
                        percent = if (totalAttachmentBytes > 0) 5 else 10,
                        statusText = "Syncing ${sub.formTitle} ($itemNum of $totalCount)...",
                        bytesUploaded = 0L,
                        totalBytes = totalAttachmentBytes
                    )
                )

                val multipartFiles = mutableListOf<MultipartBody.Part>()

                for (att in attachments) {
                    val file = File(att.localPath)
                    if (file.exists() && file.length() > 0) {
                        val mime = att.mimeType ?: when (att.fileType) {
                            "video" -> "video/mp4"
                            "image", "signature" -> "image/jpeg"
                            else -> "application/octet-stream"
                        }
                        val rawBody = file.asRequestBody(mime.toMediaTypeOrNull())
                        var fileLastReported = 0L
                        val countingBody = com.fieldplus.app.data.remote.CountingRequestBody(rawBody) { bytesWritten, _ ->
                            val delta = bytesWritten - fileLastReported
                            fileLastReported = bytesWritten
                            accumulatedBytesWritten += delta

                            val pct = if (totalAttachmentBytes > 0) {
                                ((accumulatedBytesWritten.toDouble() / totalAttachmentBytes.toDouble()) * 90.0).toInt().coerceIn(5, 95)
                            } else 50

                            val mbUploaded = String.format(Locale.US, "%.1f", accumulatedBytesWritten / 1048576.0)
                            val mbTotal = String.format(Locale.US, "%.1f", totalAttachmentBytes / 1048576.0)

                            onProgress?.invoke(
                                com.fieldplus.app.data.model.SyncProgressUpdate(
                                    currentSubmissionUuid = sub.submissionUuid,
                                    currentFormTitle = sub.formTitle,
                                    currentItemIndex = itemNum,
                                    totalItems = totalCount,
                                    percent = pct,
                                    statusText = "Uploading media ($mbUploaded / $mbTotal MB)...",
                                    bytesUploaded = accumulatedBytesWritten,
                                    totalBytes = totalAttachmentBytes
                                )
                            )
                        }

                        // Send att.fieldId directly so single fields (e.g. site_exterior_photo) match exactly,
                        // and multi-photos (e.g. site_photos_1712345678901) remain uniquely indexed for PHP.
                        val partName = att.fieldId
                        val part = MultipartBody.Part.createFormData(partName, file.name, countingBody)
                        multipartFiles.add(part)
                    }
                }

                val df = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
                val dateStr = df.format(Date(sub.createdAt))

                val subUuidPart = sub.submissionUuid.toRequestBody(textPlain)
                val formIdPart = sub.formId.toRequestBody(textPlain)
                val deviceIdPart = prefs.deviceId.toRequestBody(textPlain)
                val submitterPart = (sub.submitterName ?: prefs.surveyorName).toRequestBody(textPlain)
                val submittedAtPart = dateStr.toRequestBody(textPlain)
                val dataPart = sub.answersJson.toRequestBody(textPlain)

                val response = api.submitForm(
                    submissionUuid = subUuidPart,
                    formId = formIdPart,
                    deviceId = deviceIdPart,
                    submitterName = submitterPart,
                    submittedAt = submittedAtPart,
                    dataJson = dataPart,
                    files = multipartFiles
                )

                if (response.isSuccessful && response.body()?.status == "success") {
                    subDao.updateStatus(sub.submissionUuid, SubmissionEntity.STATUS_SYNCED, null)
                    successCount++
                    onProgress?.invoke(
                        com.fieldplus.app.data.model.SyncProgressUpdate(
                            currentSubmissionUuid = sub.submissionUuid,
                            currentFormTitle = sub.formTitle,
                            currentItemIndex = itemNum,
                            totalItems = totalCount,
                            percent = 100,
                            statusText = "Synced ${sub.formTitle} successfully!",
                            bytesUploaded = totalAttachmentBytes,
                            totalBytes = totalAttachmentBytes
                        )
                    )
                } else {
                    val code = response.code()
                    val rawError = try { response.errorBody()?.string() ?: "" } catch (_: Exception) { "" }
                    val isPayloadTooLarge = code == 413 ||
                        (code == 403 && (rawError.contains("payload", ignoreCase = true) || rawError.contains("large", ignoreCase = true) || rawError.contains("exceeded", ignoreCase = true) || totalAttachmentBytes > 25 * 1024 * 1024L))

                    val err = if (isPayloadTooLarge) {
                        val mb = String.format(Locale.US, "%.1f", totalAttachmentBytes / 1048576.0)
                        "Payload too large (${mb} MB, HTTP $code): Server rejected upload. Reduce video duration or attachment size before syncing."
                    } else {
                        "Server rejected: HTTP $code $rawError".trim()
                    }
                    subDao.updateStatus(sub.submissionUuid, SubmissionEntity.STATUS_ERROR, err)
                    errors.add(err)
                    failCount++
                }
            } catch (e: Exception) {
                val isPayloadErr = e.message?.contains("413") == true || e.message?.contains("Payload", ignoreCase = true) == true
                val err = if (isPayloadErr) {
                    "Payload too large: Server rejected upload. Reduce video duration or attachment size."
                } else {
                    "Network error: ${e.message}"
                }
                subDao.updateStatus(sub.submissionUuid, SubmissionEntity.STATUS_ERROR, err)
                errors.add(err)
                failCount++
            }
        }

        onProgress?.invoke(
            com.fieldplus.app.data.model.SyncProgressUpdate(
                currentSubmissionUuid = "",
                currentFormTitle = "",
                currentItemIndex = totalCount,
                totalItems = totalCount,
                percent = 100,
                statusText = "Sync finished ($successCount succeeded, $failCount failed)",
                isComplete = true
            )
        )

        val hasPayloadError = errors.any { it.contains("Payload too large", ignoreCase = true) }
        val finalMessage = if (hasPayloadError) {
            "Sync: $successCount succeeded, $failCount failed. Video or attachments exceeded server size limit (> 25MB)."
        } else {
            "Sync complete: $successCount succeeded, $failCount failed."
        }

        SyncResult(
            synced = successCount,
            failed = failCount,
            message = finalMessage
        )
    }

    data class SyncResult(val synced: Int, val failed: Int, val message: String)
}
