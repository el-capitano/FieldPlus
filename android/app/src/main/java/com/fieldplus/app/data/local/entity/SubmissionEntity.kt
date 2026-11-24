package com.fieldplus.app.data.local.entity

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "submissions",
    indices = [
        Index(value = ["submissionUuid"], unique = true),
        Index(value = ["formId"]),
        Index(value = ["status"])
    ]
)
data class SubmissionEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val submissionUuid: String,
    val formId: String,
    val formTitle: String,
    val submitterName: String?,
    val status: String, // "DRAFT", "PENDING_SYNC", "SYNCED", "ERROR"
    val answersJson: String,
    val latitude: Double? = null,
    val longitude: Double? = null,
    val accuracy: Float? = null,
    val altitude: Double? = null,
    val errorMessage: String? = null,
    val createdAt: Long = System.currentTimeMillis(),
    val updatedAt: Long = System.currentTimeMillis()
) {
    companion object {
        const val STATUS_DRAFT = "DRAFT"
        const val STATUS_PENDING_SYNC = "PENDING_SYNC"
        const val STATUS_SYNCED = "SYNCED"
        const val STATUS_ERROR = "ERROR"
    }
}
