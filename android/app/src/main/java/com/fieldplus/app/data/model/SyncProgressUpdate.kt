package com.fieldplus.app.data.model

data class SyncProgressUpdate(
    val currentSubmissionUuid: String,
    val currentFormTitle: String,
    val currentItemIndex: Int,
    val totalItems: Int,
    val percent: Int,
    val statusText: String,
    val bytesUploaded: Long = 0L,
    val totalBytes: Long = 0L,
    val isComplete: Boolean = false
)
